<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class CleanupImages extends Command
{
    protected $signature = 'images:cleanup
        {--dry-run : Report candidates without deleting them}
        {--hours= : Minimum age in hours; defaults to images.cleanup_after_hours}';

    protected $description = 'Safely remove orphaned image assets and stale temporary files';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = $this->nonNegativeIntegerOption('hours', (int) config('images.cleanup_after_hours'));

        if ($hours === null) {
            $this->error('The --hours option must be a non-negative integer.');

            return SymfonyCommand::INVALID;
        }

        $cutoff = now()->subHours($hours);
        $disk = Storage::disk((string) config('images.disk'));
        $deletedAssets = 0;
        $deletedTemporaryFiles = 0;
        $deletedOrphanFiles = 0;

        foreach ($disk->allFiles((string) config('images.temporary_directory')) as $path) {
            if (! $this->isOldEnough($disk->lastModified($path), $cutoff)
                || ImageAsset::query()->where('temporary_path', $path)->exists()) {
                continue;
            }

            $deletedTemporaryFiles++;
            $this->line(($dryRun ? '[dry-run] ' : '').'stale temporary file: '.$path);

            if (! $dryRun) {
                if (ImageAsset::query()->where('temporary_path', $path)->exists()) {
                    continue;
                }

                $disk->delete($path);
            }
        }

        ImageAsset::query()
            ->whereDoesntHave('uploads')
            ->whereNotNull('orphaned_at')
            ->where('orphaned_at', '<=', $cutoff)
            ->whereIn('status', [ImageStatus::Ready, ImageStatus::Failed])
            ->orderBy('id')
            ->each(function (ImageAsset $candidate) use ($dryRun, $disk, $cutoff, &$deletedAssets): void {
                $this->line(($dryRun ? '[dry-run] ' : '').'orphan database asset: '.$candidate->getKey());

                if ($dryRun) {
                    $deletedAssets++;

                    return;
                }

                $deleted = DB::transaction(function () use ($candidate, $cutoff, $disk): bool {
                    $asset = ImageAsset::query()->lockForUpdate()->find($candidate->getKey());

                    if ($asset === null
                        || $asset->orphaned_at === null
                        || $asset->orphaned_at->isAfter($cutoff)
                        || ! in_array($asset->status, [ImageStatus::Ready, ImageStatus::Failed], true)
                        || $asset->uploads()->exists()) {
                        return false;
                    }

                    $paths = array_values(array_filter([
                        $asset->temporary_path,
                        $asset->storage_path,
                    ], 'is_string'));

                    foreach ($paths as $path) {
                        if ($disk->exists($path) && ! $disk->delete($path)) {
                            throw new RuntimeException('Unable to delete orphan image file: '.$path);
                        }
                    }

                    $asset->delete();

                    return true;
                }, 3);

                if ($deleted) {
                    $deletedAssets++;
                }
            });

        foreach ($disk->allFiles((string) config('images.asset_directory')) as $path) {
            if (! $this->isOldEnough($disk->lastModified($path), $cutoff)
                || $this->assetPathIsReferenced($path)) {
                continue;
            }

            $deletedOrphanFiles++;
            $this->line(($dryRun ? '[dry-run] ' : '').'orphan asset file: '.$path);

            if (! $dryRun) {
                if ($this->assetPathIsReferenced($path)) {
                    continue;
                }

                $disk->delete($path);
            }
        }

        $this->info(sprintf(
            '%s: %d database assets, %d temporary files, %d orphan asset files.',
            $dryRun ? 'Candidates' : 'Deleted',
            $deletedAssets,
            $deletedTemporaryFiles,
            $deletedOrphanFiles,
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function isOldEnough(int $lastModified, Carbon $cutoff): bool
    {
        return $lastModified <= $cutoff->getTimestamp();
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

    private function assetPathIsReferenced(string $path): bool
    {
        $sha256 = preg_match('/\/([a-f0-9]{64})\.webp$/', $path, $matches) === 1
            ? $matches[1]
            : null;

        return ImageAsset::query()
            ->where(function ($query) use ($path, $sha256): void {
                $query->where('storage_path', $path);

                if ($sha256 !== null) {
                    $query->orWhere('sha256', $sha256);
                }
            })
            ->exists();
    }
}
