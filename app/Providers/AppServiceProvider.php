<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Ai\AiClient;
use App\Contracts\Shopify\ShopifyProductGateway;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\ProductMedia;
use App\Models\ProductTechnicalSheet;
use App\Models\ProductVariant;
use App\Models\ShopifyInstallation;
use App\Models\SyncAttempt;
use App\Models\TechnicalSheetCare;
use App\Models\TechnicalSheetComposition;
use App\Models\TechnicalSheetFit;
use App\Models\TechnicalSheetSizeGuide;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\ProductContentPolicy;
use App\Policies\ProductMediaPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductTechnicalSheetPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\ShopifyInstallationPolicy;
use App\Policies\SyncAttemptPolicy;
use App\Policies\TechnicalSheetEntryPolicy;
use App\Policies\UserPolicy;
use App\Services\Ai\NullAiClient;
use App\Services\Ai\OpenRouterClient;
use App\Services\Shopify\ShopifyProductGatewayImpl;
use App\Support\Audit\ActivityRecorder;
use App\Support\Security\HtmlSanitizer;
use App\Support\Security\SecretRedactor;
use App\Support\Security\TechnicalSheetHtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(TechnicalSheetHtmlSanitizer::class);
        $this->app->singleton(SecretRedactor::class);
        $this->app->singleton(ActivityRecorder::class);

        $this->registerAiClient();
        $this->registerShopifyGateway();
    }

    /**
     * El conector con Shopify vive detrás de un contrato (RFC-0004).
     *
     * Ninguna pantalla llama a la API directamente: el dominio depende de
     * `ShopifyProductGateway`, de modo que un cambio de versión de la API se
     * resuelve en la implementación sin tocar el flujo de la aplicación.
     */
    private function registerShopifyGateway(): void
    {
        $this->app->singleton(ShopifyProductGateway::class, ShopifyProductGatewayImpl::class);
    }

    /**
     * El proveedor de IA se elige por configuración (RFC-0003).
     *
     * El dominio depende de `AiClient`, nunca de OpenRouter: cambiar de
     * proveedor es añadir un driver, no tocar el flujo de generación.
     */
    private function registerAiClient(): void
    {
        $this->app->singleton(AiClient::class, function (): AiClient {
            return match (config('product-studio.ai.driver')) {
                'openrouter' => $this->app->make(OpenRouterClient::class),
                default => $this->app->make(NullAiClient::class),
            };
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePolicies();
        $this->configureLogging();
        $this->configureUrls();
    }

    /**
     * Un atributo desconocido debe fallar en desarrollo en lugar de guardarse
     * en silencio, y nunca se deben cargar relaciones de forma implícita.
     */
    private function configureModels(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Las Policies son la única fuente de verdad de autorización (RFC-0001).
     */
    private function configurePolicies(): void
    {
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
        Gate::policy(ProductMedia::class, ProductMediaPolicy::class);
        Gate::policy(ProductContent::class, ProductContentPolicy::class);
        Gate::policy(SyncAttempt::class, SyncAttemptPolicy::class);

        // Los cuatro mantenimientos comparten Policy porque comparten cabecera y
        // reparto de permisos (RFC-0008). Se registran uno a uno: una Policy
        // sobre la clase abstracta no se aplicaría a los modelos concretos.
        Gate::policy(TechnicalSheetComposition::class, TechnicalSheetEntryPolicy::class);
        Gate::policy(TechnicalSheetFit::class, TechnicalSheetEntryPolicy::class);
        Gate::policy(TechnicalSheetCare::class, TechnicalSheetEntryPolicy::class);
        Gate::policy(TechnicalSheetSizeGuide::class, TechnicalSheetEntryPolicy::class);
        Gate::policy(ProductTechnicalSheet::class, ProductTechnicalSheetPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
        // La conexión con Shopify es configuración: la misma Policy cubre ver la
        // pantalla y gestionarla (RFC-0009 §10).
        Gate::policy(ShopifyInstallation::class, ShopifyInstallationPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }

    /**
     * La redacción de secretos en logs se declara por canal en
     * `config/logging.php` mediante SecretRedactingProcessor (RFC-0001).
     *
     * Si se añade un canal nuevo, debe incluir ese procesador para que sus
     * entradas también queden libres de credenciales.
     */
    private function configureLogging(): void
    {
        // Intencionadamente vacío: ver config/logging.php.
    }

    private function configureUrls(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
