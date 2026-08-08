<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use App\Services\ImageTransformer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class ProcessImageAssetAction
{
    public function __construct(private ImageTransformer $transformer) {}

    public function execute(string $assetId): void
    {
        $asset = DB::transaction(function () use ($assetId): ImageAsset {
            $asset = ImageAsset::query()->lockForUpdate()->findOrFail($assetId);

            if ($asset->status === ImageStatus::Ready) {
                return $asset;
            }

            $asset->update([
                'status' => ImageStatus::Processing,
                'processing_started_at' => now(),
                'failure_reason' => null,
            ]);

            return $asset->refresh();
        });

        if ($asset->status === ImageStatus::Ready) {
            return;
        }

        $disk = Storage::disk((string) config('images.disk'));
        $sourcePath = $asset->temporary_path;

        if (! is_string($sourcePath) || ! $disk->exists($sourcePath)) {
            throw new RuntimeException('The temporary source image is missing.');
        }

        $outputPath = sprintf('%s/%s.webp.processing', config('images.temporary_directory'), Str::ulid());
        $finalPath = sprintf(
            '%s/%s/%s/%s.webp',
            config('images.asset_directory'),
            substr($asset->sha256, 0, 2),
            substr($asset->sha256, 2, 2),
            $asset->sha256,
        );

        $disk->makeDirectory(dirname($outputPath));
        $disk->makeDirectory(dirname($finalPath));
        $finalWritten = false;

        try {
            $metadata = $this->transformer->transformToWebp(
                $disk->path($sourcePath),
                $disk->path($outputPath),
                $asset->original_mime,
                (int) config('images.webp_quality'),
            );

            if ($disk->exists($finalPath)) {
                $disk->delete($finalPath);
            }

            if (! $disk->move($outputPath, $finalPath)) {
                throw new RuntimeException('Unable to move the processed image into asset storage.');
            }

            $finalWritten = true;

            DB::transaction(function () use ($assetId, $finalPath, $metadata): void {
                $lockedAsset = ImageAsset::query()->lockForUpdate()->findOrFail($assetId);

                if ($lockedAsset->status === ImageStatus::Ready) {
                    return;
                }

                $hasReferences = $lockedAsset->uploads()->exists();

                $lockedAsset->update([
                    'status' => ImageStatus::Ready,
                    'storage_path' => $finalPath,
                    'processed_mime' => 'image/webp',
                    'processed_size' => $metadata->size,
                    'processed_width' => $metadata->width,
                    'processed_height' => $metadata->height,
                    'processed_sha256' => $metadata->sha256,
                    'processed_at' => now(),
                    'temporary_path' => null,
                    'failure_reason' => null,
                    'processing_started_at' => null,
                    'orphaned_at' => $hasReferences
                        ? null
                        : ($lockedAsset->orphaned_at ?? now()),
                ]);
            });

            $disk->delete($sourcePath);
        } catch (Throwable $exception) {
            $disk->delete($outputPath);

            if ($finalWritten) {
                $disk->delete($finalPath);
            }

            throw $exception;
        }
    }
}
