<?php

declare(strict_types=1);

namespace App\Collector;

use App\Core\HttpException;
use App\Services\Discovery\FeedAdapter;
use App\Services\Discovery\FuzuBrowserClient;
use App\Services\Discovery\GoogleJobsAdapter;
use App\Services\Discovery\JsonFeedAdapter;
use App\Services\Discovery\MyJobMagAdapter;
use App\Services\Discovery\PublicContent;
use App\Services\Discovery\PublicJobParser;
use App\Services\Discovery\PublicJobsAdapter;

final class Collectors
{
    public function collect(array $source): array
    {
        $limit = max(
            1,
            min(
                $source["adapter"] === "myjobmag" ? 100 : 20,
                (int) $source["max_jobs"],
            ),
        );
        $browser = null;
        $public = new PublicContent();
        try {
            $adapter = match ($source["adapter"]) {
                "myjobmag" => new MyJobMagAdapter(),
                "brightermonday_public" => new PublicJobsAdapter(
                    "brightermonday",
                ),
                "talent_public" => new PublicJobsAdapter("talent"),
                "corporatestaffing_public" => new PublicJobsAdapter(
                    "corporatestaffing",
                ),
                "fuzu_public" => $this->browserAdapter($browser),
                "indeed_api" => new GoogleJobsAdapter(sourceKey: "indeed"),
                "json" => new JsonFeedAdapter(),
                "rss" => new FeedAdapter($public),
                default => throw new HttpException(
                    "This source needs a supported collector adapter.",
                ),
            };
            $items = $adapter->collect($source, $limit);
            $jobs = [];
            foreach (array_slice($items, 0, $limit) as $item) {
                if (isset($item["structured"])) {
                    $job = $item["structured"];
                } elseif (
                    $source["adapter"] === "rss" &&
                    !empty($item["url"])
                ) {
                    usleep(1000000);
                    try {
                        $job = (new PublicJobParser())->job(
                            $public->fetch($item["url"]),
                            $item["url"],
                        );
                    } catch (HttpException) {
                        continue;
                    }
                } else {
                    continue;
                }
                if (
                    !is_array($job) ||
                    empty($job["title"]) ||
                    empty($job["employer"]) ||
                    empty($job["description"]) ||
                    empty($job["original_url"])
                ) {
                    continue;
                }
                if (
                    !empty($job["deadline"]) &&
                    $job["deadline"] < date("Y-m-d")
                ) {
                    continue;
                }
                // Private IDs and factual approval never cross the import boundary.
                unset(
                    $job["verified"],
                    $job["verified_at"],
                    $job["source_id"],
                    $job["poster_document_id"],
                );
                $jobs[] = $job;
            }
            if ($items !== [] && $jobs === []) {
                throw new HttpException(
                    "No complete, current vacancies were returned. Structured title, employer, description and original URL are required.",
                );
            }
            return $jobs;
        } finally {
            $browser?->close();
        }
    }

    private function browserAdapter(
        ?FuzuBrowserClient &$browser,
    ): PublicJobsAdapter {
        if (getenv("COLLECTOR_BROWSER_ENABLED") !== "true") {
            throw new HttpException(
                "Fuzu needs the browser image and COLLECTOR_BROWSER_ENABLED=true.",
            );
        }
        $browser = new FuzuBrowserClient();
        return new PublicJobsAdapter("fuzu", new PublicContent($browser));
    }
}
