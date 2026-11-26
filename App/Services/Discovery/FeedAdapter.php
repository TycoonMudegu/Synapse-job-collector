<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class FeedAdapter implements SourceAdapter
{
    public function __construct(
        private readonly PublicContent $content = new PublicContent(),
    ) {}

    public function identifier(): string
    {
        return "rss";
    }

    public function validate(array $source): void
    {
        $this->collect($source, 1);
    }

    public function collect(array $source, int $limit): array
    {
        $xml = $this->content->fetch($source["url"]);
        if (
            stripos($xml, "<!DOCTYPE") !== false ||
            stripos($xml, "<!ENTITY") !== false
        ) {
            throw new HttpException(
                "Feeds containing DTDs or entities are not supported.",
            );
        }
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string(
            $xml,
            \SimpleXMLElement::class,
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (
            !$feed ||
            !in_array($feed->getName(), ["rss", "feed", "RDF"], true)
        ) {
            throw new HttpException(
                "The source is not a supported RSS or Atom feed.",
            );
        }
        $feed->registerXPathNamespace("atom", "http://www.w3.org/2005/Atom");
        $items = $feed->xpath("//item") ?: $feed->xpath("//atom:entry") ?: [];
        $results = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $content =
                (string) ($item->description ??
                    ($item->summary ?? ($item->content ?? "")));
            $contentNamespace = $item->children(
                "http://purl.org/rss/1.0/modules/content/",
            );
            if (isset($contentNamespace->encoded)) {
                $content = (string) $contentNamespace->encoded;
            }
            $url = (string) $item->link;
            if ($url === "") {
                foreach ($item->link as $link) {
                    if (
                        (string) $link["rel"] === "alternate" ||
                        !isset($link["rel"])
                    ) {
                        $url = (string) $link["href"];
                        break;
                    }
                }
            }
            $results[] = [
                "text" => trim(
                    (string) $item->title . "\n" . strip_tags($content),
                ),
                "url" => $url,
            ];
        }
        return $results;
    }
}
