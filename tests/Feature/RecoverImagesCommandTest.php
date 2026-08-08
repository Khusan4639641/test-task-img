<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Jobs\ProcessImageAsset;
use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RecoverImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    public function test_recovery_dry_run_does_not_change_or_dispatch_stale_asset(): void
    {
        $asset = $this->staleAsset(ImageStatus::Pending);
        $updatedAt = $asset->updated_at;

        $this->artisan('images:recover', ['--dry-run' => true, '--minutes' => 15])
            ->expectsOutputToContain('[dry-run] stale image asset: '.$asset->id)
            ->assertSuccessful();

        $asset->refresh();
        $this->assertSame(ImageStatus::Pending, $asset->status);
        $this->assertTrue($asset->updated_at->equalTo($updatedAt));
        Queue::assertNothingPushed();
    }

    public function test_stale_processing_asset_is_requeued_once(): void
    {
        $asset = $this->staleAsset(ImageStatus::Processing);

        $this->artisan('images:recover', ['--minutes' => 15])->assertSuccessful();
        $asset->refresh();
        $this->assertSame(ImageStatus::Pending, $asset->status);
        $this->assertNull($asset->processing_started_at);
        Queue::assertPushed(ProcessImageAsset::class, 1);

        $this->artisan('images:recover', ['--minutes' => 15])->assertSuccessful();
        Queue::assertPushed(ProcessImageAsset::class, 1);
    }

    public function test_stale_orphan_is_failed_without_requeueing(): void
    {
        $path = 'images/tmp/stale-orphan.source';
        Storage::disk('local')->put($path, 'source');
        $asset = ImageAsset::factory()->create([
            'status' => ImageStatus::Processing,
            'temporary_path' => $path,
            'processing_started_at' => now()->subHour(),
            'orphaned_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('images:recover', ['--minutes' => 15])->assertSuccessful();

        $asset->refresh();
        $this->assertSame(ImageStatus::Failed, $asset->status);
        $this->assertNull($asset->temporary_path);
        Storage::disk('local')->assertMissing($path);
        Queue::assertNothingPushed();
    }

    private function staleAsset(ImageStatus $status): ImageAsset
    {
        $path = 'images/tmp/'.fake()->uuid().'.source';
        Storage::disk('local')->put($path, 'source');
        $asset = ImageAsset::factory()->create([
            'status' => $status,
            'temporary_path' => $path,
            'processing_started_at' => $status === ImageStatus::Processing ? now()->subHour() : null,
            'updated_at' => now()->subHour(),
        ]);
        ImageUpload::factory()->for(User::factory())->for($asset, 'asset')->create();

        return $asset;
    }
}
