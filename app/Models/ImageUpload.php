<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImageUploadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImageUpload extends Model
{
    /** @use HasFactory<ImageUploadFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ImageAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(ImageAsset::class, 'image_asset_id');
    }
}
