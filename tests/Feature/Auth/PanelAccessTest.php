<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acceso al panel (RFC-0001).
 *
 * Comprueba que la autorización no depende de ocultar botones: un invitado es
 * redirigido al login y un administrador técnico sin segundo factor no puede
 * entrar al panel.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_invitado_es_redirigido_al_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_la_pantalla_de_login_es_accesible(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_una_operadora_activa_entra_al_panel(): void
    {
        $operadora = $this->operadora();

        $this->actingAs($operadora)->get('/admin')->assertOk();
    }

    public function test_un_usuario_inactivo_no_entra_al_panel(): void
    {
        $inactiva = User::factory()->operadora()->inactive()->create();

        $this->actingAs($inactiva)->get('/admin')->assertForbidden();
    }

    public function test_un_usuario_sin_rol_no_entra_al_panel(): void
    {
        $sinRol = User::factory()->create();

        $this->actingAs($sinRol)->get('/admin')->assertForbidden();
    }

    public function test_el_administrador_tecnico_sin_segundo_factor_es_redirigido_a_configurarlo(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/', (string) $response->headers->get('Location'));
    }

    public function test_el_administrador_tecnico_con_segundo_factor_entra_al_panel(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        $this->assertTrue($admin->hasTwoFactorEnabled());

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_la_operadora_no_necesita_segundo_factor(): void
    {
        $operadora = $this->operadora();

        $this->assertFalse($operadora->hasTwoFactorEnabled());
        $this->actingAs($operadora)->get('/admin')->assertOk();
    }

    public function test_las_respuestas_llevan_identificador_de_peticion(): void
    {
        $this->get('/admin/login')->assertHeader('X-Request-Id');
    }

    public function test_el_health_check_responde(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_el_segundo_factor_se_guarda_cifrado(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        $raw = DB::table('users')
            ->where('id', $admin->getKey())
            ->value('app_authentication_secret');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_el_secreto_de_dos_factores_no_se_serializa(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        $serialized = $admin->toArray();

        $this->assertArrayNotHasKey('app_authentication_secret', $serialized);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $serialized);
        $this->assertArrayNotHasKey('password', $serialized);
    }

    public function test_los_roles_del_panel_son_los_del_rfc_0000(): void
    {
        $this->assertTrue($this->operadora()->hasRole(Role::Operadora->value));
        $this->assertTrue($this->responsable()->hasRole(Role::ResponsableCatalogo->value));
        $this->assertTrue($this->admin()->hasRole(Role::AdminTecnico->value));
    }

    public function test_la_operadora_no_puede_publicar_pero_si_enviar_borradores(): void
    {
        $operadora = $this->operadora();

        $this->assertFalse($operadora->can(Permission::ProductsPublish->value));
        $this->assertTrue($operadora->can(Permission::ProductsSync->value));
    }
}
