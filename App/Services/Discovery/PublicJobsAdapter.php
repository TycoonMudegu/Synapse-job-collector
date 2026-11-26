<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class PublicJobsAdapter implements SourceAdapter
{
    public function __construct(
        private readonly string $sourceKey,
        private readonly PublicContent $content = new PublicContent(),
        private readonly int $delayMilliseconds = 1000,
    ) {
        if (
            !in_array(
                $sourceKey,
                ["brightermonday", "fuzu", "corporatestaffing", "talent"],
                true,
            )
        ) {
            throw new HttpException("Unsupported public job source.");
        }
    }

    public function identifier(): string
    {
        return $this->sourceKey . "_public";
    }

    public function validate(array $source): void
    {
        $this->collect($source, 1);
    }

    public function collect(array $source, int $limit): array
    {
        $definition = SourceCatalog::get($this->sourceKey);
        if ($source["url"] !== $definition["url"]) {
            throw new HttpException(
                "Use the built-in public category URL for this source.",
            );
        }
        $parser =
            $this->sourceKey === "talent"
                ? new TalentJobParser()
                : new PublicJobParser();
        if ($this->sourceKey === "fuzu") {
            // Start at the ordinary job search before opening its technical category.
            $this->fetch($definition["setup_url"]);
            if ($this->delayMilliseconds > 0) {
                usleep(min(5000, $this->delayMilliseconds) * 1000);
            }
        }
        $links = $parser->links($this->fetch($source["url"]), $this->sourceKey);
        if ($links === []) {
            throw new HttpException(
                "No recognisable public vacancy links were returned. The page may have changed or require a browser check.",
            );
        }
        $jobs = [];
        $skipped = 0;
        $consecutiveServerErrors = 0;
        // One category page (plus Fuzu's entry page) and at most 20 sequential vacancy pages.
        foreach (array_slice($links, 0, 20) as $url) {
            if ($this->delayMilliseconds > 0) {
                usleep(min(5000, $this->delayMilliseconds) * 1000);
            }
            try {
                $html = $this->fetch($url);
            } catch (HttpException $exception) {
                if (
                    preg_match("/HTTP (404|410)\b/", $exception->getMessage())
                ) {
                    $consecutiveServerErrors = 0;
                    $skipped++;
                    continue;
                }
                if (
                    preg_match(
                        "/HTTP (500|502|503|504)\b/",
                        $exception->getMessage(),
                    )
                ) {
                    $skipped++;
                    if (++$consecutiveServerErrors < 3) {
                        continue;
                    }
                    if ($jobs !== []) {
                        break;
                    }
                }
                throw $exception;
            }
            $consecutiveServerErrors = 0;
            try {
                $jobs[] = ["structured" => $parser->job($html, $url)];
            } catch (HttpException) {
                $skipped++;
                continue;
            }
            if (count($jobs) >= max(1, min(20, $limit))) {
                break;
            }
        }
        if ($jobs === []) {
            throw new HttpException(
                "Public vacancy pages did not provide complete structured title, employer and description fields. Nothing was imported.",
            );
        }
        if ($skipped > 0) {
            error_log(
                $definition["name"] .
                    ": skipped " .
                    $skipped .
                    " unavailable or incomplete public vacancies.",
            );
        }
        return $jobs;
    }

    private function fetch(string $url): string
    {
        try {
            return $this->content->fetch($url);
        } catch (HttpException $exception) {
            if (
                preg_match(
                    "/HTTP (403|429)\b/",
                    $exception->getMessage(),
                    $match,
                )
            ) {
                throw new HttpException(
                    SourceCatalog::get($this->sourceKey)["name"] .
                        " blocked public collection from this server (HTTP " .
                        $match[1] .
                        "). No jobs were collected. Open the site to browse." .
                        (isset(
                            SourceCatalog::get($this->sourceKey)[
                                "sender_domain"
                            ],
                        )
                            ? " You can also use optional email alerts."
                            : ""),
                );
            }
            throw $exception;
        }
    }
}
