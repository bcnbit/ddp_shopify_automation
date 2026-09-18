<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de perfiles de ajuste y tallaje reutilizables (RFC-0008).
 *
 * Cada perfil guarda un texto comercial o técnico ya aprobado («Unisex regular»,
 * «Mujer entallada», «Oversize», «Corte recto») que la ficha selecciona en lugar
 * de volver a escribirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_sheet_fits', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('product_type', 64)->nullable();
            $table->string('audience', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->text('content_text');

            $table->timestamps();

            // MySQL limita el nombre de un índice a 64 caracteres: el nombre
            // generado por convención lo superaba, así que se fija uno corto.
            $table->index(['is_active', 'product_type', 'audience'], 'technical_sheet_fits_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_sheet_fits');
    }
};
