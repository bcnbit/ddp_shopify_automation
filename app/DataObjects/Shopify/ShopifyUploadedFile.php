<?php

declare(strict_types=1);

namespace App\DataObjects\Shopify;

/**
 * Archivo ya subido a Shopify (RFC-0004).
 *
 * `gid` es lo que se asocia al producto; `resourceUrl` permite reconstruir el
 * archivo si hace falta. Se conserva el nombre para que un error sea legible.
 */
final readonly class ShopifyUploadedFile
{
    public function __construct(
        public string $gid,
        public string $filename,
        public ?string $resourceUrl = null,
    ) {}
}
