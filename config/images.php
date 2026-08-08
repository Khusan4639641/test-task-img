<?php

declare(strict_types=1);

return [
    'disk' => env('IMAGE_DISK', 'local'),
    'temporary_directory' => 'images/tmp',
    'asset_directory' => 'images/assets',
    'max_bytes' => 5 * 1024 * 1024,
    'max_dimension' => 5_000,
    'max_pixels' => 6_000_000,
    'gd_bytes_per_pixel' => 8,
    'gd_reserved_memory_bytes' => 16 * 1024 * 1024,
    'gd_memory_budget_ratio' => 0.8,
    'jpeg_validator_binary' => env('JPEGINFO_BINARY', '/usr/bin/jpeginfo'),
    'jpeg_validator_timeout' => 10,
    'webp_quality' => 85,
    'cleanup_after_hours' => 24,
    'recovery_after_minutes' => 15,
];
