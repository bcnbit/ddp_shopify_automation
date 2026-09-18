<?php

declare(strict_types=1);

namespace App\Filament\Resources\TechnicalSheets;

use App\Filament\Resources\TechnicalSheets\Pages\CreateSizeGuide;
use App\Filament\Resources\TechnicalSheets\Pages\EditSizeGuide;
use App\Filament\Resources\TechnicalSheets\Pages\ListSizeGuides;
use App\Models\TechnicalSheetSizeGuide;
use App\Support\Security\TechnicalSheetHtmlSanitizer;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * Mantenimiento de guías de tallas (RFC-0008).
 *
 * Guarda una nota introductoria opcional, la tabla HTML y una nota final
 * opcional. El HTML se sanitiza con la whitelist de tablas al guardarse, de modo
 * que no exista ninguna ruta que persista HTML sucio.
 *
 * La lista blanca no admite atributos, así que `colspan` y `rowspan` se pierden
 * al sanear y la tabla podría cambiar de significado. Para que eso no ocurra en
 * silencio, el formulario lo avisa.
 */
class SizeGuideResource extends TechnicalSheetResource
{
    protected static ?string $model = TechnicalSheetSizeGuide::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Guías de tallas';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'guía de tallas';

    protected static ?string $pluralModelLabel = 'guías de tallas';

    protected static function contentHelp(): string
    {
        return 'La tabla se inserta en la descripción de Shopify y en la propuesta de IA. '
            .'Se permite: '.implode(', ', TechnicalSheetHtmlSanitizer::ALLOWED_TAGS).'.';
    }

    protected static function contentSchema(): array
    {
        return [
            View::make('filament.forms.components.table-html-warning')
                ->viewData(fn (callable $get): array => [
                    'html' => (string) ($get('content_html') ?? ''),
                ])
                ->dehydrated(false),

            Textarea::make('intro_note')
                ->label('Nota introductoria')
                ->rows(2)
                ->helperText('Opcional. Aparece justo antes de la tabla.')
                ->live(onBlur: true)
                ->columnSpanFull(),

            Textarea::make('content_html')
                ->label('Tabla HTML')
                ->required()
                ->rows(10)
                ->helperText('Sólo table, thead, tbody, tr, th, td, p, strong, em, br, ul, ol, li. Sin atributos ni estilos.')
                ->live(onBlur: true)
                ->columnSpanFull(),

            Textarea::make('closing_note')
                ->label('Nota final')
                ->rows(2)
                ->helperText('Opcional. Por ejemplo, qué hacer si se duda entre dos tallas.')
                ->live(onBlur: true)
                ->columnSpanFull(),

            View::make('filament.forms.components.size-guide-preview')
                ->viewData(fn (callable $get): array => [
                    'intro' => (string) ($get('intro_note') ?? ''),
                    'html' => (string) ($get('content_html') ?? ''),
                    'closing' => (string) ($get('closing_note') ?? ''),
                ])
                ->dehydrated(false)
                ->columnSpanFull(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSizeGuides::route('/'),
            'create' => CreateSizeGuide::route('/create'),
            'edit' => EditSizeGuide::route('/{record}/edit'),
        ];
    }
}
