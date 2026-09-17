<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\ActivityEvent;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Support\Audit\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_el_actor_y_el_evento(): void
    {
        $operadora = $this->operadora();
        $this->actingAs($operadora);

        $product = Product::factory()->create(['created_by' => $operadora->getKey()]);

        app(ActivityRecorder::class)->record(
            ActivityEvent::Created,
            $product,
            'Ficha creada desde el panel.',
        );

        $log = ActivityLog::firstOrFail();

        $this->assertTrue($log->user->is($operadora));
        $this->assertSame(ActivityEvent::Created, $log->event);
        $this->assertSame($product->getKey(), $log->subject_id);
        $this->assertSame(Product::class, $log->subject_type);
    }

    public function test_registra_el_diff_estructurado_de_cambios(): void
    {
        $this->actingAs($this->operadora());
        $product = Product::factory()->create();

        app(ActivityRecorder::class)->recordChanges(
            ActivityEvent::Updated,
            $product,
            ['price' => '20.00'],
            ['price' => '25.00'],
        );

        $changes = ActivityLog::firstOrFail()->changedAttributes();

        $this->assertSame('20.00', $changes['price']['old']);
        $this->assertSame('25.00', $changes['price']['new']);
    }

    public function test_nunca_guarda_credenciales_en_la_auditoria(): void
    {
        $this->actingAs($this->admin());
        $product = Product::factory()->create();

        app(ActivityRecorder::class)->record(
            ActivityEvent::SecretAccessed,
            $product,
            'Intento de guardar conexión.',
            [
                'shopify_access_token' => 'shpat_supersecreto123',
                'openai_api_key' => 'sk-supersecreto123456',
                'password' => 'contraseña-real',
            ],
        );

        $properties = ActivityLog::firstOrFail()->properties;

        $this->assertStringNotContainsString('shpat_supersecreto123', json_encode($properties));
        $this->assertStringNotContainsString('sk-supersecreto123456', json_encode($properties));
        $this->assertStringNotContainsString('contraseña-real', json_encode($properties));
    }

    public function test_no_guarda_el_html_completo_en_la_auditoria(): void
    {
        $this->actingAs($this->operadora());
        $product = Product::factory()->create();

        app(ActivityRecorder::class)->record(
            ActivityEvent::Updated,
            $product,
            'Descripción actualizada.',
            ['html_description' => '<p>'.str_repeat('contenido largo ', 50).'</p>'],
        );

        $encoded = json_encode(ActivityLog::firstOrFail()->properties);

        $this->assertStringNotContainsString('contenido largo contenido largo', (string) $encoded);
    }

    public function test_registra_la_ip_y_el_identificador_de_peticion(): void
    {
        $this->actingAs($this->operadora());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/up');

        app(ActivityRecorder::class)->record(ActivityEvent::Login, null, 'Acceso correcto.');

        $log = ActivityLog::firstOrFail();

        $this->assertSame('203.0.113.10', $log->ip_address);
        $this->assertNotNull($log->request_id);
        $this->assertNotNull($log->user_agent);
    }

    public function test_el_diff_tambien_redacta_secretos(): void
    {
        $this->actingAs($this->admin());
        $product = Product::factory()->create();

        app(ActivityRecorder::class)->recordChanges(
            ActivityEvent::Updated,
            $product,
            ['api_key' => 'sk-viejo1234567890'],
            ['api_key' => 'sk-nuevo1234567890'],
        );

        $changes = ActivityLog::firstOrFail()->changedAttributes();
        $encoded = json_encode($changes);

        $this->assertStringNotContainsString('sk-viejo1234567890', (string) $encoded);
        $this->assertStringNotContainsString('sk-nuevo1234567890', (string) $encoded);
    }
}
