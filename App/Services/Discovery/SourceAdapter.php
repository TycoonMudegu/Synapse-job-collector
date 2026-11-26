<?php

declare(strict_types=1);

namespace App\Services\Discovery;

interface SourceAdapter
{
    public function identifier(): string;

    public function validate(array $source): void;

    public function collect(array $source, int $limit): array;
}
