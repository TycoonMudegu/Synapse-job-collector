<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** Read one selected file without changing or inheriting process variables. */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split("/\R/", $contents) as $line) {
            $line = trim($line);
            if (
                $line === "" ||
                str_starts_with($line, "#") ||
                !str_contains($line, "=")
            ) {
                continue;
            }
            [$key, $value] = explode("=", $line, 2);
            $key = trim($key);
            if (!array_key_exists($key, $values)) {
                $values[$key] = trim(trim($value), "\"'");
            }
        }
        return $values;
    }

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            as $line
        ) {
            $line = trim($line);
            if (str_starts_with($line, "#") || !str_contains($line, "=")) {
                continue;
            }
            [$key, $value] = explode("=", $line, 2);
            if (getenv(trim($key)) === false) {
                putenv(trim($key) . "=" . trim(trim($value), "\"'"));
            }
        }
    }

    public static function get(string $key, string $default = ""): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(
            self::get($key, $default ? "true" : "false"),
            FILTER_VALIDATE_BOOL,
        );
    }
}
