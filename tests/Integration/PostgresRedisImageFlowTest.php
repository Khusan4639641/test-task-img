<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ImageAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @group integration */
final class PostgresRedisImageFlowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || config('queue.default') !== 'redis') {
            $this->markTestSkipped('Run with phpunit.integration.xml inside Docker.');
        }

        Storage::fake('local');
        Queue::connection('redis')->clear('integration');
    }

    protected function tearDown(): void
    {
        if (config('queue.default') === 'redis') {
            Queue::connection('redis')->clear('integration');
        }

        parent::tearDown();
    }

    public function test_register_upload_redis_queue_process_list_download_delete_flow(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('image_api_test', DB::scalar('select current_database()'));
        $this->assertContains(Redis::connection()->ping(), [true, 'PONG']);

        $register = $this->postJson('/api/register', [
            'name' => 'Integration User',
            'email' => 'integration@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated();
        $token = $register->json('data.token');

        $upload = $this->withToken($token)->postJson('/api/images', [
            'image' => UploadedFile::fake()->image('integration.png', 24, 18),
        ])->assertAccepted();
        $uploadId = $upload->json('data.id');

        $this->assertSame(1, Queue::connection('redis')->size('integration'));
        $queuedJob = Queue::connection('redis')->pop('integration');
        $this->assertNotNull($queuedJob);
        $queuedJob->fire();

        $this->assertSame('ready', ImageAsset::query()->sole()->status->value);
        $this->withToken($token)->getJson('/api/images')
            ->assertOk()
            ->assertJsonPath('data.0.id', $uploadId)
            ->assertJsonPath('data.0.status', 'ready');
        $this->withToken($token)->get('/api/images/'.$uploadId)
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
        $this->withToken($token)->deleteJson('/api/images/'.$uploadId)->assertNoContent();
        $this->assertDatabaseCount('image_assets', 1);
        $this->artisan('images:cleanup', ['--hours' => 0])->assertSuccessful();
        $this->assertDatabaseEmpty('image_assets');
    }

    public function test_postgres_ready_constraint_rejects_null_processed_fields(): void
    {
        $this->assertSame('image_api_test', DB::scalar('select current_database()'));
        $rejected = false;
        DB::beginTransaction();

        try {
            DB::table('image_assets')->insert([
                'id' => (string) Str::ulid(),
                'sha256' => hash('sha256', 'invalid-ready-asset'),
                'status' => 'ready',
                'original_mime' => 'image/png',
                'original_size' => 100,
                'original_width' => 10,
                'original_height' => 10,
                'storage_path' => 'images/assets/invalid.webp',
                'processed_mime' => null,
                'processed_size' => null,
                'processed_width' => null,
                'processed_height' => null,
                'processed_sha256' => null,
                'processed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        } finally {
            DB::rollBack();
        }

        $this->assertTrue($rejected, 'PostgreSQL must reject incomplete ready assets.');
    }
}
