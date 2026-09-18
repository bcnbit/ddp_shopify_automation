<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShopifyInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Instalación de la aplicación en una tienda de Shopify (RFC-0009).
 *
 * Es la **única** fuente del access token. La aplicación sirve a una sola tienda,
 * así que `current()` devuelve la instalación activa y reinstalar la reemplaza en
 * lugar de acumular filas.
 *
 * El token nunca se expone:
 *
 * - `$hidden` lo excluye de `toArray()`/`toJson()`, que es como llega a Livewire y
 *   a una respuesta HTTP.
 * - `SecretRedactor` lo enmascara en logs y auditoría.
 * - La tabla lo guarda cifrado con `APP_KEY`.
 *
 * Sobre los prefijos: los access token de la Admin API empiezan por `shpat_` (y
 * los de delegado por `shppa_`). Una credencial `shpss_` es la **client secret**
 * de la aplicación y la Admin API la rechaza; se detecta aquí, con un mensaje que
 * explica el error real, en lugar de dejar que Shopify devuelva un 401 opaco.
 */
class ShopifyInstallation extends Model
{
    /** @use HasFactory<ShopifyInstallationFactory> */
    use HasFactory;

    /** Prefijos aceptados como access token de la Admin API. */
    public const ACCESS_TOKEN_PREFIXES = ['shpat_', 'shppa_'];

    /** Prefijo de la client secret: nunca es un access token. */
    public const CLIENT_SECRET_PREFIX = 'shpss_';

    public const REQUIRED_SCOPES = ['read_products', 'write_products', 'read_files', 'write_files'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_domain',
        'access_token',
        'scopes',
        'is_expiring',
        'expires_at',
        'installed_at',
        'last_checked_at',
        'last_check_error',
    ];

    /**
     * El token no debe serializarse nunca hacia el navegador.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'is_expiring' => 'boolean',
            'expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * La instalación activa. Sólo hay una (RFC-0009 §4.4).
     */
    public static function current(): ?self
    {
        return static::query()->orderByDesc('id')->first();
    }

    /**
     * ¿Sirve esta cadena como access token de la Admin API?
     *
     * Se comprueba el prefijo y no el contenido: son cadenas opacas, así que lo
     * único verificable sin llamar a Shopify es que sean del tipo correcto.
     */
    public static function isAccessToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        foreach (self::ACCESS_TOKEN_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Es la client secret de la aplicación puesta en el sitio equivocado?
     *
     * Es el error que motivó esta RFC, así que se detecta y se nombra.
     */
    public static function isClientSecret(?string $token): bool
    {
        return $token !== null && str_starts_with($token, self::CLIENT_SECRET_PREFIX);
    }

    public function hasUsableToken(): bool
    {
        return self::isAccessToken($this->access_token);
    }

    /**
     * Dominio de la tienda, o `null` si no tiene forma de tienda Shopify.
     */
    public function validDomain(): ?string
    {
        $domain = strtolower(trim((string) $this->shop_domain));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) === 1 ? $domain : null;
    }

    /**
     * Scopes concedidos.
     *
     * @return list<string>
     */
    public function grantedScopes(): array
    {
        $scopes = $this->scopes;

        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_filter($scopes, static fn (mixed $scope): bool => is_string($scope) && $scope !== ''));
    }

    /**
     * Scopes que la aplicación necesita y la tienda **no** ha concedido.
     *
     * @return list<string>
     */
    public function missingScopes(): array
    {
        return array_values(array_diff(self::REQUIRED_SCOPES, $this->grantedScopes()));
    }

    /**
     * ¿El token expirable ya ha caducado?
     *
     * El refresco no está implementado (RFC-0009 §4.3), así que esto sólo sirve
     * para avisar en lugar de dejar un error de autorización sin explicación.
     */
    public function hasExpiredToken(): bool
    {
        return $this->is_expiring && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Etiqueta corta con la que aparece en la pantalla de conexión.
     */
    public function describe(): string
    {
        return (string) $this->shop_domain;
    }
}
