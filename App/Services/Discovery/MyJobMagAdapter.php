<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class MyJobMagAdapter implements SourceAdapter
{
    public function identifier(): string
    {
        return "myjobmag";
    }

    public function validate(array $source): void
    {
        $this->collect($source, 1);
    }

    public function collect(array $source, int $limit): array
    {
        if ($source["url"] !== SourceCatalog::get("myjobmag")["url"]) {
            throw new HttpException(
                "Use MyJobMag’s officially advertised detailed RSS URL.",
            );
        }
        return $this->parse(
            (new PublicContent())->fetch($source["url"]),
            $limit,
        );
    }

    public function parse(string $xml, int $limit): array
    {
        if (
            strlen($xml) > 4194304 ||
            stripos($xml, "<!DOCTYPE") !== false ||
            stripos($xml, "<!ENTITY") !== false
        ) {
            throw new HttpException(
                "The MyJobMag feed exceeds safe XML limits.",
            );
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $feed = simplexml_load_string(
                $xml,
                \SimpleXMLElement::class,
                LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$feed || $feed->getName() !== "rss" || !isset($feed->channel)) {
            throw new HttpException(
                "MyJobMag did not return its expected detailed RSS feed.",
            );
        }
        $jobs = [];
        $invalid = 0;
        $scanned = 0;
        foreach ($feed->channel->item as $item) {
            if ($scanned++ >= 200 || count($jobs) >= max(1, min(200, $limit))) {
                break;
            }
            $title = trim((string) $item->position);
            $employer = trim((string) $item->company);
            $url = trim((string) $item->link);
            if (
                $title === "" ||
                $employer === "" ||
                !filter_var($url, FILTER_VALIDATE_URL) ||
                !in_array(
                    strtolower(parse_url($url, PHP_URL_HOST) ?? ""),
                    ["myjobmag.co.ke", "www.myjobmag.co.ke"],
                    true,
                ) ||
                parse_url($url, PHP_URL_SCHEME) !== "https"
            ) {
                $invalid++;
                continue;
            }
            $expiry = trim((string) $item->expiryDate);
            $deadline = null;
            if ($expiry !== "") {
                try {
                    $deadline = (new \DateTimeImmutable($expiry))->format(
                        "Y-m-d",
                    );
                } catch (\Exception) {
                    $invalid++;
                    continue;
                }
            }
            $description = html_entity_decode(
                strip_tags((string) $item->description),
                ENT_QUOTES | ENT_HTML5,
                "UTF-8",
            );
            if (trim($description) === "") {
                $invalid++;
                continue;
            }
            $jobs[] = [
                "structured" => [
                    "title" => $title,
                    "employer" => $employer,
                    "description" => trim($description),
                    "location" => trim((string) $item->location),
                    "deadline" => $deadline,
                    "industry" => trim((string) $item->industry),
                    "original_url" => $url,
                    "application_method" => "external",
                ],
            ];
        }
        if ($invalid > 0) {
            if ($jobs === []) {
                throw new HttpException(
                    "MyJobMag returned no usable vacancies with explicit position, employer, description and source URL fields.",
                );
            }
            error_log(
                "MyJobMag: skipped " .
                    $invalid .
                    " incomplete or invalid feed entries.",
            );
        }
        return $jobs;
    }
}
