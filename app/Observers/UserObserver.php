<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "creating" event.
     */
    public function creating(User $user): void
    {
        if (empty($user->slug)) {
            $user->slug = $user->generateSlug();
        }
    }

    /**
     * Handle the User "updating" event.
     */
    public function updating(User $user): void
    {
        // Regenerate slug if business_name or name changed
        if ($user->isDirty(['business_name', 'name']) && empty($user->slug)) {
            $user->slug = $user->generateSlug();
        }
    }
}