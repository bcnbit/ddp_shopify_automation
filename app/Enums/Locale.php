<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Idioma de contenido. ES es el idioma inicial; CA/EN/FR quedan preparados (RFC-0000).
 */
enum Locale: string implements HasLabel
{
    case Es = 'es';
    case Ca = 'ca';
    case En = 'en';
    case Fr = 'fr';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Es => 'Castellano',
            self::Ca => 'Català',
            self::En => 'English',
            self::Fr => 'Français',
        };
    }

    public static function default(): self
    {
        return self::Es;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
