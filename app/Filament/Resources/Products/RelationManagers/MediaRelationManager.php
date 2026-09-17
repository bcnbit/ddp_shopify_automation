<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Products\ProductMediaService;
use App\Support\Media\MediaRules;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Imágenes de la ficha (RFC-0002 / RFC-0005).
 *
 * El alta de la imagen la hace ProductMediaService, no la interfaz: así el
 * checksum, las dimensiones, el orden y la portada inicial se calculan en un
 * único sitio. Por eso el formulario de creación sólo pide el archivo.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Imágenes';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('alt_text')
                    ->label('Texto ALT')
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText('Describe exactamente la imagen. Es obligatorio antes de enviar a Shopify.')
                    ->columnSpanFull(),

                Toggle::make('is_primary')
                    ->label('Foto principal')
                    ->helperText('La ficha necesita una. Marcar otra desplaza la anterior.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_filename')
            ->columns([
                ImageColumn::make('path')
                    ->label('Imagen')
                    ->disk(fn (ProductMedia $record): string => $record->disk)
                    ->height(60)
                    ->square(),

                TextColumn::make('original_filename')
                    ->label('Archivo')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn (ProductMedia $record): string => $record->original_filename),

                TextColumn::make('is_primary')
                    ->label('Principal')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                TextColumn::make('alt_text')
                    ->label('ALT')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Aprobado' : 'Pendiente')
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'warning'),

                TextColumn::make('width')
                    ->label('Tamaño')
                    ->formatStateUsing(
                        fn (ProductMedia $record): string => $record->width === null
                            ? '—'
                            : "{$record->width}×{$record->height}"
                    )
                    ->description(fn (ProductMedia $record): string => $record->humanFileSize()),

                TextColumn::make('upload_status')
                    ->label('Subida')
                    ->badge(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir imagen')
                    ->visible(fn (): bool => $this->canEditOwner())
                    ->modalHeading('Añadir imagen')
                    ->schema([
                        FileUpload::make('upload')
                            ->label('Imagen')
                            ->required()
                            ->image()
                            ->acceptedFileTypes(MediaRules::allowedMimeTypes())
                            ->maxSize(MediaRules::maxKilobytes())
                            /*
                             * El archivo no lo guarda Filament: se le entrega
                             * temporal a ProductMediaService, que calcula el
                             * checksum, las dimensiones, el orden y la portada
                             * en un único sitio. Si Filament lo guardase, el
                             * servicio recibiría una ruta ya escrita y habría
                             * dos sitios decidiendo el destino del original.
                             */
                            ->storeFiles(false)
                            ->helperText(
                                'JPG, PNG o WebP hasta '
                                .number_format(MediaRules::maxKilobytes() / 1024, 0, ',', '.').' MB. SVG no está permitido.'
                            )
                            ->columnSpanFull(),
                    ])
                    ->using(function (array $data): ProductMedia {
                        $owner = $this->ownerRecord;

                        if (! $owner instanceof Product) {
                            throw ValidationException::withMessages(['upload' => 'Ficha no válida.']);
                        }

                        $file = $data['upload'] ?? null;

                        if (is_array($file)) {
                            $file = reset($file);
                        }

                        if (! $file instanceof TemporaryUploadedFile) {
                            throw ValidationException::withMessages(['upload' => 'No se ha recibido ninguna imagen.']);
                        }

                        $result = app(ProductMediaService::class)->store($owner, $file, auth()->user());

                        if ($result['duplicate']) {
                            Notification::make()
                                ->title('Esa imagen ya estaba en la ficha')
                                ->body('No se ha duplicado ni se ha vuelto a subir.')
                                ->warning()
                                ->send();
                        }

                        return $result['media'];
                    }),
            ])
            ->recordActions([
                Action::make('setPrimary')
                    ->label('Hacer principal')
                    ->icon('heroicon-o-star')
                    ->requiresConfirmation()
                    ->modalHeading('¿Marcar como foto principal?')
                    ->modalDescription('La portada actual pasará a ser una imagen secundaria.')
                    ->visible(fn (ProductMedia $record): bool => $this->canEditOwner() && ! $record->is_primary)
                    ->action(function (ProductMedia $record): void {
                        app(ProductMediaService::class)->markAsPrimary(
                            $this->ownerRecord,
                            $record,
                            auth()->user(),
                        );

                        Notification::make()->title('Foto principal actualizada')->success()->send();
                    }),

                EditAction::make()
                    ->label('ALT y portada')
                    ->visible(fn (): bool => $this->canEditOwner())
                    ->using(function (ProductMedia $record, array $data): ProductMedia {
                        $service = app(ProductMediaService::class);
                        $user = auth()->user();

                        $service->updateAltText($record, $data['alt_text'] ?? null, $user);

                        if (($data['is_primary'] ?? false) === true && ! $record->is_primary) {
                            $service->markAsPrimary($this->ownerRecord, $record, $user);
                        }

                        return $record->refresh();
                    }),

                DeleteAction::make()
                    ->modalHeading('¿Eliminar esta imagen?')
                    ->modalDescription(
                        'El archivo original se conserva en el almacenamiento. '
                        .'La acción queda registrada en la auditoría.'
                    )
                    ->visible(
                        fn (ProductMedia $record): bool => $this->canEditOwner()
                            && $record->shopify_media_gid === null
                    )
                    ->action(function (ProductMedia $record): void {
                        app(ProductMediaService::class)->delete($record, auth()->user());

                        Notification::make()->title('Imagen eliminada de la ficha')->success()->send();
                    }),
            ]);
    }

    private function canEditOwner(): bool
    {
        $owner = $this->ownerRecord;

        return $owner instanceof Product && auth()->user()?->can('update', $owner) === true;
    }

    public static function getModelLabel(): string
    {
        return 'imagen';
    }

    public static function getPluralModelLabel(): string
    {
        return 'imágenes';
    }
}
