<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\TechnicalSheetComposition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un permiso que existe en el código pero no en la base de datos (RFC-0008).
 *
 * Es el desfase que tumbó el panel en desarrollo: `Permission::byRole()` es
 * código, así que añadir un permiso al enum no llega a la base hasta sembrar.
 * Entre esos dos momentos, `hasPermissionTo()` lanzaba `PermissionDoesNotExist`
 * y, como Filament consulta las Policies al pintar la barra lateral, el error
 * salía en **todas** las pantallas, incluido el propio dashboard tras el login.
 *
 * La respuesta correcta es denegar y seguir: un permiso que no consta no está
 * concedido, y el panel debe quedarse en pie para poder trabajar en lo demás.
 */
class MissingPermissionResilienceTest extends TestCase
{
    /**
     * Borra un permiso de la base, como si el código fuera por delante del seeder.
     */
    private function removePermission(string $name): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('role_has_permissions')
            ->whereIn('permission_id', static function ($query) use ($name): void {
                $query->select('id')->from('permissions')->where('name', $name);
            })
            ->delete();

        DB::table('permissions')->where('name', $name)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function forgetRelations(User $user): User
    {
        return $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_el_dashboard_aguanta_un_permiso_ausente(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->removePermission(Permission::TechnicalSheetsManage->value);
        $this->forgetRelations($admin);

        // Antes: 500 con PermissionDoesNotExist al pintar la barra lateral.
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/products')->assertOk();
    }

    public function test_la_operadora_tambien_aguanta_el_permiso_ausente(): void
    {
        $operadora = $this->operadora();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->forgetRelations($operadora);

        $this->actingAs($operadora)->get('/admin')->assertOk();
        $this->actingAs($operadora)->get('/admin/products')->assertOk();
    }

    /**
     * Usuario con un único permiso del catálogo.
     *
     * Hace falta aislarlo porque la Policy autoriza si tiene `view` **o**
     * `manage`: con los dos concedidos, retirar uno no cambia el resultado y no
     * se podría observar qué hace el permiso que falta.
     */
    private function withOnlyViewPermission(): User
    {
        // Sin rol: el rol concedería `manage` igualmente y ocultaría el caso que
        // se quiere observar. Un permiso directo aísla la comprobación.
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::TechnicalSheetsView->value);

        return $this->forgetRelations($user);
    }

    public function test_la_policy_deniega_en_lugar_de_fallar(): void
    {
        $user = $this->withOnlyViewPermission();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->forgetRelations($user);

        $this->assertFalse($user->can('viewAny', TechnicalSheetComposition::class));
    }

    public function test_el_mantenimiento_queda_bloqueado_sin_el_permiso(): void
    {
        $admin = User::factory()->adminTecnico()->withTwoFactor()->create();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->removePermission(Permission::TechnicalSheetsManage->value);
        $this->forgetRelations($admin);

        // Se deniega con claridad en lugar de reventar la pantalla.
        $this->actingAs($admin)
            ->get('/admin/technical-sheets/compositions')
            ->assertForbidden();
    }

    public function test_el_desfase_deja_aviso_en_el_log(): void
    {
        // No basta con no fallar: un desfase que no se nota es un desfase que
        // sigue ahí. Se comprueba que quede rastro para poder diagnosticarlo.
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'no existe en la base de datos')
                && ($context['permission'] ?? null) === Permission::TechnicalSheetsView->value);

        $user = $this->withOnlyViewPermission();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->forgetRelations($user);

        $this->assertFalse($user->can('viewAny', TechnicalSheetComposition::class));
    }

    public function test_un_permiso_ausente_no_afecta_a_los_demas(): void
    {
        // La avería no debe contagiar al resto de la autorización: la operadora
        // sigue pudiendo crear fichas, que es su trabajo.
        $operadora = $this->operadora();

        $this->removePermission(Permission::TechnicalSheetsView->value);
        $this->forgetRelations($operadora);

        $this->assertTrue($operadora->can('create', Product::class));
        $this->assertTrue($operadora->can('viewAny', Product::class));
    }
}
