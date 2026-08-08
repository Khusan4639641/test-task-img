<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProcessedImageMetadata;
use GdImage;
use RuntimeException;

final class ImageTransformer
{
    public function transformToWebp(
        string $sourcePath,
        string $destinationPath,
        string $sourceMime,
        int $quality,
    ): ProcessedImageMetadata {
        $image = $this->withoutWarnings(
            static fn (): GdImage|false => $sourceMime === 'image/jpeg'
                ? imagecreatefromjpeg($sourcePath)
                : imagecreatefrompng($sourcePath),
        );

        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD could not decode the source image.');
        }

        try {
            if ($sourceMime === 'image/jpeg') {
                $image = $this->applyExifOrientation($image, $sourcePath);
            } else {
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if (! $this->withoutWarnings(
                static fn (): bool => imagewebp($image, $destinationPath, $quality),
            )) {
                throw new RuntimeException('GD could not encode the WebP image.');
            }
        } finally {
            unset($image);
        }

        $size = filesize($destinationPath);

        if (! is_int($size) || $size <= 0) {
            throw new RuntimeException('The encoded WebP file is empty.');
        }

        $sha256 = hash_file('sha256', $destinationPath);

        if (! is_string($sha256)) {
            throw new RuntimeException('Unable to hash the encoded WebP file.');
        }

        return new ProcessedImageMetadata($size, $width, $height, $sha256);
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        $exif = $this->withoutWarnings(static fn (): array|false => exif_read_data($path));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('GD could not apply EXIF orientation.');
        }

        unset($image);

        return $rotated;
    }

    private function withoutWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
