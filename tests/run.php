<?php

declare(strict_types=1);

require dirname(__DIR__) . "/bootstrap.php";

use App\Collector\CallbackClient;
use App\Collector\Runner;
use App\Core\HttpException;
use App\Services\Discovery\GoogleJobsAdapter;
use App\Services\Discovery\MyJobMagAdapter;
use App\Services\Discovery\PublicContent;
use App\Services\Discovery\PublicJobParser;
use App\Services\Security\UrlGuard;

function ensure(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS " . $message . PHP_EOL;
}

final class FakeCallback extends CallbackClient
{
    public array $calls = [];
    public function __construct(public array $claim) {}
    public function send(string $action, array $payload): array
    {
        $this->calls[] = [$action, $payload];
        return $action === "claim" ? $this->claim : ["status" => "partial"];
    }
}

$job = [
    "title" => "Fixture Developer",
    "employer" => "Fixture Employer",
    "description" => "Test-only vacancy, never a real listing.",
    "original_url" => "https://example.com/jobs/fixture",
];
$api = new FakeCallback([
    "run_id" => 5,
    "run_token" => str_repeat("a", 64),
    "sources" => [["id" => 1], ["id" => 2]],
]);
$runner = new Runner(
    $api,
    static fn(array $source): array => $source["id"] === 1
        ? array_fill(0, 43, $job)
        : throw new HttpException("Fixture source unavailable."),
);
$result = $runner->run(5, "fixture-execution");
$batches = array_values(
    array_filter(
        $api->calls,
        static fn(array $call): bool => $call[0] === "batch",
    ),
);
ensure(
    array_map(
        static fn(array $call): int => count($call[1]["jobs"]),
        $batches,
    ) === [20, 20, 3],
    "large source results are split into bounded batches",
);
ensure(
    array_column(array_column($batches, 1), "batch_key") === [
        "chunk-0",
        "chunk-1",
        "chunk-2",
    ],
    "callback batches carry stable replay identities",
);
$finish = $api->calls[count($api->calls) - 1][1];
ensure(
    $finish["results"][0]["status"] === "completed" &&
        $finish["results"][1]["status"] === "failed",
    "one failed source does not discard another source’s imports",
);
ensure(
    $finish["results"][1]["error"] === "Fixture source unavailable.",
    "source failures are reported explicitly",
);
ensure(
    $result["status"] === "partial",
    "run status comes from the authoritative import server",
);

$skip = new FakeCallback(["skip" => true]);
ensure(
    (new Runner(
        $skip,
        static fn(): array => throw new RuntimeException("Must not collect."),
    ))->run(null, "fixture-skip")["status"] === "skipped" &&
        count($skip->calls) === 1,
    "duplicate or limited runs exit before contacting job sites",
);

$large = new FakeCallback([
    "run_id" => 6,
    "run_token" => str_repeat("b", 64),
    "sources" => [["id" => 3]],
]);
(new Runner(
    $large,
    static fn(): array => array_fill(0, 10, [
        ...$job,
        "description" => str_repeat("x", 250000),
    ]),
))->run(6, "fixture-size");
foreach ($large->calls as [$action, $payload]) {
    if ($action === "batch") {
        ensure(
            strlen(json_encode($payload)) < 2097152,
            "callback payload stays below the server byte limit",
        );
    }
}

$xml =
    "<rss><channel><item><position>Fixture Engineer</position><company>Fixture Ltd</company><link>https://www.myjobmag.co.ke/job/fixture</link><description>Fixture description.</description><location>Nairobi</location></item></channel></rss>";
$parsed = (new MyJobMagAdapter())->parse($xml, 1);
ensure(
    $parsed[0]["structured"]["employer"] === "Fixture Ltd",
    "MyJobMag retains explicit employer data without AI",
);
try {
    (new MyJobMagAdapter())->parse(
        '<!DOCTYPE rss [<!ENTITY private SYSTEM "file:///etc/passwd">]><rss/>',
        1,
    );
    throw new RuntimeException("Unsafe XML was accepted.");
} catch (HttpException) {
    ensure(true, "feed parsing rejects external XML entities");
}
$html =
    '<script type="application/ld+json">' .
    json_encode([
        "@type" => "JobPosting",
        "title" => "Fixture Engineer",
        "hiringOrganization" => ["name" => "Fixture Ltd"],
        "description" => "<p>Fixture duties.</p>",
        "jobLocation" => ["address" => ["addressLocality" => "Nairobi"]],
    ]) .
    "</script>";
$parsed = (new PublicJobParser())->job(
    $html,
    "https://example.com/job/fixture",
);
ensure(
    $parsed["title"] === "Fixture Engineer" && $parsed["deadline"] === null,
    "public job parsing preserves advertised facts and unknown deadlines",
);
ensure(
    !(new PublicContent())->allowed(
        "User-agent: *\nDisallow: /private",
        "/private/job",
    ),
    "robots restrictions remain enforced",
);
ensure(
    !UrlGuard::publicIp("169.254.169.254") && !UrlGuard::publicIp("127.0.0.1"),
    "collection rejects metadata and loopback addresses",
);
$results = (new GoogleJobsAdapter(sourceKey: "indeed"))->parse([
    [
        "title" => "Fixture Engineer",
        "company_name" => "Fixture Ltd",
        "description" => "Fixture duties.",
        "apply_options" => [
            [
                "link" =>
                    "https://www.indeed.com/viewjob?jk=0123456789abcdef&utm_source=fixture",
            ],
        ],
    ],
]);
ensure(
    $results[0]["structured"]["original_url"] ===
        "https://www.indeed.com/viewjob?jk=0123456789abcdef",
    "Indeed uses explicit provider results and keeps stable vacancy URLs",
);
echo "Collector tests passed. No live job sites, paid APIs or Synapse callbacks were contacted.\n";
