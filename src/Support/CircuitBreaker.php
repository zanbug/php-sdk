<?php

namespace Zanbug\Sdk\Support;

/**
 * Cross-process circuit breaker for the Zanbug transport.
 *
 * When Zanbug is unreachable (server down, hosting suspended, packets dropped)
 * every telemetry send would otherwise burn its full connect/read timeout —
 * holding PHP-FPM workers and slowing the host app. After a failed send the
 * breaker opens: all sends are skipped instantly (zero network cost) until a
 * cool-down passes, then ONE probe is let through. Repeated failures back the
 * cool-down off exponentially (30s, 60s, 120s ... capped at 10 min); a success
 * closes the breaker.
 *
 * State lives in a small file in the system temp dir (shared by all workers of
 * the host). Every storage error fails OPEN (send normally) — the breaker must
 * never be the reason telemetry or the app breaks.
 */
class CircuitBreaker
{
    const BASE_COOLDOWN = 30;
    const MAX_COOLDOWN = 600;
    /** Seconds a probe request holds the breaker shut for everyone else. */
    const PROBE_LEASE = 5;

    /** @var string */
    private $file;
    /** @var int|null Injectable clock for tests. */
    private $now;

    /**
     * @param string      $key Host/URL the breaker guards.
     * @param string|null $dir Override the state directory (tests).
     */
    public function __construct($key, $dir = null)
    {
        $host = parse_url((string) $key, PHP_URL_HOST);
        $id = md5($host ? $host : (string) $key);
        $dir = $dir !== null ? $dir : sys_get_temp_dir();
        $this->file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'zanbug-breaker-' . $id . '.json';
    }

    public function setNow($ts)
    {
        $this->now = $ts;
    }

    private function now()
    {
        return $this->now !== null ? $this->now : time();
    }

    /**
     * @return bool true when a send may go out now.
     */
    public function allow()
    {
        try {
            $s = $this->read();
            if ($s['until'] <= 0) {
                return true; // closed
            }
            $now = $this->now();
            if ($now < $s['until']) {
                return false; // open
            }
            // Cool-down over: lease a single probe, keep others out meanwhile.
            $s['until'] = $now + self::PROBE_LEASE;
            $this->write($s);
            return true;
        } catch (\Exception $e) {
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function success()
    {
        try {
            $s = $this->read();
            if ($s['fails'] > 0 || $s['until'] > 0) {
                $this->write(array('fails' => 0, 'until' => 0));
            }
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public function failure()
    {
        try {
            $s = $this->read();
            $fails = $s['fails'] + 1;
            $cool = min(self::MAX_COOLDOWN, self::BASE_COOLDOWN * pow(2, min($fails - 1, 10)));
            $this->write(array('fails' => $fails, 'until' => $this->now() + (int) $cool));
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    private function read()
    {
        $empty = array('fails' => 0, 'until' => 0);
        $raw = @file_get_contents($this->file);
        if (!is_string($raw) || $raw === '') {
            return $empty;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return $empty;
        }
        return array(
            'fails' => isset($d['fails']) ? (int) $d['fails'] : 0,
            'until' => isset($d['until']) ? (int) $d['until'] : 0,
        );
    }

    private function write(array $s)
    {
        @file_put_contents($this->file, json_encode($s), LOCK_EX);
    }
}
