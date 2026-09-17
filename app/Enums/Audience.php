<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Público objetivo. Es un dato confirmado por una persona, nunca inferido por IA (RFC-0003).
 */
enum Audience: string implements HasLabel
{
    case Woman = 'mujer';
    case Man = 'hombre';
    case Unisex = 'unisex';
    case Kids = 'infantil';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Woman => 'Mujer',
            self::Man => 'Hombre',
            self::Unisex => 'Unisex',
            self::Kids => 'Infantil',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
