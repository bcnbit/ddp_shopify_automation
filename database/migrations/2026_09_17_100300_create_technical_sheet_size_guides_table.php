<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guías de tallas reutilizables (RFC-0008).
 *
 * Se separan de los otros mantenimientos porque su contenido no es un texto
 * aprobado sino una tabla: una nota introductoria opcional, el HTML de la tabla
 * y una nota final opcional. El HTML se sanitiza con la whitelist propia de
 * tablas al guardarse (cast del modelo), no aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_sheet_size_guides', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('product_type', 64)->nullable();
            $table->string('audience', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->text('intro_note')->nullable();
            $table->longText('content_html');
            $table->text('closing_note')->nullable();

            $table->timestamps();

            // MySQL limita el nombre de un índice a 64 caracteres: el nombre
            // generado por convención lo superaba, así que se fija uno corto.
            $table->index(['is_active', 'product_type', 'audience'], 'technical_sheet_size_guides_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_sheet_size_guides');
    }
};
