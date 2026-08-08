<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Images\ProcessImageAssetAction;
use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProcessImageAsset implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $assetId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('image-asset:'.$this->assetId))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function handle(ProcessImageAssetAction $action): void
    {
        $action->execute($this->assetId);
    }

    public function failed(?Throwable $exception): void
    {
        $temporaryPath = DB::transaction(function () use ($exception): ?string {
            $asset = ImageAsset::query()->lockForUpdate()->find($this->assetId);

            if ($asset === null || $asset->status === ImageStatus::Ready) {
                return null;
            }

            $reason = $exception === null
                ? 'Image processing failed without an exception.'
                : $exception::class.': '.$exception->getMessage();

            $temporaryPath = $asset->temporary_path;

            $asset->update([
                'status' => ImageStatus::Failed,
                'failure_reason' => Str::limit($reason, 2000),
                'processed_at' => now(),
                'processing_started_at' => null,
                'temporary_path' => null,
            ]);

            return $temporaryPath;
        });

        if (is_string($temporaryPath)) {
            Storage::disk((string) config('images.disk'))->delete($temporaryPath);
        }
    }
}
