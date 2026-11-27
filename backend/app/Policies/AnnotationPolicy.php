<?php

namespace App\Policies;

use App\Models\User;

class AnnotationPolicy
{
    public function access(User $user): bool
    {
        return $user->hasAnnotationRole();
    }

    public function validate(User $user): bool
    {
        return $user->hasAnnotationRole();
    }

    public function approve(User $user, object $record): bool
    {
        if (! $user->hasAnyAnnotationRole([User::ROLE_REVIEWER, User::ROLE_RESEARCH_ADMIN])) {
            return false;
        }

        $creatorId = data_get($record, 'created_by_user_id');
        if ($creatorId !== null && (int) $creatorId === (int) $user->id) {
            return false; // AD-05: self-approval prohibited by default.
        }

        return true;
    }

    public function exportCanonical(User $user): bool
    {
        return $user->isResearchAdmin();
    }

    public function reconcile(User $user): bool
    {
        return $user->isResearchAdmin();
    }
}
