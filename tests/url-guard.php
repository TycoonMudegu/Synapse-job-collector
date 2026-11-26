<?php

declare(strict_types=1);

namespace App\Services\Security {
    final class FixtureDns
    {
        public static array|false $records = false;
        public static array|false $addresses = false;
        public static int $fallbackCalls = 0;
    }

    function dns_get_record(string $host, int $type): array|false
    {
        return FixtureDns::$records;
    }

    function gethostbynamel(string $host): array|false
    {
        FixtureDns::$fallbackCalls++;
        return FixtureDns::$addresses;
    }
}

namespace {
    require dirname(__DIR__) . "/bootstrap.php";

    use App\Core\HttpException;
    use App\Services\Security\FixtureDns;
    use App\Services\Security\UrlGuard;

    function ensure(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
        echo "PASS " . $message . PHP_EOL;
    }

    foreach ([false, [], [["type" => "CNAME", "target" => "alias.example.test"]]] as $records) {
        FixtureDns::$records = $records;
        FixtureDns::$addresses = ["8.8.8.8"];
        FixtureDns::$fallbackCalls = 0;
        $resolved = UrlGuard::validate("https://fixture.example.test/jobs");
        ensure(
            $resolved["host"] === "fixture.example.test" &&
                $resolved["ip"] === "8.8.8.8" && FixtureDns::$fallbackCalls === 1,
            "system IPv4 resolution supplies a public address after a record-query failure",
        );
    }

    foreach ([
        ["127.0.0.1"],
        ["169.254.169.254"],
        ["10.0.0.1"],
        ["8.8.8.8", "169.254.169.254"],
    ] as $addresses) {
        FixtureDns::$records = false;
        FixtureDns::$addresses = $addresses;
        try {
            UrlGuard::validate("https://fixture.example.test/jobs");
            throw new RuntimeException("A private fallback address was accepted.");
        } catch (HttpException $error) {
            ensure(
                str_contains($error->getMessage(), "Private, local and reserved"),
                "fallback results reject private addresses even after a public address",
            );
        }
    }

    FixtureDns::$records = [["ip" => "8.8.8.8"]];
    FixtureDns::$addresses = ["169.254.169.254"];
    FixtureDns::$fallbackCalls = 0;
    ensure(
        UrlGuard::validate("https://fixture.example.test/jobs")["ip"] === "8.8.8.8" &&
            FixtureDns::$fallbackCalls === 0,
        "a successful record query supplies the pinned address without another lookup",
    );

    FixtureDns::$records = [["ip" => "8.8.8.8"], ["ipv6" => "::1"]];
    try {
        UrlGuard::validate("https://fixture.example.test/jobs");
        throw new RuntimeException("A private IPv6 record was accepted.");
    } catch (HttpException $error) {
        ensure(
            str_contains($error->getMessage(), "Private, local and reserved"),
            "mixed public IPv4 and private IPv6 records remain rejected",
        );
    }

    FixtureDns::$records = false;
    FixtureDns::$addresses = false;
    try {
        UrlGuard::validate("https://fixture.example.test/jobs");
        throw new RuntimeException("An unresolved hostname was accepted.");
    } catch (HttpException $error) {
        ensure(
            $error->getMessage() === "The hostname could not be resolved.",
            "an unresolved hostname fails before any HTTP request",
        );
    }
}
