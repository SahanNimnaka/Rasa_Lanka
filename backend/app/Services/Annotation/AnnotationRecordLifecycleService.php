<?php

namespace App\Services\Annotation;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\User;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleConflictException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleValidationException;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnotationRecordLifecycleService
{
    public function __construct(
        private readonly AnnotationArtifactStore $store,
    ) {}

    /**
     * Create a new draft annotation record with an initial versioned draft artifact.
     *
     * @return array{record:AnnotationRecord,artifact:array{record_id:string,draft_version_id:string,relative_path:string,sha256:string,bytes:int}}
     */
    public function createDraft(User $actor, array $draftPayload, ?string $recordId = null, ?string $verificationStatus = 'pending_confirmation', string $recognitionMethod = 'individual'): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();

        $recordId = $this->normalizeIdentifier($recordId ?? $this->generateRecordId(), 'record_id');
        $verificationStatus = $this->normalizeVerificationStatus($verificationStatus);
        $recognitionMethod = $this->normalizeRecognitionMethod($recognitionMethod);
        $workflowStatus = 'draft';
        $this->assertWorkflowVerificationAllowed($workflowStatus, $verificationStatus);

        if (AnnotationRecord::query()->where('record_id', $recordId)->exists()) {
            throw new AnnotationLifecycleConflictException('Annotation record_id already exists.');
        }

        $content = $this->encodeDraftPayload($draftPayload, $recordId);

        $artifact = $this->store->writeDraftVersion($recordId, $content);
        $verified = $this->store->verifyStoredArtifact($artifact['relative_path'], $artifact['sha256']);

        try {
            $record = DB::transaction(function () use ($actor, $recordId, $verificationStatus, $recognitionMethod, $artifact, $verified): AnnotationRecord {
                if (AnnotationRecord::query()->where('record_id', $recordId)->exists()) {
                    throw new AnnotationLifecycleConflictException('Annotation record_id already exists.');
                }

                $record = new AnnotationRecord;
                $record->record_id = $recordId;
                $record->forceFill([
                    'schema_version' => 'human-recognition-v1',
                    'recognition_method' => $recognitionMethod,
                    'verification_status' => $verificationStatus,
                    'workflow_status' => 'draft',
                    'artifact_state' => 'draft',
                    'current_draft_version_id' => $artifact['draft_version_id'],
                    'current_draft_path' => $artifact['relative_path'],
                    'current_draft_sha256' => $verified['sha256'],
                    'submission_state' => 'none',
                    'created_by_user_id' => $actor->id,
                    'is_export_eligible_canonical' => false,
                    'latest_b1_valid' => null,
                    'lock_version' => 1,
                ]);
                $record->save();

                $this->appendAudit(
                    record: $record,
                    actor: $actor,
                    actorType: 'user',
                    action: 'created',
                    fromWorkflowStatus: null,
                    toWorkflowStatus: 'draft',
                    comment: null,
                    metadata: [
                        'lock_version' => 1,
                        'draft_version_id' => $artifact['draft_version_id'],
                        'draft_sha256' => $verified['sha256'],
                        'draft_path' => $artifact['relative_path'],
                    ],
                );

                return $record->fresh();
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($this->isRecordIdUniqueViolation($e, $recordId)) {
                // Concurrent collision: artifact may be an orphan in the record namespace; reconciliation can clean it.
                throw new AnnotationLifecycleConflictException('Annotation record_id already exists.', 0, $e);
            }
            throw $e;
        } catch (\Throwable $e) {
            // Draft artifact may remain as an orphan; reconciliation can clean it later.
            throw $e;
        }

        return ['record' => $record, 'artifact' => $artifact];
    }

    /**
     * Save an existing draft as a new versioned draft artifact.
     *
     * @return array{record:AnnotationRecord,artifact:array{record_id:string,draft_version_id:string,relative_path:string,sha256:string,bytes:int}}
     */
    public function saveDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion, array $draftPayload, ?string $verificationStatus = null): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();
        $this->assertCanEditDraft($record, $actor, $expectedLockVersion);

        $nextVerificationStatus = $verificationStatus !== null
            ? $this->normalizeVerificationStatus($verificationStatus)
            : (string) $record->verification_status;
        $this->assertWorkflowVerificationAllowed('draft', $nextVerificationStatus);

        $content = $this->encodeDraftPayload($draftPayload, (string) $record->record_id);

        $artifact = $this->store->writeDraftVersion((string) $record->record_id, $content);
        $verified = $this->store->verifyStoredArtifact($artifact['relative_path'], $artifact['sha256']);

        $updated = DB::transaction(function () use ($record, $actor, $expectedLockVersion, $artifact, $verified, $nextVerificationStatus): AnnotationRecord {
            $locked = $this->lockRecord($record->id);
            $this->assertCanEditDraft($locked, $actor, $expectedLockVersion);
            $this->assertWorkflowVerificationAllowed('draft', $nextVerificationStatus);

            $previousVersionId = $locked->current_draft_version_id;
            $previousPath = $locked->current_draft_path;
            $previousSha = $locked->current_draft_sha256;
            $nextLockVersion = (int) $locked->lock_version + 1;

            $updatedRows = AnnotationRecord::query()
                ->whereKey($locked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereIn('submission_state', ['none', 'b1_infra_failed'])
                ->update([
                    'verification_status' => $nextVerificationStatus,
                    'current_draft_version_id' => $artifact['draft_version_id'],
                    'current_draft_path' => $artifact['relative_path'],
                    'current_draft_sha256' => $verified['sha256'],
                    'lock_version' => $nextLockVersion,
                    'updated_at' => now(),
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($locked->id);
                $this->assertCanEditDraft($fresh, $actor, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }

            $locked = $this->lockRecord($locked->id);

            $this->appendAudit(
                record: $locked,
                actor: $actor,
                actorType: 'user',
                action: 'draft_saved',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'lock_version' => $locked->lock_version,
                    'previous_lock_version' => $expectedLockVersion,
                    'previous_draft_version_id' => $previousVersionId,
                    'previous_draft_path' => $previousPath,
                    'previous_draft_sha256' => $previousSha,
                    'draft_version_id' => $artifact['draft_version_id'],
                    'draft_path' => $artifact['relative_path'],
                    'draft_sha256' => $verified['sha256'],
                ],
            );

            return $locked->fresh();
        });

        return ['record' => $updated, 'artifact' => $artifact];
    }

    public function discardDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion, string $reason): AnnotationRecord
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();
        $reason = trim($reason);
        if ($reason === '') {
            throw new AnnotationLifecycleValidationException('Discard reason is required.');
        }

        return DB::transaction(function () use ($record, $actor, $expectedLockVersion, $reason): AnnotationRecord {
            $locked = $this->lockRecord($record->id);
            $this->assertDiscardAuthorized($locked, $actor);
            $this->assertDraftDiscardable($locked, $expectedLockVersion);

            $nextLockVersion = (int) $locked->lock_version + 1;
            $discardedAt = now();

            $updatedRows = AnnotationRecord::query()
                ->whereKey($locked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereIn('submission_state', ['none', 'b1_infra_failed'])
                ->update([
                    'workflow_status' => 'discarded',
                    'discarded_at' => $discardedAt,
                    'discard_reason' => $reason,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $discardedAt,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($locked->id);
                $this->assertDiscardAuthorized($fresh, $actor);
                $this->assertDraftDiscardable($fresh, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }

            $locked = $this->lockRecord($locked->id);

            $this->appendAudit(
                record: $locked,
                actor: $actor,
                actorType: 'user',
                action: 'draft_discarded',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'discarded',
                comment: $reason,
                metadata: [
                    'lock_version' => $locked->lock_version,
                    'previous_lock_version' => $expectedLockVersion,
                    'discard_reason' => $reason,
                ],
            );

            return $locked->fresh();
        });
    }

    private function lockRecord(int $id): AnnotationRecord
    {
        $query = AnnotationRecord::query()->whereKey($id);
        if ($this->supportsRowLock()) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if (! $record instanceof AnnotationRecord) {
            throw new AnnotationLifecycleConflictException('Annotation record not found.');
        }

        return $record;
    }

    private function supportsRowLock(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'pgsql'], true);
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new AnnotationLifecycleConflictException('Unsupported database driver for annotation lifecycle: '.$driver);
        }
    }

    private function requireAnnotationRole(User $actor): void
    {
        if (! $actor->hasAnnotationRole()) {
            throw new AnnotationLifecycleAuthorizationException('User is not authorized for annotation lifecycle operations.');
        }
    }

    private function isRecordIdUniqueViolation(QueryException $e, string $recordId): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        $unique = $sqlState === '23505' || $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19 || $driverCode === 2067 || $driverCode === 1555;
        if (! $unique) {
            return false;
        }

        if (str_contains($message, 'record_id')) {
            return true;
        }

        return AnnotationRecord::query()->where('record_id', $recordId)->exists();
    }

    private function assertCanEditDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion): void
    {
        if ((int) $record->created_by_user_id !== (int) $actor->id) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner may edit this draft.');
        }

        $this->assertDraftMutable($record, $expectedLockVersion);
    }

    private function assertDraftDiscardable(AnnotationRecord $record, int $expectedLockVersion): void
    {
        $this->assertDraftMutable($record, $expectedLockVersion);
    }

    private function assertDraftMutable(AnnotationRecord $record, int $expectedLockVersion): void
    {
        if ((string) $record->workflow_status !== 'draft') {
            throw new AnnotationLifecycleConflictException('Only draft records can be modified by this operation.');
        }
        if ((string) $record->artifact_state !== 'draft') {
            throw new AnnotationLifecycleConflictException('Finalized records cannot be modified as drafts.');
        }
        if ($record->discarded_at !== null || (string) $record->submission_state === 'discarded') {
            throw new AnnotationLifecycleConflictException('Discarded records cannot be modified.');
        }
        if (! in_array((string) $record->submission_state, ['none', 'b1_infra_failed'], true)) {
            throw new AnnotationLifecycleConflictException('Draft cannot be modified while a submission intent is in progress.');
        }
        if ($expectedLockVersion < 1 || (int) $record->lock_version !== $expectedLockVersion) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }
    }

    private function assertDiscardAuthorized(AnnotationRecord $record, User $actor): void
    {
        $isOwner = (int) $record->created_by_user_id === (int) $actor->id;
        $isResearchAdmin = $actor->isResearchAdmin();
        if (! $isOwner && ! $isResearchAdmin) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner or research_admin may discard this draft.');
        }
    }

    private function assertWorkflowVerificationAllowed(string $workflowStatus, string $verificationStatus): void
    {
        $allowed = [
            'draft' => ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'],
            'discarded' => ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'],
        ];

        $allowedForWorkflow = $allowed[$workflowStatus] ?? null;
        if ($allowedForWorkflow === null || ! in_array($verificationStatus, $allowedForWorkflow, true)) {
            throw new AnnotationLifecycleValidationException('Invalid workflow_status/verification_status combination.');
        }
    }

    private function normalizeVerificationStatus(string $status): string
    {
        $status = trim($status);
        $allowed = ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'];
        if (! in_array($status, $allowed, true)) {
            throw new AnnotationLifecycleValidationException('Invalid verification_status.');
        }

        return $status;
    }

    private function normalizeRecognitionMethod(string $method): string
    {
        $method = trim($method);
        $allowed = ['individual', 'group'];
        if (! in_array($method, $allowed, true)) {
            throw new AnnotationLifecycleValidationException('Invalid recognition_method.');
        }

        return $method;
    }

    private function normalizeIdentifier(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $value) !== 1) {
            throw new AnnotationLifecycleValidationException('Invalid '.$field.'.');
        }

        return $value;
    }

    private function generateRecordId(): string
    {
        return 'ar-'.Str::lower((string) Str::uuid());
    }

    /**
     * @param  array<string,mixed>  $draftPayload
     */
    private function encodeDraftPayload(array $draftPayload, string $recordId): string
    {
        $payload = $draftPayload;
        $payload['record_id'] = $recordId;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new AnnotationLifecycleValidationException('Draft payload could not be encoded as JSON.');
        }

        return $json.PHP_EOL;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function appendAudit(AnnotationRecord $record, ?User $actor, string $actorType, string $action, ?string $fromWorkflowStatus, ?string $toWorkflowStatus, ?string $comment, array $metadata): void
    {
        AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'from_workflow_status' => $fromWorkflowStatus,
            'to_workflow_status' => $toWorkflowStatus,
            'comment' => $comment,
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }
}
