<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class JsonFeedAdapter implements SourceAdapter
{
    public function identifier(): string
    {
        return "json";
    }

    public function validate(array $source): void
    {
        $this->collect($source, 1);
    }

    public function collect(array $source, int $limit): array
    {
        try {
            $response = json_decode(
                (new PublicContent())->fetch($source["url"]),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw new HttpException("This source did not return valid JSON.");
        }
        $items = $response["jobs"] ?? ($response["items"] ?? $response);
        if (!is_array($items) || !array_is_list($items)) {
            throw new HttpException(
                "JSON sources must return a list, a jobs list, or JSON Feed items.",
            );
        }
        $result = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            if (!is_array($item)) {
                throw new HttpException(
                    "The feed contains invalid job records.",
                );
            }
            if (
                isset($item["employer"], $item["description"], $item["title"])
            ) {
                $result[] = ["structured" => $item];
            } else {
                $result[] = [
                    "text" => trim(
                        ($item["title"] ?? "") .
                            "\n" .
                            strip_tags(
                                $item["content_text"] ??
                                    ($item["content_html"] ??
                                        ($item["summary"] ?? "")),
                            ),
                    ),
                    "url" => $item["url"] ?? "",
                ];
            }
        }
        return $result;
    }
}
