<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Operaciones sincronizables contra Shopify (RFC-0004).
 *
 * En RFC-0001 sólo se define el vocabulario; no existe todavía ninguna llamada remota.
 */
enum SyncOperation: string implements HasLabel
{
    case CreateProduct = 'create_product';
    case UpdateProduct = 'update_product';
    case SyncVariants = 'sync_variants';
    case UploadMedia = 'upload_media';
    case UpdateContent = 'update_content';
    case PublishProduct = 'publish_product';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::CreateProduct => 'Crear producto',
            self::UpdateProduct => 'Actualizar producto',
            self::SyncVariants => 'Sincronizar variantes',
            self::UploadMedia => 'Subir medios',
            self::UpdateContent => 'Actualizar contenido',
            self::PublishProduct => 'Publicar producto',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
