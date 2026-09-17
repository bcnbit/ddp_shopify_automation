<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\Product;
use App\Models\User;
use App\Services\Shopify\ProductSyncService;
use App\Support\Products\ProductReadiness;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Envía una ficha a Shopify como borrador sin depender de la cola (RFC-0004).
 *
 * El panel encola el trabajo, que es lo correcto en producción. En local, sin un
 * worker corriendo, el trabajo se queda en la tabla `jobs` y la ficha parece
 * colgada en «Sincronizando». Este comando ejecuta la misma lógica de forma
 * síncrona, para poder enviar y ver el resultado en el momento.
 *
 * Usa exactamente el mismo servicio que el trabajo en cola: no es una vía
 * alternativa que pueda divergir, es la misma.
 */
class SyncProductToShopify extends Command
{
    protected $signature = 'shopify:sync
                            {reference : Referencia interna de la ficha (por ejemplo DDP-14666)}
                            {--dry-run : Muestra qué se enviaría sin llamar a Shopify}';

    protected $description = 'Envía una ficha a Shopify como borrador (síncrono, para local y soporte)';

    public function handle(ProductSyncService $service): int
    {
        $reference = (string) $this->argument('reference');

        $product = Product::query()
            ->where('internal_reference', mb_strtoupper($reference))
            ->first();

        if ($product === null) {
            $this->error("No existe ninguna ficha con la referencia «{$reference}».");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  Ficha: {$product->internal_reference} — {$product->source_name}");
        $this->line("  Estado: {$product->status->label()}");

        $validation = ProductReadiness::validation($product);

        if ($validation->fails()) {
            $this->newLine();
            $this->error('  Tiene errores bloqueantes y no se enviará:');

            foreach ($validation->blockingMessages() as $message) {
                $this->line('    · '.$message);
            }

            $this->newLine();

            return self::FAILURE;
        }

        // Que los datos estén bien no basta: el estado tiene que permitir enviar.
        // Sin esta comprobación, el `--dry-run` prometía un envío que la ejecución
        // real rechazaría, que es justo el tipo de discrepancia que este comando
        // existe para evitar.
        if (! ProductReadiness::canSendToShopify($product)) {
            $this->newLine();
            $this->error("  La ficha no puede enviarse desde el estado «{$product->status->label()}».");
            $this->newLine();
            $this->line('  Estados desde los que se puede enviar:');
            $this->line('    · Aprobada');

            if ($product->isSyncedWithShopify()) {
                $this->line('    · Borrador en Shopify  (ya tiene producto remoto)');
            }

            $this->newLine();
            $this->line('  Una ficha en revisión debe aprobarla antes un Responsable de catálogo.');
            $this->newLine();

            return self::FAILURE;
        }

        $warnings = $validation->warningMessages();

        if ($warnings !== []) {
            $this->newLine();
            $this->line('  Avisos (no impiden enviar):');

            foreach ($warnings as $message) {
                $this->line('    · '.$message);
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('  Simulación: no se ha llamado a Shopify.');

            if ($product->shopify_product_gid !== null) {
                $this->line("  Se actualizaría el producto {$product->shopify_product_gid}.");
            } else {
                $this->line('  Se crearía un producto nuevo, en estado borrador.');
            }

            $this->newLine();

            return self::SUCCESS;
        }

        $attempt = $service->request($product, $this->actor());

        $this->newLine();
        $this->line('  Enviando como borrador... (intento #'.$attempt->attempt_number.')');

        try {
            $service->sync($product->getKey());
        } catch (ShopifyRequestFailed $failure) {
            // El servicio ya ha dejado la ficha y el intento marcados; aquí sólo
            // se informa de forma legible.
            $this->newLine();
            $this->error('  '.$failure->userMessage());
            $this->line('  Referencia de soporte: '.($attempt->refresh()->support_reference ?? '—'));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->newLine();
            $this->error('  '.$exception->getMessage());

            return self::FAILURE;
        }

        $product->refresh();
        $attempt->refresh();

        if ($attempt->status->value !== 'succeeded') {
            $this->newLine();
            $this->error('  '.($attempt->error_message ?? 'La sincronización no se completó.'));
            $this->line('  Referencia de soporte: '.($attempt->support_reference ?? '—'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Listo. Producto creado en Shopify como BORRADOR.');
        $this->newLine();
        $this->line('  Estado local     : '.$product->status->label());
        $this->line('  Producto Shopify : '.$product->shopify_product_gid);
        $this->line('  Handle           : '.($product->shopify_handle ?? '—'));

        $numericId = $product->shopify_product_gid !== null && str_contains($product->shopify_product_gid, '/')
            ? substr($product->shopify_product_gid, strrpos($product->shopify_product_gid, '/') + 1)
            : null;

        $domain = (string) config('product-studio.shopify.shop_domain');

        if ($numericId !== null && $domain !== '') {
            $this->newLine();
            $this->line("  Revísalo en: https://{$domain}/admin/products/{$numericId}");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Actor del intento: quién lo pidió.
     *
     * En consola no hay sesión, así que se atribuye al primer administrador
     * técnico activo. Es preferible a dejarlo sin autor: así la auditoría
     * distingue «lo lanzó una persona» de «lo lanzó el sistema».
     */
    private function actor(): User
    {
        $admin = User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($query) => $query->where('name', 'admin_tecnico'))
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            throw new RuntimeException(
                'No hay ningún administrador técnico activo al que atribuir el envío. '
                .'Ejecuta `php artisan db:seed --class=AdminUserSeeder`.'
            );
        }

        return $admin;
    }
}
