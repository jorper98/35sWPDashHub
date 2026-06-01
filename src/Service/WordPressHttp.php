<?php

declare(strict_types=1);

namespace S35WpHub\Service;

final class WordPressHttp
{
    public function __construct(
        private readonly int $timeoutSeconds = 45,
        private readonly ?string $caBundlePath = null,
        private readonly bool $verifySsl = true
    ) {
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{ok: bool, status: int, body: string, decoded: mixed|null, error: string|null, headers: array<string, string>}
     */
    public function request(
        string $method,
        string $url,
        string $username,
        string $password,
        ?array $jsonBody = null,
        bool $expectJson = true
    ): array {
        if (! function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'decoded' => null,
                'error' => 'PHP curl extension is required.',
                'headers' => [],
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'decoded' => null,
                'error' => 'curl_init failed.',
                'headers' => [],
            ];
        }

        $headers = ['Accept: application/json'];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if ($this->verifySsl && $this->caBundlePath !== null && $this->caBundlePath !== '' && is_file($this->caBundlePath)) {
            $opts[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        $responseHeaders = [];
        $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $header) use (&$responseHeaders): int {
            $len = strlen($header);
            $h = trim($header);
            if ($h === '' || preg_match('#^HTTP/[0-9]#i', $h) === 1) {
                return $len;
            }
            $pos = strpos($h, ':');
            if ($pos === false) {
                return $len;
            }
            $name = strtolower(trim(substr($h, 0, $pos)));
            $value = trim(substr($h, $pos + 1));
            $responseHeaders[$name] = $value;

            return $len;
        };

        curl_setopt_array($ch, $opts);

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'decoded' => null,
                'error' => $err !== '' ? $err : 'Request failed.',
                'headers' => $responseHeaders,
            ];
        }

        $decoded = null;
        if ($expectJson && $body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'decoded' => $decoded,
            'error' => $ok ? null : ($err !== '' ? $err : 'HTTP ' . $status),
            'headers' => $responseHeaders,
        ];
    }
}
