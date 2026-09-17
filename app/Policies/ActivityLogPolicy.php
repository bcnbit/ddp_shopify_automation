<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ActivityLogPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function view(User $user, ActivityLog $log): bool
    {
        if (! $this->allows($user, Permission::AuditView)) {
            return false;
        }

        if ($log->subject_type === Product::class) {
            $product = Product::find($log->subject_id);

            return $product !== null && $user->can('view', $product);
        }

        return $this->allows($user, Permission::ProductsViewAll);
    }

    public function delete(User $user, ActivityLog $log): bool
    {
        return false;
    }
}
