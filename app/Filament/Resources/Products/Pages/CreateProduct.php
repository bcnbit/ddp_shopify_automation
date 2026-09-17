<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use App\Services\Products\ProductService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Alta rápida de una ficha (RFC-0002).
 *
 * Sólo se piden los mínimos para poder empezar: referencia, nombre, precio y
 * tipo. Las imágenes y las variantes se añaden en la pantalla de edición, que
 * ya dispone de guardado automático. Así crear una ficha no obliga a rellenar
 * todo de golpe.
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(ProductService::class)->create(
            $this->onlyProductAttributes($data),
            $user,
        );
    }

    /**
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

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ficha creada. Añade las fotos y las variantes.';
    }

    protected function getRedirectUrl(): string
    {
        // Ir directo a la edición: ahí están las fotos, las variantes y las
        // acciones de aprobación.
        return ProductResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
