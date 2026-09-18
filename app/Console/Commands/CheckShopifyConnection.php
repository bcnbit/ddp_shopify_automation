<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ShopifyInstallation;
use App\Services\Shopify\ShopifyConnectionChecker;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\Products\ProductReadiness;
use Illuminate\Console\Command;

/**
 * Comprueba la conexión con Shopify antes de intentar enviar nada (RFC-0004 / RFC-0009).
 *
 * Es deliberadamente barato y de sólo lectura: no crea ni modifica productos.
 * Sirve para separar «no tengo credenciales» de «el flujo está roto», que son
 * dos problemas distintos y se diagnostican de forma distinta.
 *
 * Desde RFC-0009 la vía principal es la pantalla «Conexión con Shopify», porque
 * la instalación se hace por OAuth y no tecleando un token. Este comando sigue
 * existiendo para soporte y para un despliegue sin navegador: informa del mismo
 * estado sin abrir el panel, y **nunca** imprime una credencial.
 */
class CheckShopifyConnection extends Command
{
    protected $signature = 'shopify:check';

    protected $description = 'Comprueba la configuración y la conexión con Shopify sin modificar nada';

    public function handle(ShopifyConnectionChecker $checker): int
    {
        $oauth = app(ShopifyOAuthService::class);
        $installation = ShopifyInstallation::current();
        $version = (string) config('product-studio.shopify.api_version');

        $this->newLine();
        $this->line('  Configuración');
        $this->line('  -------------');
        $this->line('  API key    : '.($oauth->clientId() !== '' ? 'configurada' : '(vacía)'));
        $this->line('  API secret : '.($oauth->clientSecret() !== '' ? 'configurada' : '(vacía)'));
        $this->line('  Versión    : '.$version);

        if (! $oauth->isConfigured()) {
            $this->newLine();
            $this->error('  La aplicación de Shopify no está configurada.');
            $this->newLine();
            $this->line('  Rellena estas claves en .env (nunca las subas a Git) y vuelve a ejecutar:');
            $this->line('    SHOPIFY_API_KEY=...          (client ID de la app)');
            $this->line('    SHOPIFY_API_SECRET=...       (API secret key, empieza por shpss_)');
            $this->line('    SHOPIFY_SHOP_DOMAIN=tu-tienda.myshopify.com');
            $this->newLine();
            $this->line('  La API secret key NO es un access token: no se envía en');
            $this->line('  X-Shopify-Access-Token. El token lo entrega Shopify al instalar la');
            $this->line('  aplicación por OAuth y se guarda cifrado en la base de datos.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  Instalación: '.($installation === null ? '(ninguna)' : $installation->shop_domain));

        if ($installation === null) {
            $this->newLine();
            $this->error('  No hay ninguna tienda conectada.');
            $this->newLine();
            $this->line('  Abre el panel y pulsa «Conectar con Shopify»:');
            $this->line('    '.route('filament.admin.pages.shopify-connection'));
            $this->newLine();

            return self::FAILURE;
        }

        if (! $installation->hasUsableToken()) {
            $this->newLine();
            $this->error('  La instalación no tiene un access token utilizable.');
            $this->line('  Vuelve a instalar la aplicación desde el panel.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($installation->hasExpiredToken()) {
            $this->warn('  El token instalado ha caducado: hay que reinstalar la aplicación.');
        }

        $this->newLine();
        $this->line('  Conexión (sólo lectura)');
        $this->line('  -----------------------');

        $check = $checker->check($installation);

        foreach ($check->asRows() as $row) {
            $mark = $row['ok'] ? 'OK  ' : 'FALLO';
            $this->line('  ['.$mark.'] '.$row['label'].': '.$row['detail']);
        }

        if (! $check->passed) {
            $this->newLine();
            $this->error('  La conexión no ha pasado la comprobación.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Conexión correcta.');
        $this->line('  El token es válido, los permisos están concedidos y se pueden leer productos.');

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
