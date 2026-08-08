<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Images\ProcessImageAssetAction;
use App\Enums\ImageStatus;
use App\Jobs\ProcessImageAsset;
use App\Models\ImageAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ProcessImageAssetJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_job_processes_image_and_is_idempotent_when_repeated(): void
    {
        $file = UploadedFile::fake()->image('source.png', 40, 30);
        $asset = $this->createAssetFromFile($file);
        $sourcePath = $asset->temporary_path;
        $job = new ProcessImageAsset($asset->id);

        $job->handle(app(ProcessImageAssetAction::class));

        $asset->refresh();
        $this->assertSame(ImageStatus::Ready, $asset->status);
        $this->assertSame('image/webp', $asset->processed_mime);
        $this->assertNull($asset->temporary_path);
        Storage::disk('local')->assertMissing($sourcePath);
        Storage::disk('local')->assertExists($asset->storage_path);
        $firstHash = $asset->processed_sha256;
        $firstPath = $asset->storage_path;

        $job->handle(app(ProcessImageAssetAction::class));

        $asset->refresh();
        $this->assertSame($firstHash, $asset->processed_sha256);
        $this->assertSame($firstPath, $asset->storage_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('images/assets'));
    }

    public function test_retryable_exception_keeps_source_for_the_next_attempt(): void
    {
        $path = 'images/tmp/corrupted.source';
        Storage::disk('local')->put($path, 'corrupted');
        $asset = ImageAsset::factory()->create(['temporary_path' => $path]);
        $job = new ProcessImageAsset($asset->id);

        $this->expectException(RuntimeException::class);

        try {
            $job->handle(app(ProcessImageAssetAction::class));
        } finally {
            $asset->refresh();
            $this->assertSame(ImageStatus::Processing, $asset->status);
            $this->assertSame($path, $asset->temporary_path);
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_terminal_failure_records_status_and_deletes_source(): void
    {
        $path = 'images/tmp/terminal-failure.source';
        Storage::disk('local')->put($path, 'corrupted');
        $asset = ImageAsset::factory()->create(['temporary_path' => $path]);
        $job = new ProcessImageAsset($asset->id);

        try {
            $job->handle(app(ProcessImageAssetAction::class));
            $this->fail('The processing action should have failed.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $asset->refresh();
        $this->assertSame(ImageStatus::Failed, $asset->status);
        $this->assertNotNull($asset->failure_reason);
        $this->assertNull($asset->temporary_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_job_uses_an_overlap_lock_longer_than_its_timeout(): void
    {
        $job = new ProcessImageAsset('01K00000000000000000000000');
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertGreaterThan($job->timeout, $middleware[0]->expiresAfter);
    }

    public function test_database_unique_index_is_the_final_race_condition_guard(): void
    {
        $asset = ImageAsset::factory()->create();

        $this->expectException(QueryException::class);

        ImageAsset::factory()->create(['sha256' => $asset->sha256]);
    }

    private function createAssetFromFile(UploadedFile $file): ImageAsset
    {
        $contents = file_get_contents($file->getPathname());
        $path = 'images/tmp/'.fake()->uuid().'.source';
        Storage::disk('local')->put($path, $contents);
        $mime = $file->getMimeType();
        $info = getimagesize($file->getPathname());

        return ImageAsset::factory()->create([
            'sha256' => hash('sha256', $contents),
            'status' => ImageStatus::Pending,
            'original_mime' => $mime,
            'original_size' => strlen($contents),
            'original_width' => $info[0],
            'original_height' => $info[1],
            'temporary_path' => $path,
        ]);
    }
}
