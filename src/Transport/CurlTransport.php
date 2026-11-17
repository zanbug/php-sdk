<?php

namespace Zanbug\Sdk\Transport;

use Zanbug\Sdk\Support\CircuitBreaker;
use Zanbug\Sdk\Support\Compat;

class CurlTransport implements Transport
{
    /** @var int */
    private $timeout;
    /** @var CircuitBreaker|null */
    private $breaker;

    public function __construct($timeout = 3)
    {
        $this->timeout = (int) $timeout;
    }

    private function breaker($url)
    {
        if ($this->breaker === null) {
            $this->breaker = new CircuitBreaker($url);
        }
        return $this->breaker;
    }

    /** An unreachable host should cost ~1s to detect, not the full timeout. */
    private function connectTimeout()
    {
        return 1;
    }

    /** Transport failure or server 5xx counts against the breaker; 4xx does not. */
    private function record($ch, $result)
    {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false || $code === 0 || $code >= 500) {
            $this->breaker->failure();
        } else {
            $this->breaker->success();
        }
    }

    public function send($url, $apiKey, array $payload)
    {
        // Fire-and-forget: the client app must never break or hang because of Zanbug.
        try {
            $body = json_encode($payload, Compat::jsonFlags());
            if ($body === false) {
                return;
            }

            if (!$this->breaker($url)->allow()) {
                return; // Zanbug is down — skip instantly, never hold a worker
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout(),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Zanbug-Key: ' . $apiKey,
                    'User-Agent: zanbug-php-sdk/1.0',
                ),
            ));
            $result = curl_exec($ch);
            $this->record($ch, $result);
            Compat::closeCurl($ch);
        } catch (\Exception $e) {
            // Swallow — telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // PHP 7+ engine errors — also non-fatal.
        }
    }

    public function fetch($url, $apiKey)
    {
        try {
            if (!$this->breaker($url)->allow()) {
                return null;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout(),
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'X-Zanbug-Key: ' . $apiKey,
                    'User-Agent: zanbug-php-sdk/1.0',
                ),
            ));
            $body = curl_exec($ch);
            $this->record($ch, $body);
            Compat::closeCurl($ch);
            if (!is_string($body) || $body === '') {
                return null;
            }
            $data = json_decode($body, true);

            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
