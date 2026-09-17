<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Política de inventario alineada con Shopify. El MVP no inventa stock (RFC-0005).
 */
enum InventoryPolicy: string implements HasLabel
{
    case Deny = 'deny';
    case Continue = 'continue';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Deny => 'No vender sin stock',
            self::Continue => 'Permitir venta sin stock',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
