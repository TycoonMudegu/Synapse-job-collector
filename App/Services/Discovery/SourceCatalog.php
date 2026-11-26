<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class SourceCatalog
{
    public const SOURCES = [
        "myjobmag" => [
            "name" => "MyJobMag Kenya",
            "adapter" => "myjobmag",
            "url" => "https://www.myjobmag.co.ke/jobsxml_by_categories.xml",
            "setup_url" => "https://www.myjobmag.co.ke/feeds/",
            "description" =>
                "Import the official detailed RSS feed. No AI key or email account is needed.",
        ],
        "brightermonday" => [
            "name" => "BrighterMonday Kenya",
            "adapter" => "brightermonday_public",
            "url" => "https://www.brightermonday.co.ke/jobs/software-data",
            "setup_url" => "https://www.brightermonday.co.ke/job-seeker",
            "sender_domain" => "brightermonday.co.ke",
            "description" =>
                "Collect public Software & Data vacancies directly. No Gmail or AI key is required.",
        ],
        "fuzu" => [
            "name" => "Fuzu",
            "adapter" => "fuzu_public",
            "url" =>
                "https://www.fuzu.com/kenya/job/computers-software-development",
            "setup_url" => "https://www.fuzu.com/kenya/job",
            "sender_domain" => "fuzu.com",
            "description" =>
                "Browse Fuzu job listings in Chromium and collect public IT and software vacancies. No Gmail or AI key is required.",
        ],
        "corporatestaffing" => [
            "name" => "Corporate Staffing Kenya",
            "adapter" => "corporatestaffing_public",
            "url" =>
                "https://www.corporatestaffing.co.ke/category/it-jobs-in-kenya/",
            "setup_url" =>
                "https://www.corporatestaffing.co.ke/category/it-jobs-in-kenya/",
            "description" =>
                "Collect public IT vacancies from Corporate Staffing. No browser, Gmail or AI key is required.",
        ],
        "google_jobs" => [
            "name" => "Google Jobs",
            "adapter" => "google_jobs_api",
            "url" => "https://serpapi.com/search.json",
            "setup_url" =>
                "https://www.google.com/search?q=IT+jobs+in+Kenya&udm=8",
            "description" =>
                "Import Google Jobs results through SerpApi using your own API key. Configure the query and location in Job discovery settings.",
        ],
        "talent" => [
            "name" => "Talent.com Kenya",
            "adapter" => "talent_public",
            "url" => "https://ke.talent.com/jobs/k-software-developer-l-kenya",
            "setup_url" =>
                "https://ke.talent.com/jobs/k-software-developer-l-kenya",
            "description" =>
                "Collect public software vacancies from Talent.com Kenya. No browser, Gmail or API key is required.",
        ],
        "indeed" => [
            "name" => "Indeed via Google Jobs",
            "adapter" => "indeed_api",
            "url" => "https://serpapi.com/search.json",
            "setup_url" =>
                "https://www.indeed.com/jobs?q=software+developer&l=Kenya",
            "description" =>
                "Import vacancies with explicit Indeed job links from Google Jobs using your saved SerpApi connection. Coverage depends on Google’s index.",
        ],
    ];

    public static function get(string $key, string $mode = "public"): array
    {
        $definition =
            self::SOURCES[$key] ??
            throw new HttpException("Unknown job source.");
        if ($mode === "email" && isset($definition["sender_domain"])) {
            $definition["adapter"] = "email_alert";
            $definition["url"] = $definition["setup_url"];
            $definition["description"] =
                "Import the site’s subscribed email alerts through connected Gmail monitoring. AI screening is required to extract vacancies.";
        } elseif ($mode !== "public") {
            throw new HttpException("Unsupported collection mode.");
        }
        return $definition;
    }

    public static function gmailQuery(array $sources): string
    {
        $queries = [];
        foreach ($sources as $source) {
            $definition = self::get($source["preset_key"]);
            if (isset($definition["sender_domain"])) {
                $queries[] = "from:(" . $definition["sender_domain"] . ")";
            }
        }
        return $queries ? "{" . implode(" ", array_unique($queries)) . "}" : "";
    }

    public static function senderMatches(string $from, string $key): bool
    {
        if (preg_match("/<([^<>]+)>/", $from, $matches)) {
            $from = $matches[1];
        }
        $address = trim($from);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($address, "@"), 1));
        $expected = self::get($key)["sender_domain"] ?? "";
        return $expected !== "" &&
            ($domain === $expected || str_ends_with($domain, "." . $expected));
    }
}
