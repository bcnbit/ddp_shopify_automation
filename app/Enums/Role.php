<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles del sistema (RFC-0000).
 *
 * El rol agrupa permisos; la decisión final siempre la toma una Policy.
 * Así una operadora no puede publicar ni aunque invoque la acción por HTTP.
 */
enum Role: string
{
    case Operadora = 'operadora';
    case ResponsableCatalogo = 'responsable_catalogo';
    case AdminTecnico = 'admin_tecnico';

    public function label(): string
    {
        return match ($this) {
            self::Operadora => 'Operadora',
            self::ResponsableCatalogo => 'Responsable de catálogo',
            self::AdminTecnico => 'Administrador técnico',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
