<?php

declare(strict_types=1);

namespace App\Rules;

use App\Data\UploadedImageMetadata;
use App\Exceptions\InvalidUploadedImage;
use App\Services\UploadedImageInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use LogicException;

final class ValidUploadedImage implements ValidationRule
{
    private ?UploadedImageMetadata $metadata = null;

    public function __construct(private readonly UploadedImageInspector $inspector) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The image must be an uploaded file.');

            return;
        }

        try {
            $this->metadata = $this->inspector->inspect($value->getPathname());
        } catch (InvalidUploadedImage $exception) {
            $fail($exception->getMessage());
        }
    }

    public function metadata(): UploadedImageMetadata
    {
        return $this->metadata ?? throw new LogicException('Image metadata is unavailable before successful validation.');
    }
}
