<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageUpload> */
final class ImageUploadFactory extends Factory
{
    protected $model = ImageUpload::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_asset_id' => ImageAsset::factory(),
            'original_filename' => fake()->word().'.png',
        ];
    }
}
