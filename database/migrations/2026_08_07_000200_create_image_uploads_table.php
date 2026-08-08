<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('image_asset_id')->constrained('image_assets')->restrictOnDelete();
            $table->string('original_filename');
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['image_asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_uploads');
    }
};
