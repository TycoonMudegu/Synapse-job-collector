<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Services\Security\UrlGuard;

/** One isolated Chromium session for a public-source check or collection run. */
final class FuzuBrowserClient extends HttpClient
{
    private mixed $process = null;
    private array $pipes = [];

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 45,
        int $maxBytes = 4194304,
    ): array {
        $parts = parse_url($url);
        if (
            $method !== "GET" ||
            $headers !== [] ||
            $body !== null ||
            ($parts["scheme"] ?? "") !== "https" ||
            ($parts["host"] ?? "") !== "www.fuzu.com" ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            isset($parts["query"]) ||
            isset($parts["fragment"]) ||
            ($parts["port"] ?? 443) !== 443 ||
            !preg_match(
                '#^/(?:robots\.txt|kenya/job(?:/computers-software-development)?|kenya/jobs/[a-z0-9-]+)$#',
                $parts["path"] ?? "",
            )
        ) {
            throw new HttpException(
                "The browser collector only opens built-in public Fuzu pages.",
            );
        }
        $this->start();
        $request =
            json_encode(
                [
                    "url" => $url,
                    "timeout" => max(1, min(45, $timeout)),
                    "maxBytes" => $maxBytes,
                ],
                JSON_THROW_ON_ERROR,
            ) . "\n";
        if (fwrite($this->pipes[0], $request) !== strlen($request)) {
            $this->close();
            throw new HttpException(
                "The Fuzu browser connection closed. Check the browser installation.",
            );
        }
        stream_set_timeout($this->pipes[1], max(1, min(45, $timeout)) + 20);
        // JSON escaping can expand a byte to six characters. Never read unbounded process output.
        $line = fgets($this->pipes[1], $maxBytes * 6 + 4096);
        if ($line === false || !str_ends_with($line, "\n")) {
            $this->close();
            throw new HttpException(
                "The Fuzu browser stopped or timed out. Install Chromium and its system dependencies.",
            );
        }
        try {
            $response = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->close();
            throw new HttpException(
                "The Fuzu browser returned an invalid response.",
            );
        }
        if (!empty($response["error"])) {
            $this->close();
            throw new HttpException(
                mb_substr((string) $response["error"], 0, 500),
            );
        }
        if (
            !is_int($response["status"] ?? null) ||
            !is_string($response["body"] ?? null) ||
            strlen($response["body"]) > $maxBytes
        ) {
            $this->close();
            throw new HttpException(
                "The Fuzu browser response is invalid or exceeds the page size limit.",
            );
        }
        return [
            "status" => $response["status"],
            "headers" => [],
            "body" => $response["body"],
        ];
    }

    private function start(): void
    {
        if (is_resource($this->process)) {
            return;
        }
        if (!function_exists("proc_open")) {
            throw new HttpException(
                "Fuzu browser collection requires PHP proc_open and Node.js.",
            );
        }
        $hosts = [];
        foreach (["www.fuzu.com", "public-s3.fuzu.com"] as $host) {
            $hosts[$host] = UrlGuard::validate("https://" . $host)["ip"];
        }
        $this->process = proc_open(
            [
                Env::get("NODE_BINARY", "node"),
                BASE_PATH . "/scripts/fuzu-browser.mjs",
            ],
            [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["file", "/dev/null", "a"],
            ],
            $this->pipes,
            BASE_PATH,
        );
        if (!is_resource($this->process)) {
            throw new HttpException(
                "Could not start the Fuzu browser. Check NODE_BINARY and install Playwright Chromium.",
            );
        }
        fwrite(
            $this->pipes[0],
            json_encode(["hosts" => $hosts], JSON_THROW_ON_ERROR) . "\n",
        );
    }

    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        // EOF lets Node close Chromium; stop a stuck child after a short grace period.
        $deadline = microtime(true) + 2;
        while (
            proc_get_status($this->process)["running"] &&
            microtime(true) < $deadline
        ) {
            usleep(10000);
        }
        if (proc_get_status($this->process)["running"]) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
