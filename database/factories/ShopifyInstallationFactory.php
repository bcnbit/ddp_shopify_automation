<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShopifyInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Instalación de prueba (RFC-0009).
 *
 * El token es un valor **de prueba**, no una credencial: respeta el prefijo que
 * la Admin API exige (`shpat_`) para que el camino de código que lo valida se
 * ejercite de verdad en lugar de saltárselo.
 *
 * @extends Factory<ShopifyInstallation>
 */
class ShopifyInstallationFactory extends Factory
{
    protected $model = ShopifyInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_domain' => 'dies-de-platja.myshopify.com',
            'access_token' => 'shpat_token-de-prueba-no-real',
            'scopes' => ShopifyInstallation::REQUIRED_SCOPES,
            'is_expiring' => false,
            'expires_at' => null,
            'installed_at' => now(),
            'last_checked_at' => null,
            'last_check_error' => null,
        ];
    }

    public function forDomain(string $domain): static
    {
        return $this->state(fn (array $attributes): array => ['shop_domain' => $domain]);
    }

    /**
     * Guarda la client secret donde debería ir un access token.
     *
     * Existe para poder probar que ese caso se detecta y se rechaza.
     */
    public function withClientSecretAsToken(): static
    {
        return $this->state(fn (array $attributes): array => [
            'access_token' => 'shpss_'.'secreto-de-prueba-no-real',
        ]);
    }

    public function withoutScopes(): static
    {
        return $this->state(fn (array $attributes): array => ['scopes' => []]);
    }

    public function expiring(\DateTimeInterface $expiresAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_expiring' => true,
            'expires_at' => $expiresAt,
        ]);
    }
}
