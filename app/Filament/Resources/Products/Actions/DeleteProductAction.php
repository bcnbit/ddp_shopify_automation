<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Products\ProductService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Eliminar una ficha de la aplicación (RFC-0002).
 *
 * Está en un solo sitio para que el listado y la ficha se comporten igual: si
 * cada pantalla montara su propio borrado, una acabaría limpiando los ficheros y
 * la otra no.
 *
 * Dos cosas que hace y conviene tener presentes:
 *
 * - **No toca Shopify.** Borra la ficha local y sus ficheros; el producto remoto
 *   se queda como está. El diálogo lo dice explícitamente porque es lo que
 *   espera quien pulsa: nadie quiere borrar algo en la tienda por accidente.
 * - **Limpia los originales y sus derivados.** Lo hace el servicio de dominio,
 *   que es también el que deja el rastro en auditoría.
 */
class DeleteProductAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Eliminar ficha')
            ->modalHeading('¿Eliminar esta ficha?')
            ->modalDescription(fn (Product $record): string => self::description($record))
            ->modalSubmitActionLabel('Eliminar sólo en la aplicación')
            ->successNotificationTitle('Ficha eliminada')
            // El borrado real lo hace el servicio de dominio: es el que limpia los
            // ficheros y deja el rastro en auditoría. `using()` sustituye el
            // `$record->delete()` que haría Filament por defecto.
            ->using(function (Product $record): void {
                $stats = app(ProductService::class)->delete($record, auth()->user());

                self::notifyOutcome($stats);
            });
    }

    /**
     * El aviso nombra lo que se va a perder; si no, la confirmación se convierte
     * en un «¿seguro?» genérico que nadie lee.
     */
    private static function description(Product $record): string
    {
        $lines = ['Se eliminará la ficha y sus imágenes de la aplicación. **No se tocará nada en Shopify.**'];

        if ($record->isSyncedWithShopify()) {
            $lines[] = 'Esta ficha ya está en Shopify: el producto de la tienda seguirá ahí. '
                .'Para conservar el vínculo local en lugar de borrarlo, archívala.';
        }

        $media = $record->media()->count();

        if ($media > 0) {
            $lines[] = "Se borrarán también {$media} archivo(s) de imagen del almacenamiento.";
        }

        return implode(' ', $lines);
    }

    /**
     * @param  array{media_files: int, media_failed: int}  $stats
     */
    private static function notifyOutcome(array $stats): void
    {
        $failed = $stats['media_failed'];

        if ($failed === 0) {
            return;
        }

        // La ficha ya no existe, así que no se puede dejar el aviso sólo en el
        // diálogo: hay que contar en una notificación que quedaron archivos sin
        // borrar, o el bucket se llenaría en silencio.
        Notification::make()
            ->warning()
            ->title('Ficha eliminada, pero quedaron archivos sin borrar')
            ->body("No se han podido eliminar {$failed} archivo(s) de imagen. Revisa el log para ver las rutas.")
            ->send();
    }
}
