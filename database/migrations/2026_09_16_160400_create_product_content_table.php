<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contenido comercial por idioma y versión de propuesta (RFC-0001 / RFC-0003).
     *
     * `version` materializa la frase "una fila por idioma y versión de propuesta":
     * regenerar crea una versión nueva y no sobrescribe una propuesta aprobada.
     * `ai_model` y `prompt_version` dejan la trazabilidad que exige RFC-0003.
     */
    public function up(): void
    {
        Schema::create('product_content', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->char('locale', 5)->default('es');
            $table->unsignedInteger('version')->default(1);

            $table->string('title')->nullable();
            $table->string('short_benefit')->nullable();
            $table->string('handle')->nullable();
            $table->longText('html_description')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->json('tags_json')->nullable();
            $table->json('alt_texts_json')->nullable();
            $table->json('facts_detected_json')->nullable();
            $table->json('warnings_json')->nullable();

            $table->string('product_category_taxonomy_id')->nullable();
            $table->string('ai_model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['product_id', 'locale', 'version'], 'product_content_locale_version_unique');
            $table->index(['product_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_content');
    }
};
