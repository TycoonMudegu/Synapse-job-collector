<?php

declare(strict_types=1);

require dirname(__DIR__) . "/bootstrap.php";

use App\Collector\CallbackClient;
use App\Collector\Runner;
use App\Core\HttpException;

try {
    $run = getenv("COLLECTOR_RUN_ID") ?: "";
    if ($run !== "" && (!ctype_digit($run) || (int) $run < 1)) {
        throw new HttpException(
            "COLLECTOR_RUN_ID must be a positive integer when supplied.",
        );
    }
    if ((getenv("CLOUD_RUN_TASK_COUNT") ?: "1") !== "1") {
        throw new HttpException(
            "The collector supports exactly one task per execution.",
        );
    }
    if (function_exists("pcntl_async_signals")) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never {
            throw new HttpException(
                "The collector execution time limit was reached.",
            );
        });
        pcntl_alarm(1100);
    }
    $client = new CallbackClient(
        getenv("SYNAPSE_URL") ?: "",
        getenv("COLLECTOR_TOKEN") ?: "",
    );
    $result = (new Runner($client))->run(
        $run !== "" ? (int) $run : null,
        getenv("CLOUD_RUN_EXECUTION") ?: "local-" . bin2hex(random_bytes(8)),
    );
    if (function_exists("pcntl_alarm")) {
        pcntl_alarm(0);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(
        in_array($result["status"], ["completed", "partial", "skipped"], true)
            ? 0
            : 1
    );
} catch (Throwable $error) {
    fwrite(
        STDERR,
        json_encode(
            [
                "status" => "failed",
                "error" =>
                    $error instanceof HttpException
                        ? $error->getMessage()
                        : "Collector failed. Check configuration and execution logs.",
            ],
            JSON_THROW_ON_ERROR,
        ) . PHP_EOL,
    );
    exit(1);
}
