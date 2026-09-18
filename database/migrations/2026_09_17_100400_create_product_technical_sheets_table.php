<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia congelada de los mantenimientos usados por una ficha (RFC-0008).
 *
 * Es la pieza que garantiza que modificar un mantenimiento no altera fichas ya
 * creadas, aprobadas o sincronizadas: lo que se envía a Shopify sale de aquí,
 * nunca del maestro.
 *
 * `entry_id` es deliberadamente una columna sin clave ajena. El snapshot existe
 * para sobrevivir al mantenimiento; una clave ajena con nullOnDelete dejaría el
 * texto sin dueño si el maestro se borra, que es justo el caso que hay que
 * cubrir. El código, el nombre y la versión se copian para que la copia se pueda
 * leer sin consultar el maestro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_technical_sheets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('slot', 32);
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('entry_code', 64);
            $table->string('entry_name');
            $table->unsignedInteger('entry_version');

            $table->text('content_text')->nullable();
            $table->longText('content_html')->nullable();
            $table->text('intro_note')->nullable();
            $table->text('closing_note')->nullable();

            $table->timestamp('captured_at')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_technical_sheets');
    }
};
