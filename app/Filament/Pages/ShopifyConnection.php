<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\ShopifyInstallation;
use App\Services\Shopify\ShopifyConnectionChecker;
use App\Services\Shopify\ShopifyOAuthService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pantalla de conexión con Shopify (RFC-0009 §10).
 *
 * Es el único sitio desde el que se instala la aplicación y se comprueba la
 * conexión. Sustituye al diagnóstico por consola de RFC-0004 como vía principal:
 * la persona que gestiona el catálogo no abre una terminal, pero sí necesita ver
 * si la tienda está conectada y con qué permisos.
 *
 * **Qué cruza al navegador y qué no.** Esta pantalla se renderiza en el servidor y
 * Filament la hidrata con Livewire, así que todo lo que sea propiedad pública de la
 * clase viaja al navegador. Por eso la página **no** expone el modelo de la
 * instalación como propiedad: `access_token` está en `$hidden`, pero la defensa no
 * es confiar en ese `$hidden`, sino no publicar el modelo. Lo que se muestra son
 * cadenas ya formateadas: dominio, handles de scope, fechas.
 *
 * El token, la client secret y la `shpss_` no aparecen aquí, ni siquiera
 * enmascarados: no hay motivo para mostrarlos y un valor enmascarado invita a
 * intentar revelarlo.
 */
class ShopifyConnection extends Page
{
    protected string $view = 'filament.pages.shopify-connection';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Conexión con Shopify';

    protected static ?string $title = 'Conexión con Shopify';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    /**
     * Sólo el administrador técnico tiene `settings.manage`.
     *
     * Conectar la tienda entrega una credencial de escritura sobre el catálogo: no
     * es una acción que deba poder hacer quien sólo prepara fichas. Ocultar el
     * menú no autoriza; esta comprobación es la barrera.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can(Permission::SettingsManage->value);
    }

    /**
     * Estado de la conexión, ya formateado para la vista.
     *
     * Se devuelven cadenas y no el modelo: nada de lo que aquí se construye
     * contiene una credencial.
     *
     * @return array<string, mixed>
     */
    public function connectionState(): array
    {
        $oauth = app(ShopifyOAuthService::class);
        $installation = ShopifyInstallation::current();

        return [
            'app_configured' => $oauth->isConfigured(),
            'api_key_configured' => $oauth->clientId() !== '',
            'api_secret_configured' => $oauth->clientSecret() !== '',
            'oauth_expiring' => (bool) config('product-studio.shopify.oauth_expiring', false),
            'installed' => $installation !== null,
            'shop_domain' => $installation?->shop_domain,
            'valid_domain' => $installation?->validDomain(),
            'token_is_usable' => $installation?->hasUsableToken() ?? false,
            'token_expired' => $installation?->hasExpiredToken() ?? false,
            'is_expiring' => $installation?->is_expiring ?? false,
            'granted_scopes' => $installation?->grantedScopes() ?? [],
            'missing_scopes' => $installation?->missingScopes() ?? [],
            'required_scopes' => ShopifyInstallation::REQUIRED_SCOPES,
            'installed_at' => $installation?->installed_at?->format('d/m/Y H:i'),
            'last_checked_at' => $installation?->last_checked_at?->format('d/m/Y H:i'),
            'last_check_error' => $installation?->last_check_error,
            'install_url' => route('filament.admin.shopify.oauth.install'),
            'can_install' => $oauth->suggestedShopDomain() !== '',
        ];
    }

    /**
     * Comprobación de sólo lectura bajo demanda.
     *
     * El resultado se muestra y, además, el servicio deja registrada la marca de
     * la última comprobación en la instalación.
     */
    public function checkConnection(): void
    {
        $installation = ShopifyInstallation::current();

        if ($installation === null) {
            Notification::make()
                ->title('No hay ninguna tienda conectada')
                ->body('Conecta primero la tienda con el botón de arriba.')
                ->warning()
                ->send();

            return;
        }

        $check = app(ShopifyConnectionChecker::class)->check($installation);

        $notification = Notification::make()
            ->title($check->passed ? 'Conexión correcta' : 'La comprobación ha encontrado problemas')
            ->body($check->passed
                ? 'El token es válido, los permisos están concedidos y se pueden leer productos.'
                : ($check->errorMessage ?? 'Revisa el detalle de las comprobaciones.'));

        ($check->passed ? $notification->success() : $notification->danger())->persistent()->send();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Comprobar conexión')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn (): bool => ShopifyInstallation::current() !== null)
                ->action('checkConnection'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'state' => $this->connectionState(),
            'result_rows' => (array) session('shopify_connection_rows', []),
        ];
    }
}
