<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\HttpException;

final class UrlGuard
{
    public static function publicIp(string $ip): bool
    {
        if (
            !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )
        ) {
            return false;
        }
        if (str_contains($ip, ":")) {
            // Permit only global unicast IPv6; reject IPv4-mapped and transition ranges.
            $packed = inet_pton($ip);
            if ($packed === false || (ord($packed[0]) & 0xe0) !== 0x20) {
                return false;
            }
            $prefix = unpack("nfirst/nsecond", substr($packed, 0, 4));
            // IANA special-use: 2001::/23, documentation 2001:db8::/32,
            // 6to4 2002::/16, and documentation 3fff::/20. Other 2001::
            // allocations include ordinary public Google API addresses.
            return !(
                ($prefix["first"] === 0x2001 &&
                    ($prefix["second"] < 0x0200 ||
                        $prefix["second"] === 0x0db8)) ||
                $prefix["first"] === 0x2002 ||
                ($prefix["first"] === 0x3fff && $prefix["second"] < 0x1000)
            );
        }
        $octets = array_map("intval", explode(".", $ip));
        if (
            $octets[0] >= 224 ||
            ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127)
        ) {
            return false;
        }
        foreach (
            [
                ["192.0.0.0", 24],
                ["192.0.2.0", 24],
                ["192.88.99.0", 24],
                ["198.18.0.0", 15],
                ["198.51.100.0", 24],
                ["203.0.113.0", 24],
            ]
            as [$network, $prefix]
        ) {
            $mask = -1 << (32 - $prefix);
            if ((ip2long($ip) & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }
        return true;
    }

    public static function validate(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new HttpException("Enter a valid public HTTPS URL.");
        }
        $parts = parse_url($url);
        if (
            !$parts ||
            ($parts["scheme"] ?? "") !== "https" ||
            empty($parts["host"]) ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            ($parts["port"] ?? 443) !== 443
        ) {
            throw new HttpException(
                "Only public HTTPS URLs on port 443 are supported.",
            );
        }
        $host = strtolower(trim($parts["host"], "[]"));
        if (
            !preg_match('/^[a-z0-9.:-]+$/i', $host) ||
            (!str_contains($host, ".") && !str_contains($host, ":"))
        ) {
            throw new HttpException("This hostname is not permitted.");
        }
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                if (isset($record["ip"]) || isset($record["ipv6"])) {
                    $addresses[] = $record["ip"] ?? $record["ipv6"];
                }
            }
        }
        if ($addresses === []) {
            throw new HttpException("The hostname could not be resolved.");
        }
        foreach ($addresses as $address) {
            if (!self::publicIp($address)) {
                throw new HttpException(
                    "Private, local and reserved network addresses are not permitted.",
                );
            }
        }
        return ["host" => $host, "ip" => $addresses[0], "parts" => $parts];
    }

    public static function canonical(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || !isset($parts["host"])) {
            throw new HttpException("Invalid job URL.");
        }
        parse_str($parts["query"] ?? "", $query);
        foreach (array_keys($query) as $key) {
            if (
                str_starts_with(strtolower($key), "utm_") ||
                in_array(strtolower($key), ["fbclid", "gclid"], true)
            ) {
                unset($query[$key]);
            }
        }
        ksort($query);
        $queryString = http_build_query($query);
        return strtolower($parts["scheme"] ?? "https") .
            "://" .
            strtolower($parts["host"]) .
            rtrim($parts["path"] ?? "/", "/") .
            ($queryString !== "" ? "?" . $queryString : "");
    }
}
