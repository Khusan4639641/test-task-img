<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Jobs\ProcessImageAsset;
use App\Models\ImageAsset;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ExactSizePng;
use Tests\TestCase;

final class ImageUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_valid_png_upload_is_accepted(): void
    {
        $file = UploadedFile::fake()->image('sample.png', 32, 24);
        $expectedSize = $file->getSize();
        $expectedSha256 = hash_file('sha256', $file->getPathname());

        $this->postJson('/api/images', [
            'image' => $file,
        ])->assertAccepted()
            ->assertJsonPath('data.original_mime', 'image/png')
            ->assertJsonPath('data.original_size', $expectedSize)
            ->assertJsonPath('data.sha256', $expectedSha256)
            ->assertJsonPath('data.dimensions.width', 32)
            ->assertJsonPath('data.dimensions.height', 24)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('image_assets', 1);
        $this->assertDatabaseCount('image_uploads', 1);
        Queue::assertPushed(ProcessImageAsset::class, 1);
    }

    public function test_valid_jpeg_upload_is_accepted(): void
    {
        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->image('sample.jpg', 32, 24),
        ])->assertAccepted()
            ->assertJsonPath('data.original_mime', 'image/jpeg')
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ProcessImageAsset::class, 1);
    }

    public function test_unsupported_image_mime_is_rejected(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);

        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->createWithContent('sample.gif', $gif),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertDatabaseEmpty('image_assets');
    }

    public function test_actual_webp_content_is_rejected(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagewebp($image);
        $contents = ob_get_clean();
        unset($image);

        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->createWithContent('sample.webp', $contents),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_fake_png_extension_is_rejected_by_content(): void
    {
        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_corrupted_png_is_rejected(): void
    {
        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\ninvalid"),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_file_larger_than_five_mib_is_rejected(): void
    {
        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->create('too-large.png', 5121, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        Queue::assertNothingPushed();
    }

    public function test_exactly_five_mib_valid_png_is_accepted(): void
    {
        $path = ExactSizePng::create(5 * 1024 * 1024);

        try {
            $file = new UploadedFile($path, 'exactly-five-mib.png', 'image/png', null, true);

            $this->postJson('/api/images', ['image' => $file])
                ->assertAccepted()
                ->assertJsonPath('data.original_size', 5 * 1024 * 1024);
        } finally {
            unlink($path);
        }
    }

    public function test_five_mib_plus_one_byte_valid_png_is_rejected(): void
    {
        $path = ExactSizePng::create((5 * 1024 * 1024) + 1);

        try {
            $file = new UploadedFile($path, 'too-large.png', 'image/png', null, true);

            $this->postJson('/api/images', ['image' => $file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image');
        } finally {
            unlink($path);
        }
    }

    public function test_small_compressed_image_with_unsafe_pixel_count_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('dimension-bomb.png', 3000, 2500);

        $this->assertLessThan(5 * 1024 * 1024, $file->getSize());
        $this->postJson('/api/images', ['image' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_truncated_jpeg_is_rejected(): void
    {
        $jpeg = UploadedFile::fake()->image('source.jpg', 32, 24);
        $contents = file_get_contents($jpeg->getPathname());
        $truncated = substr($contents, 0, -10);

        $this->postJson('/api/images', [
            'image' => UploadedFile::fake()->createWithContent('truncated.jpg', $truncated),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_identical_uploads_are_deduplicated_but_keep_separate_references(): void
    {
        $first = UploadedFile::fake()->image('first.png', 20, 20);
        $contents = file_get_contents($first->getPathname());

        $this->postJson('/api/images', ['image' => $first])->assertAccepted();

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['*']);
        $second = UploadedFile::fake()->createWithContent('second.png', $contents);
        $this->postJson('/api/images', ['image' => $second])->assertAccepted();

        $this->assertSame(1, ImageAsset::query()->count());
        $this->assertSame(2, ImageUpload::query()->count());
        Queue::assertPushed(ProcessImageAsset::class, 1);
        $this->assertCount(2, ImageUpload::query()->distinct()->pluck('user_id'));
    }

    public function test_duplicate_of_ready_asset_returns_created_without_another_job(): void
    {
        $file = UploadedFile::fake()->image('ready-duplicate.png', 20, 20);
        $contents = file_get_contents($file->getPathname());
        $sha256 = hash('sha256', $contents);
        $storagePath = sprintf('images/assets/%s/%s/%s.webp', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
        Storage::disk('local')->put($storagePath, 'processed');
        ImageAsset::factory()->create([
            'sha256' => $sha256,
            'status' => ImageStatus::Ready,
            'original_mime' => 'image/png',
            'original_size' => strlen($contents),
            'original_width' => 20,
            'original_height' => 20,
            'temporary_path' => null,
            'storage_path' => $storagePath,
            'processed_mime' => 'image/webp',
            'processed_size' => 9,
            'processed_width' => 20,
            'processed_height' => 20,
            'processed_sha256' => hash('sha256', 'processed'),
            'processed_at' => now(),
            'orphaned_at' => now()->subDay(),
        ]);

        $this->postJson('/api/images', ['image' => $file])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseCount('image_assets', 1);
        $this->assertDatabaseCount('image_uploads', 1);
        Queue::assertNothingPushed();
        $this->assertNull(ImageAsset::query()->sole()->orphaned_at);
        $this->artisan('images:cleanup', ['--hours' => 0])->assertSuccessful();
        $this->assertDatabaseCount('image_assets', 1);
    }

    public function test_reupload_of_failed_asset_replaces_source_and_dispatches_processing(): void
    {
        $file = UploadedFile::fake()->image('retry.png', 20, 20);
        $contents = file_get_contents($file->getPathname());
        $asset = ImageAsset::factory()->create([
            'sha256' => hash('sha256', $contents),
            'status' => ImageStatus::Failed,
            'temporary_path' => null,
            'failure_reason' => 'Previous processing failed.',
            'processed_at' => now(),
        ]);

        $this->postJson('/api/images', ['image' => $file])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $asset->refresh();
        $this->assertSame(ImageStatus::Pending, $asset->status);
        $this->assertNull($asset->failure_reason);
        $this->assertNotNull($asset->temporary_path);
        Storage::disk('local')->assertExists($asset->temporary_path);
        Queue::assertPushed(ProcessImageAsset::class, 1);
    }
}
