<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\IssueType;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\SlaZone;
use App\Enums\UnitLevel;
use App\Enums\UserRole;

it('provides accurate cases and helper outputs for all 11 enums', function (): void {
    expect(UserRole::cases())->toHaveCount(5)
        ->and(UserRole::ADMIN_UNIT->requiresUnit())->toBeTrue()
        ->and(UserRole::PETUGAS->requiresUnit())->toBeTrue()
        ->and(UserRole::WARGA->requiresUnit())->toBeFalse()
        ->and(UserRole::INSPEKTORAT->requiresUnit())->toBeFalse()
        ->and(UserRole::SUPER_ADMIN->requiresUnit())->toBeFalse()
        ->and(UserRole::WARGA->label())->toBe('Warga');

    expect(UnitLevel::cases())->toHaveCount(4)
        ->and(UnitLevel::OPD->label())->toBe('OPD Kabupaten');

    expect(IssueType::POINT->defaultRadiusM())->toBe(200)
        ->and(IssueType::LINEAR->defaultRadiusM())->toBe(750)
        ->and(IssueType::POINT->defaultGeotagToleranceM())->toBe(100)
        ->and(IssueType::LINEAR->defaultGeotagToleranceM())->toBe(500);

    expect(ResolutionTrack::cases())->toHaveCount(2)
        ->and(ResolutionTrack::APBD->label())->toContain('APBD');

    expect(ReportStatus::cases())->toHaveCount(11)
        ->and(ReportStatus::REJECTED->isFinal())->toBeTrue()
        ->and(ReportStatus::VERIFIED_CLOSED->isFinal())->toBeTrue()
        ->and(ReportStatus::AUTO_VERIFIED_CLOSED->isFinal())->toBeTrue()
        ->and(ReportStatus::CLOSED_BY_AUDIT->isFinal())->toBeTrue()
        ->and(ReportStatus::IN_PROGRESS->isFinal())->toBeFalse()
        ->and(ReportStatus::IN_PROGRESS->isSlaRunning())->toBeTrue()
        ->and(ReportStatus::REOPENED_BY_AUDIT->isSlaRunning())->toBeTrue()
        ->and(ReportStatus::PENDING_CITIZEN_SIGN_OFF->isSlaRunning())->toBeFalse()
        ->and(ReportStatus::PENDING_CITIZEN_SIGN_OFF->label())->toBe('Menunggu Konfirmasi Warga')
        ->and(ReportStatus::VERIFIED_CLOSED->badgeColor())->toBe('emerald');

    expect(SlaZone::cases())->toHaveCount(6)
        ->and(SlaZone::YELLOW->label())->toBe('Zona Kuning');

    expect(PhotoType::cases())->toHaveCount(4)
        ->and(DisputeStatus::cases())->toHaveCount(3);

    expect(RejectionReasonCode::cases())->toHaveCount(4)
        ->and(RejectionReasonCode::HOAX->label())->toContain('Hoaks');

    expect(AuditActionType::cases())->toHaveCount(26)
        ->and(AuditActionType::SUBMITTED->isPublicVisible())->toBeTrue()
        ->and(AuditActionType::USER_CREATED->isPublicVisible())->toBeFalse()
        ->and(AuditActionType::USER_UPDATED->isPublicVisible())->toBeFalse()
        ->and(AuditActionType::USER_DEACTIVATED->isPublicVisible())->toBeFalse()
        ->and(AuditActionType::CATEGORY_UPDATED->isPublicVisible())->toBeFalse()
        ->and(AuditActionType::UNIT_UPDATED->isPublicVisible())->toBeFalse();

    expect(NotificationType::cases())->toHaveCount(13)
        ->and(NotificationType::SIGN_OFF_REQUESTED->defaultTitle())->not->toBeEmpty();
});
