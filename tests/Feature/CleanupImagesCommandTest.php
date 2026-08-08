<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CleanupImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_cleanup_dry_run_reports_but_does_not_delete_candidates_or_referenced_files(): void
    {
        Storage::disk('local')->put('images/tmp/orphan.source', 'orphan');
        Storage::disk('local')->put('images/tmp/referenced.source', 'referenced');
        $asset = ImageAsset::factory()->create([
            'status' => ImageStatus::Failed,
            'temporary_path' => 'images/tmp/referenced.source',
            'failure_reason' => 'Terminal failure.',
            'processed_at' => now()->subHours(2),
            'orphaned_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        Carbon::setTestNow(now()->addHour());

        try {
            $this->artisan('images:cleanup', ['--dry-run' => true, '--hours' => 0])
                ->expectsOutputToContain('[dry-run] stale temporary file: images/tmp/orphan.source')
                ->expectsOutputToContain('[dry-run] orphan database asset: '.$asset->id)
                ->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertExists('images/tmp/orphan.source');
        Storage::disk('local')->assertExists('images/tmp/referenced.source');
        $this->assertDatabaseHas('image_assets', ['id' => $asset->id]);
    }

    public function test_cleanup_rejects_invalid_hours(): void
    {
        $this->artisan('images:cleanup', ['--hours' => 'not-a-number'])
            ->expectsOutputToContain('must be a non-negative integer')
            ->assertFailed();
    }

    public function test_cleanup_never_deletes_a_referenced_asset_after_candidate_snapshot(): void
    {
        $path = 'images/assets/aa/bb/'.str_repeat('a', 64).'.webp';
        Storage::disk('local')->put($path, 'processed');
        $asset = ImageAsset::factory()->create([
            'status' => ImageStatus::Ready,
            'temporary_path' => null,
            'storage_path' => $path,
            'processed_mime' => 'image/webp',
            'processed_size' => 9,
            'processed_width' => 32,
            'processed_height' => 32,
            'processed_sha256' => hash('sha256', 'processed'),
            'processed_at' => now()->subDay(),
            'orphaned_at' => now()->subDay(),
        ]);
        ImageUpload::factory()->for(User::factory())->for($asset, 'asset')->create();

        $this->artisan('images:cleanup', ['--hours' => 0])->assertSuccessful();

        $this->assertDatabaseHas('image_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_cleanup_removes_unreferenced_temporary_residue(): void
    {
        Storage::disk('local')->put('images/tmp/failed-delete-residue.source', 'residue');
        Carbon::setTestNow(now()->addHour());

        try {
            $this->artisan('images:cleanup', ['--hours' => 0])->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertMissing('images/tmp/failed-delete-residue.source');
    }
}
