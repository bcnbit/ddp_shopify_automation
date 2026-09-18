<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets;

use App\Filament\Resources\TechnicalSheets\Pages\CreateCare;
use App\Filament\Resources\TechnicalSheets\Pages\EditCare;
use App\Filament\Resources\TechnicalSheets\Pages\ListCares;
use App\Models\TechnicalSheetCare;
use Filament\Support\Icons\Heroicon;

/**
 * Mantenimiento de perfiles de cuidados (RFC-0008).
 *
 * El texto se escribe **una instrucción por línea**: el compositor emite una
 * lista, que es como se leen de un vistazo en una ficha de producto. Un párrafo
 * corrido obligaría a reescribir todo para quitar una instrucción.
 */
class CareResource extends TechnicalSheetResource
{
    protected static ?string $model = TechnicalSheetCare::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Cuidados';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'perfil de cuidados';

    protected static ?string $pluralModelLabel = 'perfiles de cuidados';

    protected static function contentHelp(): string
    {
        return 'Una instrucción por línea. Se enviarán a Shopify como una lista con viñetas.';
    }

    protected static function contentSchema(): array
    {
        return [
            self::contentTextarea('Instrucciones de cuidado', 5)
                ->helperText(
                    self::textHelp()
                    .' Ejemplo: «Lavar del revés a un máximo de 30 ºC.» en una línea, '
                    .'«No usar secadora.» en la siguiente.'
                ),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCares::route('/'),
            'create' => CreateCare::route('/create'),
            'edit' => EditCare::route('/{record}/edit'),
        ];
    }
}
