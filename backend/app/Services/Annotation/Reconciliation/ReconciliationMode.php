<?php

namespace App\Services\Annotation\Reconciliation;

enum ReconciliationMode: string
{
    case REPORT_ONLY = 'report_only';
    case AUDIT_WRITING_INSPECTION = 'audit_writing_inspection';
    case APPLY = 'apply';

    public function allowsRepairs(): bool
    {
        return $this === self::APPLY;
    }
}
