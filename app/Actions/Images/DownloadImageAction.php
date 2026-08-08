<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Enums\ImageStatus;
use App\Models\ImageUpload;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DownloadImageAction
{
    public function execute(ImageUpload $upload): Response
    {
        $asset = $upload->asset;

        if (in_array($asset->status, [ImageStatus::Pending, ImageStatus::Processing], true)) {
            return response()->json([
                'message' => 'The image is still being processed.',
                'code' => 'image_not_ready',
                'status' => $asset->status->value,
            ], Response::HTTP_CONFLICT);
        }

        if ($asset->status === ImageStatus::Failed) {
            return response()->json([
                'message' => 'Image processing failed.',
                'code' => 'image_processing_failed',
                'status' => $asset->status->value,
            ], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk((string) config('images.disk'));
        $path = $asset->storage_path;

        if (! is_string($path) || ! $disk->exists($path)) {
            return response()->json([
                'message' => 'The processed image file is unavailable.',
                'code' => 'image_file_missing',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = response()->file($disk->path($path), [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) $asset->processed_size,
            'ETag' => '"'.$asset->processed_sha256.'"',
            'Content-Disposition' => sprintf('inline; filename="image-%s.webp"', $upload->getKey()),
        ]);

        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
