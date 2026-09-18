<?php

declare(strict_types=1);

namespace App\DataObjects\Shopify;

/**
 * Resultado de la comprobación de sólo lectura de la conexión (RFC-0009 §7).
 *
 * Es un DTO y no un array porque la pantalla recorre exactamente las mismas
 * comprobaciones que las pruebas, y así una comprobación nueva no puede añadirse
 * en un sitio y olvidarse en el otro.
 *
 * Ninguno de sus campos contiene credenciales: sólo dominio, scopes y estado.
 */
final readonly class ShopifyConnectionCheck
{
    /**
     * @param  list<string>  $grantedScopes
     * @param  list<string>  $missingScopes
     */
    public function __construct(
        public bool $passed,
        public string $shopDomain,
        public ?string $shopName,
        public ?string $reportedDomain,
        public array $grantedScopes,
        public array $missingScopes,
        public bool $productsReadable,
        public int $productCount,
        public ?string $errorMessage = null,
    ) {}

    public static function failed(string $shopDomain, string $message): self
    {
        return new self(
            passed: false,
            shopDomain: $shopDomain,
            shopName: null,
            reportedDomain: null,
            grantedScopes: [],
            missingScopes: [],
            productsReadable: false,
            productCount: 0,
            errorMessage: $message,
        );
    }

    /**
     * ¿El dominio confirmado por Shopify es el esperado?
     *
     * Se exige forma canónica `*.myshopify.com`: es la condición que fija la
     * petición, y un dominio personalizado significaría que se ha instalado en otra
     * parte.
     */
    public function domainIsValid(): bool
    {
        $domain = $this->reportedDomain ?? $this->shopDomain;

        $isCanonical = str_ends_with($domain, '.myshopify.com')
            && preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) === 1;

        return $isCanonical && $this->shopDomain === $domain;
    }

    public function hasAllScopes(): bool
    {
        return $this->missingScopes === [];
    }

    /**
     * Resumen legible para la pantalla y para la consola.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function asRows(): array
    {
        return [
            [
                'label' => 'Dominio de la tienda',
                'ok' => $this->domainIsValid(),
                'detail' => (string) ($this->reportedDomain ?? $this->shopDomain),
            ],
            [
                'label' => 'Token instalado',
                'ok' => $this->errorMessage === null || $this->productsReadable,
                'detail' => $this->errorMessage ?? 'La API ha aceptado el access token.',
            ],
            [
                'label' => 'Permisos concedidos',
                'ok' => $this->hasAllScopes(),
                'detail' => $this->grantedScopes === []
                    ? 'La instalación no ha informado de ningún permiso.'
                    : implode(', ', $this->grantedScopes)
                        .($this->missingScopes === [] ? '' : ' — faltan: '.implode(', ', $this->missingScopes)),
            ],
            [
                'label' => 'Acceso a productos',
                'ok' => $this->productsReadable,
                'detail' => $this->productsReadable
                    ? 'Lectura correcta ('.$this->productCount.' producto(s) en la primera página).'
                    : 'No se han podido leer productos.',
            ],
        ];
    }
}
