<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditActionType: string
{
    case SUBMITTED = 'SUBMITTED';
    case ROUTED = 'ROUTED';
    case REROUTED = 'REROUTED';
    case REJECTED = 'REJECTED';
    case ASSIGNED = 'ASSIGNED';
    case OFFICER_CHANGED = 'OFFICER_CHANGED';
    case BUDGET_SCHEDULED = 'BUDGET_SCHEDULED';
    case MILESTONE_UPDATED = 'MILESTONE_UPDATED';
    case MILESTONE_OVERDUE = 'MILESTONE_OVERDUE';
    case EXECUTION_STARTED = 'EXECUTION_STARTED';
    case PROGRESS_NOTE = 'PROGRESS_NOTE';
    case SLA_WARNING = 'SLA_WARNING';
    case SLA_OVERDUE = 'SLA_OVERDUE';
    case EVIDENCE_SUBMITTED = 'EVIDENCE_SUBMITTED';
    case CITIZEN_APPROVED = 'CITIZEN_APPROVED';
    case CITIZEN_REJECTED = 'CITIZEN_REJECTED';
    case AUTO_VERIFIED = 'AUTO_VERIFIED';
    case AUDIT_REOPENED = 'AUDIT_REOPENED';
    case AUDIT_CLOSED = 'AUDIT_CLOSED';
    case RATED = 'RATED';
    case UPVOTED = 'UPVOTED';
    case USER_CREATED = 'USER_CREATED';
    case USER_UPDATED = 'USER_UPDATED';
    case USER_DEACTIVATED = 'USER_DEACTIVATED';
    case CATEGORY_UPDATED = 'CATEGORY_UPDATED';
    case UNIT_UPDATED = 'UNIT_UPDATED';

    public function isPublicVisible(): bool
    {
        return match ($this) {
            self::USER_CREATED,
            self::USER_UPDATED,
            self::USER_DEACTIVATED,
            self::CATEGORY_UPDATED,
            self::UNIT_UPDATED => false,
            default => true,
        };
    }
}
