<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets;

use App\Filament\Resources\TechnicalSheets\Pages\CreateFit;
use App\Filament\Resources\TechnicalSheets\Pages\EditFit;
use App\Filament\Resources\TechnicalSheets\Pages\ListFits;
use App\Models\TechnicalSheetFit;
use Filament\Support\Icons\Heroicon;

/**
 * Mantenimiento de perfiles de ajuste y tallaje (RFC-0008).
 *
 * Contiene un nombre y un texto comercial o técnico ya aprobado. Es el dato que
 * respalda las palabras «unisex», «oversize» o «entallado»: sin él, RFC-0003
 * prohíbe que la IA las use.
 */
class FitResource extends TechnicalSheetResource
{
    protected static ?string $model = TechnicalSheetFit::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?string $navigationLabel = 'Ajuste y tallaje';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'perfil de ajuste';

    protected static ?string $pluralModelLabel = 'perfiles de ajuste';

    protected static function contentHelp(): string
    {
        return 'Describe el corte de forma que se entienda sin ver la prenda. '
            .'El nombre corto («Oversize») es el que aparece en el selector de la ficha.';
    }

    protected static function contentSchema(): array
    {
        return [
            self::contentTextarea('Texto comercial o técnico', 3)
                ->helperText(self::textHelp().' Ejemplos: «Unisex regular», «Mujer entallada», «Corte recto».'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFits::route('/'),
            'create' => CreateFit::route('/create'),
            'edit' => EditFit::route('/{record}/edit'),
        ];
    }
}
