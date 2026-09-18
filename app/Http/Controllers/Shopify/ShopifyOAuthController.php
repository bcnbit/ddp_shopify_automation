<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopify;

use App\Enums\ActivityEvent;
use App\Exceptions\Shopify\ShopifyOAuthFailed;
use App\Services\Shopify\ShopifyConnectionChecker;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\Audit\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Instalación de la aplicación en Shopify por OAuth (RFC-0009 §6).
 *
 * Dos rutas con protecciones distintas y deliberadas:
 *
 * - `install` la pulsa una persona desde el panel, así que exige sesión y permiso
 *   `settings.manage` (lo comprueba quien registra la ruta).
 * - `callback` lo invoca **Shopify**, no el navegador con sesión: exigirle el
 *   middleware del panel lo rompería. Se protege con `state` + `hmac`, que es el
 *   mecanismo que Shopify prescribe para una petición que no puede llevar sesión.
 *
 * Ninguna de las dos devuelve jamás el token, la client secret ni la `shpss_`: la
 * respuesta es siempre una redirección a la pantalla de conexión.
 */
class ShopifyOAuthController
{
    private const STATE_SESSION_KEY = 'shopify.oauth.state';

    public function __construct(
        private readonly ShopifyOAuthService $oauth,
        private readonly ShopifyConnectionChecker $checker,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Paso 1: enviar a la persona a la pantalla de autorización de Shopify.
     *
     * El `state` es aleatorio y se guarda en la sesión para poder compararlo al
     * volver. Sin esa comparación, cualquiera podría provocar una instalación
     * haciendo que el navegador de otra persona llamase al callback.
     */
    public function install(Request $request): RedirectResponse
    {
        $domain = $this->oauth->suggestedShopDomain();

        if ($domain === '') {
            return $this->back('Falta indicar la tienda. Define SHOPIFY_SHOP_DOMAIN o conecta una tienda ya instalada.');
        }

        $state = Str::random(40);

        $request->session()->put(self::STATE_SESSION_KEY, $state);

        try {
            $url = $this->oauth->authorizationUrl($domain, $state);
        } catch (ShopifyOAuthFailed $failure) {
            return $this->back($failure->getMessage());
        }

        $this->recorder->record(
            event: ActivityEvent::ShopifyConnected,
            description: 'Se ha iniciado la instalación de la aplicación en Shopify.',
            properties: ['shop_domain' => $domain],
            actor: $request->user(),
        );

        return redirect()->away($url);
    }

    /**
     * Pasos 3 a 5: validar la vuelta de Shopify, canjear el código y guardar.
     *
     * Se valida **todo** antes de canjear nada. En particular el `hmac`, que es lo
     * único que demuestra que la respuesta la ha emitido Shopify y no un tercero
     * que conoce la URL de callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);

        // El callback no puede depender de la sesión para funcionar, pero el
        // `state` sí: sin él no hay nada contra lo que comparar.
        if (! is_string($expectedState) || $expectedState === '') {
            return $this->back('La sesión ha caducado antes de terminar la instalación. Vuelve a empezar la conexión desde el panel.');
        }

        $query = $request->query();

        // `verifyCallback()` es la **única** fuente de la validación: comprueba el
        // `state`, la firma `hmac` y el dominio, en ese orden y antes de canjear
        // nada. Repetir aquí alguna de esas comprobaciones duplicaría la regla —y
        // una regla duplicada se puede arreglar en un sitio y dejar rota en el otro—.
        $code = $query['code'] ?? null;
        $shop = $query['shop'] ?? null;

        if (! is_string($code) || $code === '' || ! is_string($shop) || $shop === '') {
            return $this->back('Shopify no ha devuelto el código de instalación. Vuelve a empezar la conexión.');
        }

        try {
            $domain = $this->oauth->verifyCallback($query, $expectedState);
            $granted = $this->oauth->exchangeCodeForToken($domain, $code);
        } catch (ShopifyOAuthFailed $failure) {
            return $this->back($failure->getMessage());
        }

        $installation = $this->oauth->store($domain, $granted);

        // Se comprueba de inmediato: si el token no sirve o faltan permisos, es
        // mejor saberlo aquí que en el primer envío de una ficha.
        $check = $this->checker->check($installation);

        $this->recorder->record(
            event: ActivityEvent::ShopifyConnected,
            subject: $installation,
            description: 'Se ha instalado la aplicación en Shopify.',
            properties: [
                'shop_domain' => $installation->shop_domain,
                'scopes' => $installation->grantedScopes(),
                'check_passed' => $check->passed,
            ],
            actor: $request->user(),
        );

        return $this->back(
            $check->passed
                ? 'Conexión establecida y comprobada correctamente.'
                : 'La aplicación se ha instalado, pero la comprobación ha encontrado un problema.',
            success: $check->passed,
            rows: $check->asRows(),
        );
    }

    /**
     * Vuelve a la pantalla de conexión con el resultado.
     *
     * Nunca redirige a Shopify ni incluye credenciales: sólo un mensaje y, si la
     * hay, el detalle de la comprobación.
     *
     * @param  list<array{label: string, ok: bool, detail: string}>  $rows
     */
    private function back(string $message, bool $success = false, array $rows = []): RedirectResponse
    {
        $key = $success ? 'shopify_connection_ok' : 'shopify_connection_error';

        return redirect()
            ->route('filament.admin.pages.shopify-connection')
            ->with($key, $message)
            ->with('shopify_connection_rows', $rows);
    }
}
