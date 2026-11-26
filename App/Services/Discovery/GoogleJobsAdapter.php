<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Http\HttpClient;
use App\Services\Security\UrlGuard;

/** Third-party Google Jobs results; never fetches Google or application pages. */
final class GoogleJobsAdapter implements SourceAdapter
{
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $sourceKey = "google_jobs",
    ) {
        if (!in_array($sourceKey, ["google_jobs", "indeed"], true)) {
            throw new HttpException("Unsupported Google Jobs result source.");
        }
    }

    public function identifier(): string
    {
        return $this->sourceKey . "_api";
    }

    public function validate(array $source): void
    {
        if ($this->search($source, 1, 1) === []) {
            throw new HttpException(
                SourceCatalog::get($this->sourceKey)["name"] .
                    " returned no complete vacancies. Try another query or location.",
            );
        }
    }

    public function collect(array $source, int $limit): array
    {
        return $this->search($source, $limit, 2);
    }

    private function search(array $source, int $limit, int $maxRequests): array
    {
        if ($source["url"] !== SourceCatalog::get($this->sourceKey)["url"]) {
            throw new HttpException(
                "Use the built-in SerpApi endpoint for Google Jobs.",
            );
        }
        $settings = array_replace(
            ["query" => "IT jobs in Kenya", "location" => "Kenya"],
            $source["config"] ?? [],
        );
        $key = getenv("COLLECTOR_SERPAPI_KEY") ?: "";
        if ($key === "") {
            throw new HttpException(
                "Configure COLLECTOR_SERPAPI_KEY in the collector before enabling this source.",
            );
        }
        $parameters = [
            "engine" => "google_jobs",
            "q" => $settings["query"],
            "location" => $settings["location"],
            "hl" => "en",
            "gl" => "ke",
            "api_key" => $key,
        ];
        $items = [];
        $seen = [];
        $token = null;
        $limit = max(1, min(20, $limit));
        // At most two billed search requests; never follow provider-supplied URLs.
        for ($page = 0; $page < $maxRequests; $page++) {
            if ($token !== null) {
                $parameters["next_page_token"] = $token;
            }
            $response = $this->http->json(
                "GET",
                $source["url"] . "?" . http_build_query($parameters),
            );
            if (isset($response["error"])) {
                // Provider errors may echo credentials; never reflect their text into logs/UI.
                throw new HttpException(
                    "SerpApi could not return Google Jobs results. Check your key, account quota, query and location.",
                );
            }
            if (
                !isset($response["jobs_results"]) ||
                !is_array($response["jobs_results"]) ||
                !array_is_list($response["jobs_results"])
            ) {
                throw new HttpException(
                    "SerpApi did not return a valid Google Jobs results list.",
                );
            }
            foreach ($this->parse($response["jobs_results"]) as $item) {
                $url = $item["structured"]["original_url"];
                if (!isset($seen[$url])) {
                    $items[] = $item;
                    $seen[$url] = true;
                }
                if (count($items) >= $limit) {
                    return $items;
                }
            }
            $next = $response["serpapi_pagination"]["next_page_token"] ?? null;
            if (
                !is_string($next) ||
                $next === "" ||
                strlen($next) > 4000 ||
                $next === $token
            ) {
                break;
            }
            $token = $next;
        }
        return $items;
    }

    public function parse(array $results): array
    {
        $items = [];
        foreach (array_slice($results, 0, 100) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $title = $this->text($result["title"] ?? "", 250);
            $employer = $this->text($result["company_name"] ?? "", 250);
            $description = $this->text($result["description"] ?? "", 60000);
            $url = $this->originalUrl($result);
            if (
                $title === "" ||
                $employer === "" ||
                $description === "" ||
                $url === null
            ) {
                continue;
            }
            $extensions = is_array($result["detected_extensions"] ?? null)
                ? $result["detected_extensions"]
                : [];
            $items[] = [
                "structured" => [
                    "title" => $title,
                    "employer" => $employer,
                    "description" => $description,
                    "location" => $this->text($result["location"] ?? "", 200),
                    "employment_type" => match (
                        strtolower(
                            $this->text(
                                $extensions["schedule_type"] ?? "",
                                100,
                            ),
                        )
                    ) {
                        "full-time", "full time" => "full_time",
                        "part-time", "part time" => "part_time",
                        "contractor", "contract" => "contract",
                        "internship", "intern" => "internship",
                        "temporary" => "temporary",
                        "volunteer" => "volunteer",
                        default => "not_specified",
                    },
                    "workplace" =>
                        ($extensions["work_from_home"] ?? false) === true
                            ? "remote"
                            : "not_specified",
                    "deadline" => null,
                    "original_url" => $url,
                    "application_method" => "external",
                ],
            ];
        }
        return $items;
    }

    private function originalUrl(array $result): ?string
    {
        $candidates = [$result["source_link"] ?? null];
        foreach (
            is_array($result["apply_options"] ?? null)
                ? $result["apply_options"]
                : []
            as $option
        ) {
            if (is_array($option)) {
                $candidates[] = $option["link"] ?? null;
            }
        }
        // A Google share link is a user-facing fallback, never a collection endpoint.
        $candidates[] = $result["share_link"] ?? null;
        foreach ($candidates as $candidate) {
            if (
                !is_string($candidate) ||
                strlen($candidate) > 2048 ||
                preg_match('/[\x00-\x20\x7f\\\\]/', $candidate)
            ) {
                continue;
            }
            $parts = parse_url($candidate);
            if (
                !filter_var($candidate, FILTER_VALIDATE_URL) ||
                ($parts["scheme"] ?? "") !== "https" ||
                isset($parts["user"]) ||
                isset($parts["pass"]) ||
                ($parts["port"] ?? 443) !== 443
            ) {
                continue;
            }
            $host = strtolower(trim($parts["host"] ?? "", "[]"));
            if ($this->sourceKey === "indeed") {
                parse_str($parts["query"] ?? "", $indeedQuery);
                if (
                    !(
                        ($host === "indeed.com" ||
                            str_ends_with($host, ".indeed.com")) &&
                        ($parts["path"] ?? "") === "/viewjob" &&
                        is_string($indeedQuery["jk"] ?? null) &&
                        preg_match('/^[a-f0-9]{16}$/Di', $indeedQuery["jk"])
                    )
                ) {
                    continue;
                }
                // Keep the stable vacancy identity, excluding click/tracking parameters.
                $candidate =
                    "https://" .
                    $host .
                    "/viewjob?jk=" .
                    strtolower($indeedQuery["jk"]);
            }
            if (
                !str_contains($host, ".") ||
                $host === "localhost" ||
                str_ends_with($host, ".local") ||
                str_ends_with($host, ".localhost") ||
                (filter_var($host, FILTER_VALIDATE_IP) &&
                    !UrlGuard::publicIp($host))
            ) {
                continue;
            }
            if (
                !filter_var($host, FILTER_VALIDATE_IP) &&
                !preg_match('/\.[a-z]{2,63}$/', $host)
            ) {
                continue;
            }
            if (in_array($host, ["google.com", "www.google.com"], true)) {
                parse_str($parts["query"] ?? "", $query);
                if (
                    ($parts["path"] ?? "") !== "/search" ||
                    !is_string($query["htidocid"] ?? null) ||
                    $query["htidocid"] === ""
                ) {
                    continue;
                }
            }
            return UrlGuard::canonical($candidate);
        }
        return null;
    }

    private function text(mixed $value, int $length): string
    {
        return is_string($value)
            ? mb_substr((new PublicContent())->text($value), 0, $length)
            : "";
    }
}
