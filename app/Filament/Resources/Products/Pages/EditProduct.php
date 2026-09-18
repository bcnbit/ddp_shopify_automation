<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\ProductMedia;
use App\Services\Ai\ProductGenerationService;
use App\Services\Products\ProductContentService;
use App\Services\Products\ProductMediaService;
use App\Services\Products\ProductService;
use App\Services\Shopify\ProductSyncService;
use App\Support\Ai\GenerationLimiter;
use App\Support\Products\ProductReadiness;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Response;
use RuntimeException;

/**
 * Edición de una ficha con guardado automático (RFC-0002).
 *
 * El guardado automático escribe **sólo** las columnas de `products`; el
 * contenido comercial vive en `product_content` y se guarda con su propia
 * acción, para que una edición manual no cree versiones nuevas sin querer.
 *
 * Todas las acciones críticas exigen confirmación y pasan por los servicios de
 * dominio, que son los que dejan rastro en auditoría.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** Segundos entre guardados automáticos. */
    protected int $autoSaveInterval = 5;

    protected function getHeaderActions(): array
    {
        return [
            $this->makeGenerateProposalAction(),
            $this->makeRegenerateFieldAction(),
            $this->makeRestoreProposalAction(),
            $this->makeLocalDraftAction(),
            $this->makeApproveAction(),
            $this->makeSendToShopifyAction(),
            $this->makeDownloadJsonAction(),
            DeleteAction::make()
                ->label('Eliminar ficha')
                ->visible(fn (): bool => auth()->user()?->can('delete', $this->getRecord()) === true)
                ->successNotificationTitle('Ficha eliminada'),
        ];
    }

    /**
     * Solicita la generación de la propuesta con IA (RFC-0003).
     *
     * La generación es asíncrona: se encola y la ficha pasa a «generando». La
     * propuesta nunca se aprueba sola; sólo aparece para que una persona la
     * revise campo a campo.
     */
    private function makeGenerateProposalAction(): Action
    {
        return Action::make('generateProposal')
            ->label('Generar propuesta')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('¿Generar la propuesta de contenido?')
            ->modalDescription(function (): string {
                $limiter = app(GenerationLimiter::class);
                $record = $this->getRecord();
                $remaining = $limiter->remainingToday($record);

                return 'Se enviarán a la IA sólo los datos confirmados y hasta cuatro fotos. '
                    .'La propuesta se revisa antes de aprobar. '
                    ."Te quedan {$remaining} de {$limiter->limitPerDay()} generaciones hoy para esta ficha.";
            })
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) === true)
            ->disabled(fn (): bool => ! app(GenerationLimiter::class)->canGenerate($this->getRecord()))
            ->action(function (): void {
                try {
                    app(ProductGenerationService::class)->request($this->getRecord(), auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('No se ha podido generar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->refreshFormData(['status']);

                Notification::make()
                    ->title('Generación en marcha')
                    ->body('La propuesta aparecerá en «Contenido comercial» en unos segundos.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Crea una propuesta local sin IA, a partir de los datos confirmados.
     *
     * Es útil cuando no hay proveedor configurado o se quiere partir de algo
     * antes de editar a mano. Nunca inventa datos comerciales.
     */
    private function makeLocalDraftAction(): Action
    {
        return Action::make('localDraft')
            ->label('Propuesta sin IA')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('¿Crear una propuesta a partir de los datos confirmados?')
            ->modalDescription('No se llama a ningún proveedor: sólo se reutiliza lo que ya has confirmado.')
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) === true)
            ->action(function (): void {
                app(ProductContentService::class)->createLocalDraft($this->getRecord(), auth()->user());

                $this->refreshFormData(['shopify_handle']);

                Notification::make()->title('Propuesta creada')->success()->send();
            });
    }

    /**
     * Regenera un único campo sin tocar el resto (RFC-0003).
     */
    private function makeRegenerateFieldAction(): Action
    {
        return Action::make('regenerateField')
            ->label('Regenerar un campo')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->schema([
                Select::make('field')
                    ->label('Campo a regenerar')
                    ->options([
                        'title' => 'Título',
                        'short_benefit' => 'Beneficio corto',
                        'html_description' => 'Descripción',
                        'seo_title' => 'Meta title',
                        'seo_description' => 'Meta description',
                        'handle' => 'Handle',
                        'tags_json' => 'Etiquetas',
                    ])
                    ->required()
                    ->helperText('Los demás campos se conservan tal cual, incluidos tus cambios a mano.'),
            ])
            ->modalHeading('¿Regenerar un campo?')
            ->modalDescription('Sólo se sustituye el campo elegido. El resto de la propuesta no se toca.')
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) === true)
            ->disabled(fn (): bool => ! app(GenerationLimiter::class)->canGenerate($this->getRecord()))
            ->action(function (array $data): void {
                try {
                    app(ProductGenerationService::class)->requestFieldRegeneration(
                        $this->getRecord(),
                        (string) $data['field'],
                        auth()->user(),
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('No se ha podido regenerar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Regeneración en marcha')
                    ->body('El campo se actualizará en unos segundos.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Restaura la última propuesta aprobada como versión nueva (RFC-0002).
     */
    private function makeRestoreProposalAction(): Action
    {
        return Action::make('restoreProposal')
            ->label('Restaurar propuesta')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('¿Restaurar la última propuesta aprobada?')
            ->modalDescription(
                'Se copia como versión nueva para seguir editando. '
                .'La versión aprobada se conserva intacta.'
            )
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getRecord()) === true)
            ->action(function (): void {
                try {
                    app(ProductContentService::class)->restoreFromApproved($this->getRecord(), auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('No se ha podido restaurar')
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()->title('Propuesta restaurada')->success()->send();
            });
    }

    private function makeApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar ficha')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('¿Aprobar esta ficha?')
            ->modalDescription(
                'Aprobar habilita el envío como borrador a Shopify. '
                .'No publica nada: la publicación es una acción aparte y posterior.'
            )
            ->visible(function (): bool {
                $user = auth()->user();
                $record = $this->getRecord();

                return $user !== null
                    && $user->can('approve', $record)
                    && ProductReadiness::canApprove($record);
            })
            ->action(function (): void {
                try {
                    app(ProductService::class)->approve($this->getRecord(), auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('No se ha podido aprobar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshFormData(['status', 'approved_at', 'approved_by']);

                Notification::make()->title('Ficha aprobada')->success()->send();
            });
    }

    /**
     * Envío como borrador. En RFC-0002 no existe conector con Shopify: la
     * acción valida, bloquea si falta algo y deja la ficha preparada y auditada.
     */
    private function makeSendToShopifyAction(): Action
    {
        return Action::make('sendToShopify')
            ->label(fn (): string => ProductReadiness::actionLabel($this->getRecord()))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => ProductReadiness::actionLabel($this->getRecord()).'?')
            ->modalDescription(function (): string {
                $validation = ProductReadiness::validation($this->getRecord());

                if ($validation->fails()) {
                    return 'Hay errores bloqueantes: '.implode(' ', $validation->blockingMessages());
                }

                return 'Se enviará con estado borrador. Nunca se publica automáticamente.';
            })
            ->visible(fn (): bool => auth()->user()?->can('sync', $this->getRecord()) === true)
            ->disabled(fn (): bool => ! ProductReadiness::canSendToShopify($this->getRecord()))
            ->action(function (): void {
                $record = $this->getRecord();
                $validation = ProductReadiness::validation($record);

                if ($validation->fails()) {
                    Notification::make()
                        ->title('No se puede enviar todavía')
                        ->body(implode(' ', $validation->blockingMessages()))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                try {
                    app(ProductSyncService::class)->request($record, auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('No se ha podido enviar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshFormData(['status']);

                Notification::make()
                    ->title('Envío en marcha')
                    ->body('El producto se creará en Shopify como borrador. Si algo falla, podrás reintentarlo sin duplicarlo.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Exporta la ficha completa en JSON para revisión o soporte.
     */
    private function makeDownloadJsonAction(): Action
    {
        return Action::make('downloadJson')
            ->label('Exportar JSON')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('view', $this->getRecord()) === true)
            ->action(function () {
                $record = $this->getRecord();
                $record->load(['variants', 'media', 'contents', 'technicalSheets']);

                $payload = ProductResource::toExportArray($record);

                return Response::streamDownload(
                    fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'ficha-'.strtolower((string) $record->internal_reference).'.json',
                    ['Content-Type' => 'application/json'],
                );
            });
    }

    /**
     * Guardado automático: sólo los campos de la ficha.
     *
     * Se llama cada pocos segundos y al cambiar de pestaña. Es idempotente y no
     * crea versiones de contenido.
     */
    public function autoSave(): void
    {
        $record = $this->getRecord();

        if ($record === null) {
            return;
        }

        try {
            $this->authorizeAutoSave($record);
        } catch (AuthorizationException) {
            return;
        }

        $data = $this->form->getState();

        app(ProductService::class)->update($record, $this->onlyProductAttributes($data), auth()->user());

        $this->resetErrorBag();
    }

    /**
     * Descarta claves que no son columnas de `products`.
     *
     * El estado del formulario incluye campos de solo lectura y ayudas visuales
     * que no deben llegar al modelo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function onlyProductAttributes(array $data): array
    {
        $attributes = [];

        foreach (ProductResource::editableProductAttributes() as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        return $attributes;
    }

    private function authorizeAutoSave(Model $record): void
    {
        if (auth()->user()?->can('update', $record) !== true) {
            throw new AuthorizationException('No autorizado.');
        }
    }

    /**
     * Marca la foto principal desde la tabla de medios (acción masiva).
     */
    public function setPrimaryMedia(int $mediaId): void
    {
        $record = $this->getRecord();
        $media = ProductMedia::findOrFail($mediaId);

        app(ProductMediaService::class)->markAsPrimary($record, $media, auth()->user());

        $this->refreshFormData([]);

        Notification::make()->title('Foto principal actualizada')->success()->send();
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * Al guardar con el botón, redirigimos al listado para no confundir el
     * guardado manual con el automático.
     */
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Ficha guardada';
    }
}
