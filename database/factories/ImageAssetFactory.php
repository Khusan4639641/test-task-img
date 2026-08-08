<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageAsset> */
final class ImageAssetFactory extends Factory
{
    protected $model = ImageAsset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sha256' => hash('sha256', fake()->uuid()),
            'status' => ImageStatus::Pending,
            'original_mime' => 'image/png',
            'original_size' => 1024,
            'original_width' => 32,
            'original_height' => 32,
            'temporary_path' => 'images/tmp/'.fake()->uuid().'.source',
        ];
    }
}
