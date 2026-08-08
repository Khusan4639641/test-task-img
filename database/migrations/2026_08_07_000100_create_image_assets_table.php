<?php

declare(strict_types=1);

use App\Enums\ImageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('sha256', 64)->unique();
            $table->enum('status', array_column(ImageStatus::cases(), 'value'))->index();
            $table->string('original_mime', 32);
            $table->unsignedBigInteger('original_size');
            $table->unsignedInteger('original_width');
            $table->unsignedInteger('original_height');
            $table->string('temporary_path')->nullable()->unique();
            $table->string('storage_path')->nullable()->unique();
            $table->string('processed_mime', 32)->nullable();
            $table->unsignedBigInteger('processed_size')->nullable();
            $table->unsignedInteger('processed_width')->nullable();
            $table->unsignedInteger('processed_height')->nullable();
            $table->char('processed_sha256', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('orphaned_at')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE image_assets ADD CONSTRAINT image_assets_original_size_positive CHECK (original_size > 0)');
            DB::statement('ALTER TABLE image_assets ADD CONSTRAINT image_assets_dimensions_positive CHECK (original_width > 0 AND original_height > 0)');
            DB::statement(<<<'SQL'
                ALTER TABLE image_assets
                ADD CONSTRAINT image_assets_processed_fields_consistent CHECK (
                    status <> 'ready'
                    OR (
                        storage_path IS NOT NULL
                        AND processed_mime IS NOT NULL
                        AND processed_mime = 'image/webp'
                        AND processed_size IS NOT NULL
                        AND processed_size > 0
                        AND processed_width IS NOT NULL
                        AND processed_width > 0
                        AND processed_height IS NOT NULL
                        AND processed_height > 0
                        AND processed_sha256 IS NOT NULL
                        AND processed_at IS NOT NULL
                    )
                )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_assets');
    }
};
