<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renumera las posiciones de variante que quedaron empatadas.
 *
 * El modelo no asignaba `position` al crear, así que las variantes añadidas desde
 * el panel se quedaban todas con el valor por defecto (`0`). El listado de la ficha
 * ordena por esa columna, de modo que el orden que veía la persona era arbitrario y
 * podía cambiar entre visitas.
 *
 * El arreglo de raíz está en `ProductVariant::booted()`, que ahora encadena la
 * posición al crear. Esto repara **los datos que ya existen**.
 *
 * Sólo se tocan las fichas con posiciones repetidas: donde ya hay un orden
 * distinto para cada variante no se cambia nada, porque ese orden puede haberlo
 * decidido una persona arrastrando filas y hay que respetarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fichas con posiciones repetidas dentro del mismo producto.
        $productIds = DB::table('product_variants')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > COUNT(DISTINCT position)')
            ->pluck('product_id');

        foreach ($productIds as $productId) {
            $variantIds = DB::table('product_variants')
                ->where('product_id', $productId)
                // Se usa el id como orden de referencia: es el único que refleja
                // el orden real de creación cuando la posición no lo hacía.
                ->orderBy('id')
                ->pluck('id');

            foreach ($variantIds as $position => $variantId) {
                DB::table('product_variants')
                    ->where('id', $variantId)
                    ->update(['position' => $position]);
            }
        }
    }

    public function down(): void
    {
        // Sin reversión: volver a poner todas las posiciones repetidas a cero no
        // aporta nada y dejaría el listado peor de como estaba.
    }
};
