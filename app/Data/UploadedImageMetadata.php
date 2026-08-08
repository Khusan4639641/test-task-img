<?php

declare(strict_types=1);

namespace App\Data;

final readonly class UploadedImageMetadata
{
    public function __construct(
        public string $mimeType,
        public int $size,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}
}
