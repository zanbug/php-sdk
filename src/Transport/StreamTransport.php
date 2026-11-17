<?php

namespace Zanbug\Sdk\Transport;

use Zanbug\Sdk\Support\CircuitBreaker;
use Zanbug\Sdk\Support\Compat;

/**
 * Fallback transport using PHP streams (file_get_contents) — for environments
 * WITHOUT the cURL extension (common on stripped-down / legacy PHP installs).
 * Requires allow_url_fopen=On.
 */
class StreamTransport implements Transport
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

    /**
     * @param mixed      $body result of file_get_contents
     * @param array|null $hdrs the $http_response_header of that call
     */
    private function record($body, $hdrs)
    {
        $code = 0;
        if (is_array($hdrs) && isset($hdrs[0]) && preg_match('#\s(\d{3})\b#', $hdrs[0], $m)) {
            $code = (int) $m[1];
        }
        if ($body === false || $code === 0 || $code >= 500) {
            $this->breaker->failure();
        } else {
            $this->breaker->success();
        }
    }

    public function send($url, $apiKey, array $payload)
    {
        try {
            $body = json_encode($payload, Compat::jsonFlags());
            if ($body === false) {
                return;
            }

            if (!$this->breaker($url)->allow()) {
                return; // Zanbug is down — skip instantly
            }

            $headers = "Content-Type: application/json\r\n"
                . "Accept: application/json\r\n"
                . "X-Zanbug-Key: " . $apiKey . "\r\n"
                . "User-Agent: zanbug-php-sdk/1.0\r\n";

            $context = stream_context_create(array(
                'http' => array(
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $body,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ),
            ));

            $http_response_header = null;
            $res = @file_get_contents($url, false, $context);
            $this->record($res, $http_response_header);
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public static function isAvailable()
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    public function fetch($url, $apiKey)
    {
        try {
            if (!$this->breaker($url)->allow()) {
                return null;
            }

            $context = stream_context_create(array('http' => array(
                'method'        => 'GET',
                'header'        => "Accept: application/json\r\n"
                                 . 'X-Zanbug-Key: ' . $apiKey . "\r\n"
                                 . "User-Agent: zanbug-php-sdk/1.0\r\n",
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            )));
            $http_response_header = null;
            $body = @file_get_contents($url, false, $context);
            $this->record($body, $http_response_header);
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
