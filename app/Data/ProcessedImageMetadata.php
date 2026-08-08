<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ProcessedImageMetadata
{
    public function __construct(
        public int $size,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}
}
