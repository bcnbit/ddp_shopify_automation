<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets;

use App\Filament\Resources\TechnicalSheets\Pages\CreateComposition;
use App\Filament\Resources\TechnicalSheets\Pages\EditComposition;
use App\Filament\Resources\TechnicalSheets\Pages\ListCompositions;
use App\Models\TechnicalSheetComposition;
use Filament\Support\Icons\Heroicon;

/**
 * Mantenimiento de composiciones (RFC-0008).
 *
 * Guarda el texto exacto y confirmado que puede mencionarse en Shopify y en la
 * propuesta de IA. La persona que lo crea asume que el dato es real: la IA no
 * puede inventar composición, y un mantenimiento es justamente el sitio donde el
 * dato queda confirmado una sola vez.
 */
class CompositionResource extends TechnicalSheetResource
{
    protected static ?string $model = TechnicalSheetComposition::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Composiciones';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'composición';

    protected static ?string $pluralModelLabel = 'composiciones';

    protected static function contentHelp(): string
    {
        return 'Escribe la composición tal y como debe aparecer en la tienda. '
            .'«100% algodón orgánico» sólo si es un dato confirmado.';
    }

    protected static function contentSchema(): array
    {
        return [
            self::contentTextarea('Composición', 2)
                ->helperText(self::textHelp().' Ejemplos: «100% algodón», «80% algodón / 20% poliéster».'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompositions::route('/'),
            'create' => CreateComposition::route('/create'),
            'edit' => EditComposition::route('/{record}/edit'),
        ];
    }
}
