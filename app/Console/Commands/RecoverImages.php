<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImageStatus;
use App\Jobs\ProcessImageAsset;
use App\Models\ImageAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class RecoverImages extends Command
{
    protected $signature = 'images:recover
        {--dry-run : Report stale assets without changing them}
        {--minutes= : Minimum stale age; defaults to images.recovery_after_minutes}';

    protected $description = 'Idempotently recover stale pending and processing image assets';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minutes = $this->nonNegativeIntegerOption(
            'minutes',
            (int) config('images.recovery_after_minutes'),
        );

        if ($minutes === null) {
            $this->error('The --minutes option must be a non-negative integer.');

            return SymfonyCommand::INVALID;
        }

        $cutoff = now()->subMinutes($minutes);
        $disk = Storage::disk((string) config('images.disk'));
        $candidates = 0;
        $requeued = 0;
        $failed = 0;

        ImageAsset::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($pending) use ($cutoff): void {
                    $pending->where('status', ImageStatus::Pending)
                        ->where('updated_at', '<=', $cutoff);
                })->orWhere(function ($processing) use ($cutoff): void {
                    $processing->where('status', ImageStatus::Processing)
                        ->where(function ($timestamps) use ($cutoff): void {
                            $timestamps->where('processing_started_at', '<=', $cutoff)
                                ->orWhere(function ($missingStart) use ($cutoff): void {
                                    $missingStart->whereNull('processing_started_at')
                                        ->where('updated_at', '<=', $cutoff);
                                });
                        });
                });
            })
            ->orderBy('id')
            ->each(function (ImageAsset $candidate) use (
                $dryRun,
                $disk,
                $cutoff,
                &$candidates,
                &$requeued,
                &$failed,
            ): void {
                $candidates++;
                $this->line(($dryRun ? '[dry-run] ' : '').'stale image asset: '.$candidate->getKey());

                if ($dryRun) {
                    return;
                }

                $result = DB::transaction(function () use ($candidate, $disk, $cutoff): string {
                    $asset = ImageAsset::query()->lockForUpdate()->find($candidate->getKey());

                    if ($asset === null || ! $this->isStale($asset, $cutoff)) {
                        return 'skipped';
                    }

                    $temporaryPath = $asset->temporary_path;

                    if (! $asset->uploads()->exists()) {
                        $asset->update([
                            'status' => ImageStatus::Failed,
                            'temporary_path' => null,
                            'processing_started_at' => null,
                            'processed_at' => now(),
                            'failure_reason' => 'Processing was abandoned after the final user reference was deleted.',
                            'orphaned_at' => $asset->orphaned_at ?? now(),
                        ]);

                        if (is_string($temporaryPath)) {
                            $disk->delete($temporaryPath);
                        }

                        return 'failed';
                    }

                    if (! is_string($temporaryPath) || ! $disk->exists($temporaryPath)) {
                        $asset->update([
                            'status' => ImageStatus::Failed,
                            'temporary_path' => null,
                            'processing_started_at' => null,
                            'processed_at' => now(),
                            'failure_reason' => 'Recovery failed because the temporary source image is missing.',
                        ]);

                        return 'failed';
                    }

                    $asset->update([
                        'status' => ImageStatus::Pending,
                        'processing_started_at' => null,
                        'processed_at' => null,
                        'failure_reason' => null,
                    ]);

                    ProcessImageAsset::dispatch((string) $asset->getKey())->afterCommit();

                    return 'requeued';
                }, 3);

                if ($result === 'requeued') {
                    $requeued++;
                } elseif ($result === 'failed') {
                    $failed++;
                }
            });

        $this->info(sprintf(
            '%s: %d candidates, %d requeued, %d marked failed.',
            $dryRun ? 'Candidates' : 'Recovered',
            $candidates,
            $requeued,
            $failed,
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function isStale(ImageAsset $asset, Carbon $cutoff): bool
    {
        if ($asset->status === ImageStatus::Pending) {
            return $asset->updated_at->lessThanOrEqualTo($cutoff);
        }

        if ($asset->status !== ImageStatus::Processing) {
            return false;
        }

        return ($asset->processing_started_at ?? $asset->updated_at)->lessThanOrEqualTo($cutoff);
    }

    private function nonNegativeIntegerOption(string $name, int $default): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]) ?: ($value === '0' ? 0 : null);
    }
}
