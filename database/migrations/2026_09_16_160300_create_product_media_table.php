<?php

declare(strict_types=1);

use App\Enums\MediaUploadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medio de producto (RFC-0001 / RFC-0005).
     *
     * Los originales nunca se borran: `disk` + `path` apuntan al original y los
     * derivados (miniatura, copia optimizada) se regeneran a partir de `sha256`.
     * El índice único (product_id, sha256) evita volver a subir el mismo archivo
     * a Shopify (RFC-0004) y detecta duplicados en la ficha (RFC-0005).
     */
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 32)->default('media');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->text('alt_text')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('shopify_media_gid')->nullable()->unique();
            $table->string('upload_status', 16)->default(MediaUploadStatus::Pending->value);

            $table->timestamps();

            $table->unique(['product_id', 'sha256'], 'product_media_product_checksum_unique');
            $table->index(['product_id', 'sort_order']);
            $table->index('upload_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
