<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

/** Reads Talent's public job card, excluding related jobs and application redirects. */
final class TalentJobParser
{
    private const ORIGIN = "https://ke.talent.com";

    public function links(string $html, string $sourceKey = "talent"): array
    {
        if ($sourceKey !== "talent") {
            throw new HttpException("Unsupported Talent source.");
        }
        $links = [];
        foreach ($this->document($html)->query("//a[@href]") as $anchor) {
            $url = $this->canonicalUrl(trim($anchor->getAttribute("href")));
            if ($url !== null) {
                $links[$url] = $url;
            }
        }
        return array_values($links);
    }

    public function job(string $html, string $url): array
    {
        if ($this->canonicalUrl($url) !== $url) {
            throw new HttpException(
                "Use a canonical public Talent vacancy URL.",
            );
        }
        $xpath = $this->document($html);
        $canonicals = $xpath->query('//link[@rel="canonical"]');
        if (
            $canonicals->length !== 1 ||
            $this->canonicalUrl($canonicals->item(0)->getAttribute("href")) !==
                $url
        ) {
            throw new HttpException(
                "Talent did not identify the requested public vacancy.",
            );
        }
        $articles = $xpath->query("//article[.//h1]");
        if ($articles->length !== 1) {
            throw new HttpException(
                "Talent did not return one primary vacancy.",
            );
        }
        $article = $articles->item(0);
        $titles = $xpath->query(".//h1", $article);
        $companies = $xpath->query(
            './/header//*[@data-hook="job-card-container"]/*[contains(@class,"_companyLocationRow__")]/span',
            $article,
        );
        $descriptions = $xpath->query(
            './/div[contains(@class,"_jobInfoContent__")]',
            $article,
        );
        if (
            $titles->length !== 1 ||
            $companies->length !== 3 ||
            $descriptions->length !== 1 ||
            $this->text($companies->item(1)) !== "•"
        ) {
            throw new HttpException(
                "Talent is missing an unambiguous title, employer or description.",
            );
        }
        $title = $this->text($titles->item(0));
        $employer = $this->text($companies->item(0));
        $description = $this->text($descriptions->item(0));
        if ($title === "" || $employer === "" || $description === "") {
            throw new HttpException(
                "Talent is missing an explicit title, employer or description.",
            );
        }
        return [
            "title" => $title,
            "employer" => $employer,
            "description" => $description,
            "location" => $this->text($companies->item(2)),
            "employment_type" => "not_specified",
            "workplace" => "not_specified",
            "deadline" => null,
            "original_url" => $url,
            "application_method" => "external",
        ];
    }

    private function canonicalUrl(string $url): ?string
    {
        if (str_starts_with($url, "/") && !str_starts_with($url, "//")) {
            $url = self::ORIGIN . $url;
        }
        $parts = parse_url($url);
        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            strlen($url) > 2048 ||
            ($parts["scheme"] ?? "") !== "https" ||
            ($parts["host"] ?? "") !== "ke.talent.com" ||
            ($parts["path"] ?? "") !== "/view" ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            ($parts["port"] ?? 443) !== 443
        ) {
            return null;
        }
        parse_str($parts["query"] ?? "", $query);
        if (
            !is_string($query["id"] ?? null) ||
            !preg_match('/^[0-9]{1,24}$/D', $query["id"])
        ) {
            return null;
        }
        foreach (array_keys($query) as $key) {
            if (
                $key !== "id" &&
                (!is_string($key) || !str_starts_with($key, "utm_"))
            ) {
                return null;
            }
        }
        return self::ORIGIN . "/view?id=" . $query["id"];
    }

    private function document(string $html): \DOMXPath
    {
        if (strlen($html) > 4194304 || trim($html) === "") {
            throw new HttpException(
                "Talent returned an empty page or exceeded the collection size limit.",
            );
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    private function text(\DOMNode $node): string
    {
        $html = $node->ownerDocument->saveHTML($node);
        $html = preg_replace(
            "#</?(?:p|br|div|li|ul|ol|h[1-6])(?:\s[^>]*)?>#i",
            "\n",
            $html,
        );
        return trim(
            str_replace("\u{00A0}", " ", (new PublicContent())->text($html)),
        );
    }
}
