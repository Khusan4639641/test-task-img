<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\UploadedImageMetadata;
use App\Exceptions\InvalidUploadedImage;
use GdImage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class UploadedImageInspector
{
    public function inspect(string $path): UploadedImageMetadata
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidUploadedImage('The uploaded file cannot be read.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size <= 0) {
            throw new InvalidUploadedImage('The image file is empty.');
        }

        if ($size > (int) config('images.max_bytes')) {
            throw new InvalidUploadedImage('The image may not be greater than 5 MiB.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new InvalidUploadedImage('The image must be a valid PNG or JPEG file.');
        }

        $imageInfo = $this->withoutWarnings(static fn (): array|false => getimagesize($path));

        if (! is_array($imageInfo)
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || ! isset($imageInfo[0], $imageInfo[1])) {
            throw new InvalidUploadedImage('The image contents are invalid or corrupted.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $maxDimension = (int) config('images.max_dimension');
        $maxPixels = (int) config('images.max_pixels');

        if ($width <= 0 || $height <= 0
            || $width > $maxDimension || $height > $maxDimension
            || ($width * $height) > $maxPixels) {
            throw new InvalidUploadedImage('The image dimensions exceed the allowed limit.');
        }

        $this->ensureSafeMemoryBudget($width, $height);

        if ($mimeType === 'image/jpeg') {
            $this->validateJpegStructure($path);
        }

        $image = $this->withoutWarnings(
            static fn (): GdImage|false => $mimeType === 'image/jpeg'
                ? imagecreatefromjpeg($path)
                : imagecreatefrompng($path),
        );

        if (! $image instanceof GdImage) {
            throw new InvalidUploadedImage('The image contents are invalid or corrupted.');
        }

        unset($image);

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new InvalidUploadedImage('The uploaded file cannot be read.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $sha256 = hash_final($hash);
        } finally {
            fclose($stream);
        }

        return new UploadedImageMetadata($mimeType, $size, $width, $height, $sha256);
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

    private function ensureSafeMemoryBudget(int $width, int $height): void
    {
        $memoryLimit = $this->iniBytes((string) ini_get('memory_limit'));

        if ($memoryLimit === null) {
            return;
        }

        $estimatedBytes = memory_get_usage(true)
            + ($width * $height * (int) config('images.gd_bytes_per_pixel'))
            + (int) config('images.gd_reserved_memory_bytes');
        $budget = (int) floor($memoryLimit * (float) config('images.gd_memory_budget_ratio'));

        if ($estimatedBytes > $budget) {
            throw new InvalidUploadedImage('The image requires more memory than the safe processing budget.');
        }
    }

    private function validateJpegStructure(string $path): void
    {
        $process = new Process([
            (string) config('images.jpeg_validator_binary'),
            '-c',
            $path,
        ]);
        $process->setTimeout((float) config('images.jpeg_validator_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new InvalidUploadedImage('The JPEG structure validation timed out.');
        }

        if (! $process->isSuccessful()) {
            throw new InvalidUploadedImage('The JPEG file is structurally invalid or truncated.');
        }
    }

    private function iniBytes(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
