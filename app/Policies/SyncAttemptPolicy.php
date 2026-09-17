<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SyncAttempt;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class SyncAttemptPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SyncRetry);
    }

    public function view(User $user, SyncAttempt $attempt): bool
    {
        if (! $this->allowsAny($user, Permission::ProductsView, Permission::ProductsViewAll)) {
            return false;
        }

        return $user->can('view', $attempt->product);
    }

    public function retry(User $user, SyncAttempt $attempt): bool
    {
        if (! $this->allows($user, Permission::SyncRetry)) {
            return false;
        }

        return $attempt->isFailed() && $attempt->is_retryable;
    }
}
