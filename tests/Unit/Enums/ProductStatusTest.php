<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ProductStatus;
use PHPUnit\Framework\TestCase as BaseTestCase;

class ProductStatusTest extends BaseTestCase
{
    public function test_contiene_los_estados_del_rfc_0000(): void
    {
        $expected = [
            'draft', 'generating', 'review', 'approved', 'syncing',
            'shopify_draft', 'published', 'generation_failed',
            'validation_failed', 'sync_failed', 'archived',
        ];

        $this->assertSame($expected, ProductStatus::values());
    }

    public function test_permite_el_camino_feliz_completo(): void
    {
        $this->assertTrue(ProductStatus::Draft->canTransitionTo(ProductStatus::Generating));
        $this->assertTrue(ProductStatus::Generating->canTransitionTo(ProductStatus::Review));
        $this->assertTrue(ProductStatus::Review->canTransitionTo(ProductStatus::Approved));
        $this->assertTrue(ProductStatus::Approved->canTransitionTo(ProductStatus::Syncing));
        $this->assertTrue(ProductStatus::Syncing->canTransitionTo(ProductStatus::ShopifyDraft));
        $this->assertTrue(ProductStatus::ShopifyDraft->canTransitionTo(ProductStatus::Published));
    }

    public function test_no_permite_publicar_sin_pasar_por_shopify(): void
    {
        // Criterio de RFC-0000: no se publica sin una sincronización correcta.
        $this->assertFalse(ProductStatus::Draft->canTransitionTo(ProductStatus::Published));
        $this->assertFalse(ProductStatus::Review->canTransitionTo(ProductStatus::Published));
        $this->assertFalse(ProductStatus::Approved->canTransitionTo(ProductStatus::Published));
        $this->assertFalse(ProductStatus::Generating->canTransitionTo(ProductStatus::Published));
        $this->assertFalse(ProductStatus::SyncFailed->canTransitionTo(ProductStatus::Published));
    }

    public function test_un_estado_archivado_es_terminal(): void
    {
        $this->assertTrue(ProductStatus::Archived->isTerminal());
        $this->assertSame([], ProductStatus::Archived->allowedTransitions());

        foreach (ProductStatus::cases() as $status) {
            $this->assertFalse(
                ProductStatus::Archived->canTransitionTo($status),
                "Archived no debería permitir transición a {$status->value}",
            );
        }
    }

    public function test_los_estados_de_fallo_se_reconocen_como_tales(): void
    {
        $this->assertTrue(ProductStatus::GenerationFailed->isFailed());
        $this->assertTrue(ProductStatus::ValidationFailed->isFailed());
        $this->assertTrue(ProductStatus::SyncFailed->isFailed());
        $this->assertFalse(ProductStatus::Draft->isFailed());
    }

    public function test_solo_los_estados_finales_estan_sincronizados(): void
    {
        $this->assertTrue(ProductStatus::ShopifyDraft->isSynced());
        $this->assertTrue(ProductStatus::Published->isSynced());
        $this->assertFalse(ProductStatus::Syncing->isSynced());
        $this->assertFalse(ProductStatus::Approved->isSynced());
    }

    public function test_no_permite_editar_fichas_ya_sincronizadas(): void
    {
        $this->assertTrue(ProductStatus::Draft->isEditable());
        $this->assertTrue(ProductStatus::Review->isEditable());
        $this->assertFalse(ProductStatus::ShopifyDraft->isEditable());
        $this->assertFalse(ProductStatus::Published->isEditable());
        $this->assertFalse(ProductStatus::Archived->isEditable());
    }
}
