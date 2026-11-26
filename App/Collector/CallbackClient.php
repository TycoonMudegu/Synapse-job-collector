<?php

declare(strict_types=1);

namespace App\Collector;

use App\Core\HttpException;
use App\Http\HttpClient;
use App\Services\Security\UrlGuard;

class CallbackClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly HttpClient $http = new HttpClient(),
    ) {
        $parts = parse_url($baseUrl);
        if (
            strlen($token) < 32 ||
            preg_match("/\s/", $token) ||
            isset($parts["query"]) ||
            isset($parts["fragment"])
        ) {
            throw new HttpException(
                "Configure a public HTTPS SYNAPSE_URL and a COLLECTOR_TOKEN of at least 32 characters.",
            );
        }
        UrlGuard::validate($baseUrl);
    }

    public function send(string $action, array $payload): array
    {
        if (!in_array($action, ["claim", "batch", "finish"], true)) {
            throw new \InvalidArgumentException("Unsupported callback action.");
        }
        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        if (strlen($body) > 2097152) {
            throw new HttpException("Callback batch exceeds two MiB.");
        }
        // Only callbacks are retried, using stable identities. Job-site/API reads are never retried here.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->http->request(
                    "POST",
                    rtrim($this->baseUrl, "/") . "/api/collector/" . $action,
                    [
                        "Authorization: Bearer " . $this->token,
                        "Content-Type: application/json",
                        "Accept: application/json",
                    ],
                    $body,
                    25,
                    262144,
                );
            } catch (HttpException) {
                if ($attempt < 2) {
                    usleep(250000 * ($attempt + 1));
                    continue;
                }
                throw new HttpException(
                    "Synapse callback could not be confirmed. Results can be reconciled in Collection runs.",
                );
            }
            if (
                in_array($response["status"], [502, 503, 504], true) &&
                $attempt < 2
            ) {
                usleep(250000 * ($attempt + 1));
                continue;
            }
            if (
                $action === "claim" &&
                in_array($response["status"], [409, 429], true)
            ) {
                return ["skip" => true];
            }
            if ($response["status"] !== 200) {
                throw new HttpException(
                    "Synapse rejected the " .
                        $action .
                        " callback (HTTP " .
                        $response["status"] .
                        ").",
                );
            }
            try {
                $data = json_decode(
                    $response["body"],
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                throw new HttpException(
                    "Synapse returned an invalid callback response.",
                );
            }
            if (!is_array($data) || ($data["ok"] ?? false) !== true) {
                throw new HttpException(
                    "Synapse did not confirm the callback.",
                );
            }
            return $data;
        }
        throw new HttpException("Synapse callback failed.");
    }
}
