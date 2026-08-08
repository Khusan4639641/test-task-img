<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\UploadedImageMetadata;
use App\Rules\ValidUploadedImage;
use App\Services\UploadedImageInspector;
use Illuminate\Foundation\Http\FormRequest;

final class StoreImageRequest extends FormRequest
{
    private ValidUploadedImage $imageRule;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(UploadedImageInspector $inspector): array
    {
        $this->imageRule = new ValidUploadedImage($inspector);

        return [
            'image' => [
                'bail',
                'required',
                'file',
                'max:'.intdiv((int) config('images.max_bytes'), 1024),
                $this->imageRule,
            ],
        ];
    }

    public function metadata(): UploadedImageMetadata
    {
        return $this->imageRule->metadata();
    }
}
