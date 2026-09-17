<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class RoleAndPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_los_tres_roles_del_rfc_0000(): void
    {
        $this->assertSame(3, RoleModel::count());

        foreach (Role::cases() as $role) {
            $this->assertTrue(RoleModel::where('name', $role->value)->exists(), "Falta el rol {$role->value}");
        }
    }

    public function test_crea_todos_los_permisos(): void
    {
        $this->assertSame(count(Permission::cases()), PermissionModel::count());
    }

    public function test_es_idempotente(): void
    {
        $rolesBefore = RoleModel::count();
        $permissionsBefore = PermissionModel::count();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertSame($rolesBefore, RoleModel::count());
        $this->assertSame($permissionsBefore, PermissionModel::count());
    }

    public function test_la_operadora_no_puede_publicar_ni_configurar_conexiones(): void
    {
        $operadora = $this->operadora();

        $this->assertFalse($operadora->hasPermissionTo(Permission::ProductsPublish->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::SettingsManage->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::UsersManage->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::AuditView->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::SyncRetry->value));
        $this->assertFalse($operadora->hasPermissionTo(Permission::ProductsApprove->value));
    }

    public function test_la_operadora_si_puede_trabajar_sus_fichas(): void
    {
        $operadora = $this->operadora();

        $this->assertTrue($operadora->hasPermissionTo(Permission::ProductsView->value));
        $this->assertTrue($operadora->hasPermissionTo(Permission::ProductsCreate->value));
        $this->assertTrue($operadora->hasPermissionTo(Permission::ProductsUpdate->value));
        $this->assertTrue($operadora->hasPermissionTo(Permission::MediaUpload->value));
        $this->assertTrue($operadora->hasPermissionTo(Permission::ContentGenerate->value));
        $this->assertTrue($operadora->hasPermissionTo(Permission::ProductsSync->value));
    }

    public function test_la_operadora_no_ve_las_fichas_de_otras_personas(): void
    {
        $this->assertFalse($this->operadora()->hasPermissionTo(Permission::ProductsViewAll->value));
    }

    public function test_el_responsable_puede_aprobar_y_ver_todo_pero_no_publicar(): void
    {
        $responsable = $this->responsable();

        $this->assertTrue($responsable->hasPermissionTo(Permission::ProductsApprove->value));
        $this->assertTrue($responsable->hasPermissionTo(Permission::ContentApprove->value));
        $this->assertTrue($responsable->hasPermissionTo(Permission::ProductsViewAll->value));

        $this->assertFalse($responsable->hasPermissionTo(Permission::ProductsPublish->value));
        $this->assertFalse($responsable->hasPermissionTo(Permission::SettingsManage->value));
        $this->assertFalse($responsable->hasPermissionTo(Permission::UsersManage->value));
    }

    public function test_el_administrador_tecnico_tiene_todos_los_permisos(): void
    {
        $admin = $this->admin();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission->value),
                "El administrador debería tener {$permission->value}",
            );
        }
    }

    public function test_el_reparto_de_permisos_cubre_todos_los_roles_y_permisos_validos(): void
    {
        $map = Permission::byRole();

        $this->assertSame(Role::values(), array_keys($map));

        foreach (Permission::cases() as $permission) {
            $assigned = false;

            foreach ($map as $permissions) {
                if (in_array($permission->value, $permissions, true)) {
                    $assigned = true;

                    break;
                }
            }

            $this->assertTrue($assigned, "El permiso {$permission->value} no está asignado a ningún rol");
        }
    }

    public function test_un_usuario_inactivo_no_puede_acceder_al_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $inactive = User::factory()->adminTecnico()->inactive()->create();

        $this->assertFalse($inactive->canAccessPanel($panel));
    }

    public function test_un_usuario_activo_puede_acceder_al_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($this->operadora()->canAccessPanel($panel));
        $this->assertTrue($this->admin()->canAccessPanel($panel));
    }
}
