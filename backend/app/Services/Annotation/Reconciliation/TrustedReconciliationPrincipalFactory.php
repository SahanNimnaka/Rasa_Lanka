<?php

namespace App\Services\Annotation\Reconciliation;

use App\Models\User;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;

final class TrustedReconciliationPrincipalFactory
{
    public function make(User $user): TrustedReconciliationPrincipal
    {
        $fresh = User::query()->whereKey($user->id)->first();

        if (! $fresh instanceof User || ! $fresh->isResearchAdmin()) {
            throw new AnnotationLifecycleAuthorizationException(
                'Reconciliation requires an authenticated, currently authorized research_admin principal.'
            );
        }

        return TrustedReconciliationPrincipal::fromAuthenticatedResearchAdmin($fresh);
    }
}
