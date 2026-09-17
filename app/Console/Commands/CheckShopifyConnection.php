<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Shopify\ShopifyProductGateway;
use App\Exceptions\Shopify\ShopifyRequestFailed;
use App\Models\Product;
use App\Support\Products\ProductReadiness;
use Illuminate\Console\Command;

/**
 * Comprueba la conexión con Shopify antes de intentar enviar nada (RFC-0004).
 *
 * Es deliberadamente barato y de sólo lectura: no crea ni modifica productos.
 * Sirve para separar «no tengo credenciales» de «el flujo está roto», que son
 * dos problemas distintos y se diagnostican de forma distinta.
 */
class CheckShopifyConnection extends Command
{
    protected $signature = 'shopify:check';

    protected $description = 'Comprueba la configuración y la conexión con Shopify sin modificar nada';

    public function handle(ShopifyProductGateway $gateway): int
    {
        $domain = (string) config('product-studio.shopify.shop_domain');
        $version = (string) config('product-studio.shopify.api_version');

        $this->newLine();
        $this->line('  Configuración');
        $this->line('  -------------');
        $this->line('  Dominio : '.($domain !== '' ? $domain : '(vacío)'));
        $this->line('  Versión : '.$version);
        $this->line('  Token   : '.(config('product-studio.shopify.access_token') ? 'configurado' : '(vacío)'));

        if (! $gateway->isConfigured()) {
            $this->newLine();
            $this->error('  Shopify no está configurado.');

            $this->newLine();
            $this->line('  Rellena estas dos claves en .env y vuelve a ejecutar:');
            $this->line('    SHOPIFY_SHOP_DOMAIN=tu-tienda.myshopify.com');
            $this->line('    SHOPIFY_ACCESS_TOKEN=shpat_...');
            $this->newLine();
            $this->line('  El token sale de una app personalizada en Shopify con permisos de');
            $this->line('  lectura y escritura de productos y archivos. No se versiona.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Conexión');
        $this->line('  --------');

        try {
            // Una búsqueda que no debería encontrar nada: valida token, permisos
            // y versión de API sin tocar el catálogo.
            $gateway->findByStudioId('__comprobacion_de_conexion__');
        } catch (ShopifyRequestFailed $failure) {
            $this->error('  Fallo: '.$failure->userMessage());
            $this->line('  Código: '.($failure->errorCode ?? '—'));

            if ($failure->isRetryable) {
                $this->line('  Es un error transitorio: puede funcionar si lo repites.');
            }

            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  Conexión correcta.');
        $this->line('  El token es válido y la versión de API responde.');

        // Fichas que hoy podrían enviarse, para saber si hay trabajo pendiente.
        $ready = Product::query()
            ->whereIn('status', ['approved', 'shopify_draft', 'sync_failed', 'validation_failed'])
            ->get()
            ->filter(static fn (Product $product): bool => ProductReadiness::canSendToShopify($product))
            ->count();

        $this->newLine();
        $this->line("  Fichas listas para enviar: {$ready}");
        $this->newLine();

        return self::SUCCESS;
    }
}
