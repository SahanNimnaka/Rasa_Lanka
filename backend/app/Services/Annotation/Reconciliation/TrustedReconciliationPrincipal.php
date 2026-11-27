<?php

namespace App\Services\Annotation\Reconciliation;

use App\Models\User;

final class TrustedReconciliationPrincipal
{
    private function __construct(
        private readonly int $userId,
    ) {}

    /**
     * Controlled construction for trusted reconciliation callers/tests.
     * Authentication is established by the M0 boundary; this object is an
     * internal application contract and is not cryptographically unforgeable.
     */
    public static function fromAuthenticatedResearchAdmin(User $user): self
    {
        return new self((int) $user->id);
    }

    public function userId(): int
    {
        return $this->userId;
    }
}
