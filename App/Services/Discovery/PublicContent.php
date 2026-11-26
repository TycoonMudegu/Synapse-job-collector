<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Http\HttpClient;
use App\Services\Security\UrlGuard;

final class PublicContent
{
    private array $robotsCache = [];

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function fetch(string $url, bool $checkRobots = true): string
    {
        $resolved = UrlGuard::validate($url);
        if ($checkRobots) {
            $robots =
                $this->robotsCache[$resolved["host"]] ??
                $this->http->request(
                    "GET",
                    "https://" . $resolved["host"] . "/robots.txt",
                    timeout: 15,
                    maxBytes: 262144,
                );
            if (!in_array($robots["status"], [200, 404, 410], true)) {
                throw new HttpException(
                    "Robots permissions could not be verified for this website. Paste the advertisement text instead.",
                );
            }
            $this->robotsCache[$resolved["host"]] = $robots;
            if (
                $robots["status"] === 200 &&
                !$this->allowed(
                    $robots["body"],
                    ($resolved["parts"]["path"] ?? "/") .
                        (isset($resolved["parts"]["query"])
                            ? "?" . $resolved["parts"]["query"]
                            : ""),
                )
            ) {
                throw new HttpException(
                    "This website disallows automated access to that page. Paste the advertisement text instead.",
                );
            }
        }
        // Redirects are intentionally not followed: this prevents redirect-based SSRF and credential leakage.
        $response = $this->http->request("GET", $url);
        if ($response["status"] < 200 || $response["status"] >= 300) {
            throw new HttpException(
                "The page returned HTTP " .
                    $response["status"] .
                    ". Use the final public URL or paste the advertisement text.",
            );
        }
        return $response["body"];
    }

    public function allowed(string $robots, string $path): bool
    {
        $agents = [];
        $inRules = false;
        $best = -1;
        $allowed = true;
        foreach (preg_split("/\R/", $robots) as $line) {
            $line = trim(explode("#", $line, 2)[0]);
            if (!str_contains($line, ":")) {
                continue;
            }
            [$directive, $value] = array_map("trim", explode(":", $line, 2));
            $directive = strtolower($directive);
            if ($directive === "user-agent") {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                continue;
            }
            $inRules = true;
            if (
                !array_intersect($agents, ["*", "synapse", "jobflow"]) ||
                !in_array($directive, ["allow", "disallow"], true) ||
                $value === ""
            ) {
                continue;
            }
            $pattern =
                "#^" .
                str_replace(
                    ["\*", '\$'],
                    [".*", '$'],
                    preg_quote($value, "#"),
                ) .
                "#";
            if (preg_match($pattern, $path) && strlen($value) >= $best) {
                $best = strlen($value);
                $allowed = $directive === "allow";
            }
        }
        return $allowed;
    }

    public function text(string $html): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        foreach (
            $xpath->query("//script|//style|//nav|//footer|//header|//noscript")
            as $node
        ) {
            $node->parentNode?->removeChild($node);
        }
        return mb_substr(
            trim(preg_replace('/[ \t]+/', " ", $document->textContent)),
            0,
            60000,
        );
    }
}
