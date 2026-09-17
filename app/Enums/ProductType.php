<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Familia de producto del MVP (README + RFC-0003).
 */
enum ProductType: string implements HasLabel
{
    case Tshirt = 'camiseta';
    case Hoodie = 'sudadera';
    case Bag = 'bolso';
    case Kids = 'prenda_infantil';
    case Other = 'otro';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Tshirt => 'Camiseta',
            self::Hoodie => 'Sudadera',
            self::Bag => 'Bolso / neceser',
            self::Kids => 'Prenda infantil',
            self::Other => 'Otro',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
