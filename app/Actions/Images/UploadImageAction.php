<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Data\UploadedImageMetadata;
use App\Enums\ImageStatus;
use App\Jobs\ProcessImageAsset;
use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class UploadImageAction
{
    /** @return array{upload: ImageUpload, accepted: bool} */
    public function execute(
        User $user,
        UploadedFile $file,
        UploadedImageMetadata $metadata,
    ): array {
        $disk = Storage::disk((string) config('images.disk'));
        $temporaryPath = sprintf('%s/%s.source', config('images.temporary_directory'), Str::ulid());
        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the uploaded image stream.');
        }

        try {
            if (! $disk->writeStream($temporaryPath, $stream)) {
                throw new RuntimeException('Unable to persist the uploaded image.');
            }
        } finally {
            fclose($stream);
        }

        $incomingTemporaryIsUsed = false;
        $obsoleteTemporaryPath = null;

        try {
            $result = DB::transaction(function () use (
                $user,
                $file,
                $metadata,
                $temporaryPath,
                &$incomingTemporaryIsUsed,
                &$obsoleteTemporaryPath,
            ): array {
                $assetId = (string) Str::ulid();
                $now = now();
                $inserted = 0;
                $asset = null;

                for ($attempt = 0; $attempt < 3 && $asset === null; $attempt++) {
                    $inserted = DB::table('image_assets')->insertOrIgnore([
                        'id' => $assetId,
                        'sha256' => $metadata->sha256,
                        'status' => ImageStatus::Pending->value,
                        'original_mime' => $metadata->mimeType,
                        'original_size' => $metadata->size,
                        'original_width' => $metadata->width,
                        'original_height' => $metadata->height,
                        'temporary_path' => $temporaryPath,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $asset = ImageAsset::query()
                        ->where('sha256', $metadata->sha256)
                        ->lockForUpdate()
                        ->first();
                }

                if ($asset === null) {
                    throw new RuntimeException('Unable to acquire the image asset after deduplication.');
                }

                $shouldDispatch = $inserted === 1;

                if ($shouldDispatch) {
                    $incomingTemporaryIsUsed = true;
                } elseif ($asset->status === ImageStatus::Failed) {
                    $obsoleteTemporaryPath = $asset->temporary_path;
                    $incomingTemporaryIsUsed = true;
                    $shouldDispatch = true;
                    $asset->update([
                        'status' => ImageStatus::Pending,
                        'temporary_path' => $temporaryPath,
                        'storage_path' => null,
                        'processed_mime' => null,
                        'processed_size' => null,
                        'processed_width' => null,
                        'processed_height' => null,
                        'processed_sha256' => null,
                        'failure_reason' => null,
                        'processing_started_at' => null,
                        'processed_at' => null,
                    ]);
                }

                if ($asset->orphaned_at !== null) {
                    $asset->update(['orphaned_at' => null]);
                }

                $upload = ImageUpload::query()->create([
                    'user_id' => $user->getKey(),
                    'image_asset_id' => $asset->getKey(),
                    'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                ]);

                if ($shouldDispatch) {
                    ProcessImageAsset::dispatch((string) $asset->getKey())->afterCommit();
                }

                return [
                    'upload' => $upload->load('asset'),
                    'accepted' => $asset->fresh()->status !== ImageStatus::Ready,
                ];
            }, 3);
        } catch (Throwable $exception) {
            if (! ImageAsset::query()->where('temporary_path', $temporaryPath)->exists()) {
                $disk->delete($temporaryPath);
            }

            throw $exception;
        }

        if (! $incomingTemporaryIsUsed) {
            $disk->delete($temporaryPath);
        }

        if (is_string($obsoleteTemporaryPath) && $obsoleteTemporaryPath !== $temporaryPath) {
            $disk->delete($obsoleteTemporaryPath);
        }

        return $result;
    }
}
