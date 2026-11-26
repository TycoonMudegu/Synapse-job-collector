<?php

declare(strict_types=1);

namespace App\Collector;

use App\Core\HttpException;

final class Runner
{
    public function __construct(
        private readonly CallbackClient $api,
        private readonly ?\Closure $collect = null,
    ) {}

    public function run(?int $runId, string $execution): array
    {
        $claim = $this->api->send("claim", [
            "execution" => $execution,
            ...$runId !== null ? ["run_id" => $runId] : [],
        ]);
        if (!empty($claim["skip"])) {
            return ["status" => "skipped"];
        }
        if (
            !is_int($claim["run_id"] ?? null) ||
            !is_string($claim["run_token"] ?? null) ||
            !is_array($claim["sources"] ?? null) ||
            !array_is_list($claim["sources"]) ||
            count($claim["sources"]) > 50
        ) {
            throw new HttpException("Invalid collection claim.");
        }
        $identity = [
            "run_id" => $claim["run_id"],
            "run_token" => $claim["run_token"],
        ];
        $results = [];
        $started = hrtime(true);
        $deadline = max(
            60,
            min(1050, (int) (getenv("COLLECTOR_MAX_SECONDS") ?: 900)),
        );
        foreach ($claim["sources"] as $source) {
            try {
                if ((hrtime(true) - $started) / 1e9 > $deadline - 60) {
                    throw new HttpException(
                        "The collection time budget was reached before this source started.",
                    );
                }
                $jobs =
                    $this->collect !== null
                        ? ($this->collect)($source)
                        : (new Collectors())->collect($source);
                $chunk = [];
                $index = 0;
                foreach ($jobs as $job) {
                    if (
                        strlen(
                            json_encode(
                                $job,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                            ),
                        ) > 1800000
                    ) {
                        throw new HttpException(
                            "A vacancy exceeds the import size limit.",
                        );
                    }
                    if (
                        $chunk !== [] &&
                        (count($chunk) >= 20 ||
                            strlen(
                                json_encode(
                                    [...$chunk, $job],
                                    JSON_THROW_ON_ERROR |
                                        JSON_UNESCAPED_UNICODE,
                                ),
                            ) > 1800000)
                    ) {
                        $this->batch($identity, $source, $index++, $chunk);
                        $chunk = [];
                    }
                    $chunk[] = $job;
                }
                if ($chunk !== []) {
                    $this->batch($identity, $source, $index, $chunk);
                }
                $results[] = [
                    "source_id" => $source["id"],
                    "status" => "completed",
                    "error" => "",
                ];
            } catch (\Throwable $error) {
                // Only our explicit public error messages are reported; raw provider responses are excluded.
                $message =
                    $error instanceof HttpException
                        ? $error->getMessage()
                        : "Collection failed. Check this adapter and its configuration.";
                $results[] = [
                    "source_id" => $source["id"],
                    "status" => "failed",
                    "error" => mb_substr($message, 0, 1000),
                ];
            }
        }
        $finished = $this->api->send("finish", [
            ...$identity,
            "results" => $results,
        ]);
        return [
            "run_id" => $claim["run_id"],
            "status" => $finished["status"] ?? "unknown",
            "sources_checked" => count($results),
        ];
    }

    private function batch(
        array $identity,
        array $source,
        int $index,
        array $jobs,
    ): void {
        $this->api->send("batch", [
            ...$identity,
            "source_id" => $source["id"],
            "batch_key" => "chunk-" . $index,
            "jobs" => $jobs,
        ]);
    }
}
