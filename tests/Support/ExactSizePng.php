<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class ExactSizePng
{
    public static function create(int $targetBytes): string
    {
        $image = imagecreatetruecolor(2, 2);
        $color = imagecolorallocate($image, 25, 100, 180);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $base = ob_get_clean();
        unset($image);

        if (! is_string($base) || substr($base, -8, 4) !== 'IEND') {
            throw new RuntimeException('Unable to generate the base PNG.');
        }

        $payloadBytes = $targetBytes - strlen($base) - 12;

        if ($payloadBytes < 0) {
            throw new RuntimeException('The requested PNG size is too small.');
        }

        $path = tempnam(sys_get_temp_dir(), 'image-api-png-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary PNG path.');
        }

        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the temporary PNG.');
        }

        try {
            $prefix = substr($base, 0, -12);
            $iend = substr($base, -12);
            $type = 'ruSt';
            fwrite($stream, $prefix);
            fwrite($stream, pack('N', $payloadBytes));
            fwrite($stream, $type);
            $crc = hash_init('crc32b');
            hash_update($crc, $type);
            $remaining = $payloadBytes;
            $zeros = str_repeat("\0", 8192);

            while ($remaining > 0) {
                $chunk = substr($zeros, 0, min($remaining, strlen($zeros)));
                fwrite($stream, $chunk);
                hash_update($crc, $chunk);
                $remaining -= strlen($chunk);
            }

            fwrite($stream, hash_final($crc, true));
            fwrite($stream, $iend);
        } finally {
            fclose($stream);
        }

        clearstatcache(true, $path);

        if (filesize($path) !== $targetBytes) {
            unlink($path);

            throw new RuntimeException('The exact-size PNG has an unexpected length.');
        }

        return $path;
    }
}
