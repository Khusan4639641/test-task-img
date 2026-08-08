<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageStatus;
use Database\Factories\ImageAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ImageAsset extends Model
{
    /** @use HasFactory<ImageAssetFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<ImageUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(ImageUpload::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ImageStatus::class,
            'original_size' => 'integer',
            'original_width' => 'integer',
            'original_height' => 'integer',
            'processed_size' => 'integer',
            'processed_width' => 'integer',
            'processed_height' => 'integer',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'orphaned_at' => 'immutable_datetime',
        ];
    }
}
