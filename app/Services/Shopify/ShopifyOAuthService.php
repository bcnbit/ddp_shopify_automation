<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Exceptions\Shopify\ShopifyOAuthFailed;
use App\Models\ShopifyInstallation;
use App\Support\Security\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Instalación de la aplicación en Shopify por OAuth (RFC-0009).
 *
 * Sustituye al token pre-generado de RFC-0004. El flujo es el *authorization code
 * grant* que Shopify prescribe para una aplicación que corre fuera del admin de la
 * tienda:
 *
 * 1. `authorizationUrl()` construye la URL a la que se envía a la persona.
 * 2. Shopify redirige de vuelta con `code`, `hmac`, `state` y `shop`.
 * 3. `verifyCallback()` comprueba la procedencia **antes** de canjear nada.
 * 4. `exchangeCodeForToken()` canjea el código por el access token.
 * 5. `store()` guarda la instalación con el token cifrado.
 *
 * Detalles verificados contra la documentación vigente y que es fácil equivocar:
 *
 * - **Token offline**: se omiten `grant_options[]`. Añadirlos con `per-user`
 *   devolvería un token *online*, que caduca con la sesión del usuario.
 * - **Dominio**: la URL de autorización usa el dominio `*.myshopify.com` tal cual,
 *   y el intercambio va contra `https://{shop}/admin/oauth/access_token`.
 * - **HMAC**: se quita `hmac` del conjunto, se ordenan los parámetros
 *   alfabéticamente como `k=v`, se unen con `&` y se calcula HMAC-SHA256 en hex con
 *   la client secret, comparando en tiempo constante.
 *
 * Ninguna credencial se registra ni se devuelve: el token sólo aparece en el
 * objeto de la instalación, que lo cifra al guardarse.
 */
class ShopifyOAuthService
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * URL de autorización a la que se envía a la persona (paso 1).
     *
     * Se piden exactamente los scopes de `ShopifyInstallation::REQUIRED_SCOPES` y
     * se omite `grant_options[]` para obtener un token **offline**.
     *
     * @throws ShopifyOAuthFailed
     */
    public function authorizationUrl(string $shopDomain, string $state): string
    {
        if (! $this->isConfigured()) {
            throw ShopifyOAuthFailed::notConfigured();
        }

        $domain = $this->normalizeShopDomain($shopDomain);

        return 'https://'.$domain.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'scope' => implode(',', ShopifyInstallation::REQUIRED_SCOPES),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * Comprueba que la respuesta la ha enviado Shopify (paso 3).
     *
     * Los cuatro (`state`, `hmac`, dominio y `shop` coincidente) deben pasar. El
     * orden importa: se valida todo **antes** de canjear el código, para no
     * entregar un token a quien no lo ha pedido legítimamente.
     *
     * @param  array<string, mixed>  $query  parámetros de la petición de vuelta
     *
     * @throws ShopifyOAuthFailed
     */
    public function verifyCallback(array $query, ?string $expectedState): string
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $hmac = is_string($query['hmac'] ?? null) ? $query['hmac'] : '';
        $shop = is_string($query['shop'] ?? null) ? $query['shop'] : '';

        if ($expectedState === null || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            throw ShopifyOAuthFailed::invalidState();
        }

        if (! $this->hasValidHmac($query)) {
            throw ShopifyOAuthFailed::invalidHmac();
        }

        // Si hay un dominio esperado configurado, la instalación debe ser de esa
        // tienda: evita que un enlace preparado para otra tienda instale aquí.
        $expected = trim((string) config('product-studio.shopify.expected_shop_domain', ''));

        if ($expected !== '' && ! hash_equals($this->normalizeShopDomain($expected), $this->normalizeShopDomain($shop))) {
            throw ShopifyOAuthFailed::invalidShopDomain($shop);
        }

        return $this->normalizeShopDomain($shop);
    }

    /**
     * ¿La firma `hmac` corresponde a los parámetros con la client secret?
     *
     * @param  array<string, mixed>  $query
     */
    public function hasValidHmac(array $query): bool
    {
        if (! is_string($query['hmac'] ?? null) || $query['hmac'] === '') {
            return false;
        }

        $provided = $query['hmac'];
        $secret = $this->clientSecret();

        if ($secret === '') {
            return false;
        }

        $pairs = [];

        foreach ($query as $key => $value) {
            // El propio hmac queda fuera del cálculo, y los parámetros de array
            // no forman parte de la firma de una petición de instalación.
            if ($key === 'hmac' || ! is_scalar($value)) {
                continue;
            }

            $pairs[(string) $key] = (string) $value;
        }

        ksort($pairs, SORT_STRING);

        $parts = [];

        foreach ($pairs as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        $expected = hash_hmac('sha256', implode('&', $parts), $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Canjea el código por el access token (paso 4).
     *
     * `expiring` sólo se envía cuando se pide un token expirable. La documentación
     * indica que `expiring=1` funciona en cualquier aplicación, mientras que
     * `expiring=0` sólo está permitido en aplicaciones personalizadas; por eso el
     * valor por defecto es no enviar nada y quedarse con el token no expirable.
     *
     * @return array{access_token: string, scopes: list<string>, is_expiring: bool, expires_at: ?\DateTimeInterface}
     *
     * @throws ShopifyOAuthFailed
     */
    public function exchangeCodeForToken(string $shopDomain, string $code): array
    {
        if (! $this->isConfigured()) {
            throw ShopifyOAuthFailed::notConfigured();
        }

        $domain = $this->normalizeShopDomain($shopDomain);

        $payload = [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
        ];

        if ($this->wantsExpiringToken()) {
            $payload['expiring'] = '1';
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('product-studio.shopify.timeout', 30))
                ->connectTimeout((int) config('product-studio.shopify.connect_timeout', 10))
                ->post('https://'.$domain.'/admin/oauth/access_token', $payload);
        } catch (ConnectionException $exception) {
            throw ShopifyOAuthFailed::exchangeFailed('no se ha podido contactar con la tienda.');
        }

        if ($response->failed()) {
            // El cuerpo puede citar la credencial enviada: se redacta antes de
            // formar parte del mensaje de error (RFC-0009 §5.2).
            $detail = $this->redactor->redactString((string) $response->body()) ?? '';

            throw ShopifyOAuthFailed::exchangeFailed(
                $detail === '' ? 'la tienda ha rechazado la petición.' : Str::limit($detail, 200)
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ShopifyOAuthFailed::exchangeFailed('la respuesta no era legible.');
        }

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw ShopifyOAuthFailed::exchangeFailed('la respuesta no incluía ningún token.');
        }

        // Aquí es donde se detecta el error que motivó la RFC: una client secret
        // no es un access token, y decirlo ahora evita un 401 incomprensible.
        if (ShopifyInstallation::isClientSecret($token)) {
            throw ShopifyOAuthFailed::clientSecretIsNotAnAccessToken();
        }

        if (! ShopifyInstallation::isAccessToken($token)) {
            throw ShopifyOAuthFailed::unusableAccessToken();
        }

        return [
            'access_token' => $token,
            'scopes' => $this->scopesFrom($body['scope'] ?? null),
            'is_expiring' => $this->wantsExpiringToken(),
            'expires_at' => $this->expiresAtFrom($body),
        ];
    }

    /**
     * Guarda la instalación con el token cifrado (paso 5).
     *
     * Reinstalar **reemplaza** la instalación anterior en lugar de acumular filas:
     * la aplicación sirve a una sola tienda (RFC-0009 §4.4).
     *
     * @param  array{access_token: string, scopes: list<string>, is_expiring: bool, expires_at: ?\DateTimeInterface}  $granted
     */
    public function store(string $shopDomain, array $granted): ShopifyInstallation
    {
        $domain = $this->normalizeShopDomain($shopDomain);

        $installation = ShopifyInstallation::query()->where('shop_domain', $domain)->first()
            ?? new ShopifyInstallation;

        $installation->fill([
            'shop_domain' => $domain,
            'access_token' => $granted['access_token'],
            'scopes' => $granted['scopes'],
            'is_expiring' => $granted['is_expiring'],
            'expires_at' => $granted['expires_at'],
            'installed_at' => now(),
            'last_checked_at' => null,
            'last_check_error' => null,
        ]);

        $installation->save();

        // Sólo una instalación activa: cualquier otra fila queda obsoleta.
        ShopifyInstallation::query()->whereKeyNot($installation->getKey())->delete();

        return $installation;
    }

    public function redirectUri(): string
    {
        // Nombre completo: las rutas viven dentro del grupo del panel de Filament
        // para heredar su sesión, su autenticación y su segundo factor (RFC-0009 §6.2).
        return route('filament.admin.shopify.oauth.callback');
    }

    public function clientId(): string
    {
        return trim((string) config('product-studio.shopify.api_key', ''));
    }

    public function clientSecret(): string
    {
        return trim((string) config('product-studio.shopify.api_secret', ''));
    }

    /**
     * Dominio normalizado de una tienda Shopify.
     *
     * Acepta lo que una persona pueda pegar (`https://`, barra final, mayúsculas) y
     * exige que termine en `.myshopify.com`: la instalación ocurre contra el
     * dominio canónico de la tienda, nunca contra el dominio público.
     *
     * @throws ShopifyOAuthFailed
     */
    public function normalizeShopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim((string) $domain, '/');

        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) !== 1) {
            throw ShopifyOAuthFailed::invalidShopDomain($domain);
        }

        return $domain;
    }

    /**
     * Dominio con el que se prellena el formulario de conexión.
     *
     * Es el esperado del entorno si lo hay; si no, la instalación guardada.
     */
    public function suggestedShopDomain(): string
    {
        $expected = trim((string) config('product-studio.shopify.expected_shop_domain', ''));

        if ($expected !== '') {
            return $expected;
        }

        return (string) (ShopifyInstallation::current()?->shop_domain ?? '');
    }

    private function wantsExpiringToken(): bool
    {
        return (bool) config('product-studio.shopify.oauth_expiring', false);
    }

    /**
     * Scopes de la respuesta de intercambio.
     *
     * Shopify devuelve una lista separada por comas. Algunas respuestas la omiten
     * porque no ha cambiado respecto a lo pedido; en ese caso no se inventa nada y
     * se deja vacío para que la comprobación de sólo lectura lo confirme.
     *
     * @return list<string>
     */
    private function scopesFrom(mixed $scope): array
    {
        if (is_array($scope)) {
            $scope = implode(',', array_filter($scope, 'is_string'));
        }

        if (! is_string($scope) || trim($scope) === '') {
            return [];
        }

        $scopes = array_map('trim', explode(',', $scope));

        return array_values(array_filter($scopes, static fn (string $item): bool => $item !== ''));
    }

    private function expiresAtFrom(array $body): ?\DateTimeInterface
    {
        $expiresIn = $body['expires_in'] ?? null;

        if (! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            return null;
        }

        return now()->addSeconds((int) $expiresIn);
    }
}
