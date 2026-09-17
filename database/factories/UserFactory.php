<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'job_title' => fake()->jobTitle(),
            'locale' => 'es',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function operadora(): static
    {
        return $this->withRole(Role::Operadora);
    }

    public function responsableCatalogo(): static
    {
        return $this->withRole(Role::ResponsableCatalogo);
    }

    public function adminTecnico(): static
    {
        return $this->withRole(Role::AdminTecnico);
    }

    /**
     * Asigna el rol después de crear el usuario, cuando ya existe el id.
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role->value);
        });
    }

    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        });
    }
}
