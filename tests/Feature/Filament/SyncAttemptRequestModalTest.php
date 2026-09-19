<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\SyncAttemptsRelationManager;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Ver petición» en el historial de sincronización (RFC-0004).
 *
 * Cuando Shopify rechaza un envío, lo primero que se pregunta es «¿qué se envió?».
 * La petición se guarda en `sync_attempts.request_payload`, y esta acción la
 * muestra desde el panel para no tener que entrar en la base de datos.
 */
class SyncAttemptRequestModalTest extends TestCase
{
    private function productFor(User $user): Product
    {
        return Product::factory()->create([
            'internal_reference' => 'DDP-4100',
            'created_by' => $user->getKey(),
        ]);
    }

    private function attemptsTable(Product $product, User $user)
    {
        return Livewire::actingAs($user)->test(SyncAttemptsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);
    }

    private function failedAttempt(Product $product, array $requestPayload = []): SyncAttempt
    {
        $attempt = SyncAttempt::factory()->for($product)->create([
            'request_payload' => $requestPayload === [] ? [
                'product_studio_id' => $product->internal_reference,
                'status' => 'DRAFT',
                'variants' => [['sku' => 'DDP-4100-S']],
            ] : $requestPayload,
        ]);

        $attempt->markFailed('File URL is invalid', 'user_error');

        return $attempt->refresh();
    }

    /**
     * La acción se resuelve sobre el registro y renderiza el contenido.
     *
     * Se comprueba el contenido del modal y no el HTML del componente: Filament
     * monta el modal fuera del árbol del relation manager, así que `assertSee`
     * sobre la tabla no lo vería aunque la acción funcione.
     */
    public function test_la_operadora_puede_ver_la_peticion_que_se_envio(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);
        $attempt = $this->failedAttempt($product);

        $this->attemptsTable($product, $operadora)->mountTableAction('inspectRequest', $attempt->getKey());

        $action = $this->attemptsTable($product, $operadora)
            ->instance()
            ->getTable()
            ->getAction('inspectRequest')
            ->record($attempt);

        // Es la vía para diagnosticar sin acceso a la base de datos: el payload
        // exacto que recibió Shopify.
        $html = $action->getModalContent()?->render() ?? '';

        $this->assertStringContainsString('DDP-4100-S', $html);
        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringContainsString($attempt->support_reference, (string) $action->getModalHeading());
    }

    public function test_sin_peticion_guardada_la_accion_no_aparece(): void
    {
        $operadora = $this->operadora();
        $product = $this->productFor($operadora);

        // Un intento antiguo, anterior a que se guardara la petición.
        $attempt = SyncAttempt::factory()->for($product)->create(['request_payload' => null]);
        $attempt->markFailed('Fallo', 'user_error');

        $this->assertFalse(
            $this->attemptsTable($product, $operadora)
                ->instance()
                ->getTable()
                ->getAction('inspectRequest')
                ->record($attempt->refresh())
                ->isVisible(),
        );
    }

    public function test_la_operadora_ajena_no_puede_ver_la_peticion(): void
    {
        $product = $this->productFor($this->operadora());
        $attempt = $this->failedAttempt($product);

        // La autorización la decide la Policy del producto, no el botón.
        $this->assertFalse(
            $this->attemptsTable($product, $this->operadora())
                ->instance()
                ->getTable()
                ->getAction('inspectRequest')
                ->record($attempt)
                ->isVisible(),
        );
    }
}
