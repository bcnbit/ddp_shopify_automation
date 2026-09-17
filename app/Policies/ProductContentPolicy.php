<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ProductContentPolicy
{
    use ChecksPermissions;

    public function view(User $user, ProductContent $content): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $user->can('view', $content->product);
    }

    public function create(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::ContentGenerate) && $user->can('update', $product);
    }

    public function update(User $user, ProductContent $content): bool
    {
        if (! $this->allows($user, Permission::ContentGenerate)) {
            return false;
        }

        // Una versión ya aprobada no se edita: se crea una versión nueva.
        if ($content->isApproved()) {
            return false;
        }

        return $user->can('update', $content->product);
    }

    public function approve(User $user, ProductContent $content): bool
    {
        if (! $this->allows($user, Permission::ContentApprove)) {
            return false;
        }

        return $user->can('update', $content->product);
    }

    public function delete(User $user, ProductContent $content): bool
    {
        if (! $this->allows($user, Permission::ContentGenerate)) {
            return false;
        }

        if ($content->isApproved()) {
            return false;
        }

        return $user->can('update', $content->product);
    }
}
