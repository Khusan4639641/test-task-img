<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Models\ImageAsset;
use App\Models\ImageUpload;
use Illuminate\Support\Facades\DB;

final class DeleteImageAction
{
    public function execute(ImageUpload $upload): void
    {
        DB::transaction(function () use ($upload): void {
            $lockedUpload = ImageUpload::query()->lockForUpdate()->findOrFail($upload->getKey());
            $asset = ImageAsset::query()->lockForUpdate()->findOrFail($lockedUpload->image_asset_id);
            $lockedUpload->delete();

            if (ImageUpload::query()->where('image_asset_id', $asset->getKey())->exists()) {
                return;
            }

            $asset->update([
                'orphaned_at' => $asset->orphaned_at ?? now(),
            ]);
        }, 3);
    }
}
