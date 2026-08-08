<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ImageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $asset = $this->asset;

        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'original_mime' => $asset->original_mime,
            'original_size' => $asset->original_size,
            'sha256' => $asset->sha256,
            'status' => $asset->status->value,
            'dimensions' => [
                'width' => $asset->original_width,
                'height' => $asset->original_height,
            ],
            'processed' => $asset->status === ImageStatus::Ready ? [
                'mime' => $asset->processed_mime,
                'size' => $asset->processed_size,
                'width' => $asset->processed_width,
                'height' => $asset->processed_height,
            ] : null,
            'download_url' => $asset->status === ImageStatus::Ready
                ? route('images.show', ['image' => $this->id])
                : null,
            'failure_message' => $asset->status === ImageStatus::Failed
                ? 'Image processing failed.'
                : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
