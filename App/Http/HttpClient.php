<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\HttpException;
use App\Services\Security\UrlGuard;

class HttpClient
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 45,
        int $maxBytes = 4194304,
    ): array {
        $resolved = UrlGuard::validate($url);
        $curl = curl_init($url);
        $responseBody = "";
        $responseHeaders = [];
        $tooLarge = false;
        $ip = str_contains($resolved["ip"], ":")
            ? "[" . $resolved["ip"] . "]"
            : $resolved["ip"];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => "",
            CURLOPT_RESOLVE => [$resolved["host"] . ":443:" . $ip],
            CURLOPT_USERAGENT => "Synapse/1.0 (public permitted job access)",
            CURLOPT_WRITEFUNCTION => static function (
                $handle,
                string $chunk,
            ) use (&$responseBody, &$tooLarge, $maxBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (
                $handle,
                string $line,
            ) use (&$responseHeaders): int {
                if (str_contains($line, ":")) {
                    [$name, $value] = explode(":", $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($tooLarge) {
            throw new HttpException(
                "The remote response exceeds the allowed size.",
            );
        }
        if ($result === false) {
            throw new HttpException(
                "The external request failed or timed out. Check the connection before retrying.",
                502,
            );
        }
        return [
            "status" => $status,
            "headers" => $responseHeaders,
            "body" => $responseBody,
        ];
    }

    public function json(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = 45,
    ): array {
        $result = $this->request(
            $method,
            $url,
            [
                "Accept: application/json",
                "Content-Type: application/json",
                ...$headers,
            ],
            $method === "GET" ? null : json_encode($data, JSON_THROW_ON_ERROR),
            $timeout,
        );
        if ($result["status"] < 200 || $result["status"] >= 300) {
            throw new HttpException(
                "The external service returned HTTP " .
                    $result["status"] .
                    ". Check its settings and permissions.",
                502,
            );
        }
        try {
            $json = json_decode($result["body"], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(
                "The external service returned invalid JSON.",
                502,
            );
        }
        if (!is_array($json)) {
            throw new HttpException(
                "The external service returned an unexpected response.",
                502,
            );
        }
        return $json;
    }
}
