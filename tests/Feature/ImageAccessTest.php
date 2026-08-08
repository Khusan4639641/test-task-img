<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Images\ProcessImageAssetAction;
use App\Enums\ImageStatus;
use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ImageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_list_contains_only_the_authenticated_users_uploads(): void
    {
        $user = User::factory()->create();
        $ownUpload = ImageUpload::factory()->for($user)->create();
        $foreignUpload = ImageUpload::factory()->create();

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownUpload->id)
            ->assertJsonMissing(['id' => $foreignUpload->id])
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_foreign_upload_is_hidden_for_download_and_delete(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $upload = ImageUpload::factory()->for($owner)->create();
        Sanctum::actingAs($stranger, ['*']);

        $this->getJson('/api/images/'.$upload->id)->assertNotFound();
        $this->deleteJson('/api/images/'.$upload->id)->assertNotFound();
        $this->assertDatabaseHas('image_uploads', ['id' => $upload->id]);
    }

    public function test_ready_image_can_be_downloaded_with_private_cache_headers(): void
    {
        $user = User::factory()->create();
        $contents = 'processed-webp-content';
        $asset = $this->createReadyAsset($contents);
        $upload = ImageUpload::factory()->for($user)->for($asset, 'asset')->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->get('/api/images/'.$upload->id);

        $response->assertOk()
            ->assertHeader('content-type', 'image/webp')
            ->assertHeader('content-length', (string) strlen($contents))
            ->assertHeader('etag', '"'.hash('sha256', $contents).'"');
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_pending_image_cannot_be_downloaded(): void
    {
        $user = User::factory()->create();
        $upload = ImageUpload::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/images/'.$upload->id)
            ->assertConflict()
            ->assertJsonPath('code', 'image_not_ready')
            ->assertJsonPath('status', 'pending');
    }

    public function test_deleting_a_reference_keeps_shared_asset_until_the_last_reference(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $path = 'images/assets/aa/bb/shared.webp';
        $contents = 'shared-webp';
        Storage::disk('local')->put($path, $contents);
        $asset = ImageAsset::factory()->create([
            'status' => ImageStatus::Ready,
            'temporary_path' => null,
            'storage_path' => $path,
            'processed_mime' => 'image/webp',
            'processed_size' => strlen($contents),
            'processed_width' => 32,
            'processed_height' => 32,
            'processed_sha256' => hash('sha256', $contents),
            'processed_at' => now(),
        ]);
        $firstUpload = ImageUpload::factory()->for($firstUser)->for($asset, 'asset')->create();
        $secondUpload = ImageUpload::factory()->for($secondUser)->for($asset, 'asset')->create();

        Sanctum::actingAs($firstUser, ['*']);
        $this->deleteJson('/api/images/'.$firstUpload->id)->assertNoContent();
        $this->assertDatabaseHas('image_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertExists($path);

        Sanctum::actingAs($secondUser, ['*']);
        $this->deleteJson('/api/images/'.$secondUpload->id)->assertNoContent();
        $asset->refresh();
        $this->assertNotNull($asset->orphaned_at);
        Storage::disk('local')->assertExists($path);

        Carbon::setTestNow(now()->addHours(25));

        try {
            $this->artisan('images:cleanup')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseMissing('image_assets', ['id' => $asset->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_during_processing_is_finalized_then_removed_by_delayed_cleanup(): void
    {
        $file = UploadedFile::fake()->image('processing.png', 40, 30);
        $contents = file_get_contents($file->getPathname());
        $sourcePath = 'images/tmp/processing.source';
        Storage::disk('local')->put($sourcePath, $contents);
        $asset = ImageAsset::factory()->create([
            'sha256' => hash('sha256', $contents),
            'status' => ImageStatus::Processing,
            'original_size' => strlen($contents),
            'original_width' => 40,
            'original_height' => 30,
            'temporary_path' => $sourcePath,
            'processing_started_at' => now(),
        ]);
        $user = User::factory()->create();
        $upload = ImageUpload::factory()->for($user)->for($asset, 'asset')->create();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson('/api/images/'.$upload->id)->assertNoContent();
        $asset->refresh();
        $this->assertNotNull($asset->orphaned_at);

        app(ProcessImageAssetAction::class)->execute($asset->id);
        $asset->refresh();
        $this->assertSame(ImageStatus::Ready, $asset->status);
        Storage::disk('local')->assertMissing($sourcePath);
        Storage::disk('local')->assertExists($asset->storage_path);

        Carbon::setTestNow(now()->addHours(25));

        try {
            $this->artisan('images:cleanup')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseMissing('image_assets', ['id' => $asset->id]);
    }

    private function createReadyAsset(string $contents): ImageAsset
    {
        $sha256 = hash('sha256', fake()->uuid());
        $path = sprintf('images/assets/%s/%s/%s.webp', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
        Storage::disk('local')->put($path, $contents);

        return ImageAsset::factory()->create([
            'sha256' => $sha256,
            'status' => ImageStatus::Ready,
            'temporary_path' => null,
            'storage_path' => $path,
            'processed_mime' => 'image/webp',
            'processed_size' => strlen($contents),
            'processed_width' => 32,
            'processed_height' => 32,
            'processed_sha256' => hash('sha256', $contents),
            'processed_at' => now(),
        ]);
    }
}
