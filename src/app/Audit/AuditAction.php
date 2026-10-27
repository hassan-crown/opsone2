<?php

namespace App\Audit;

/**
 * Audited actions. The value is stored; the area groups actions for the AuditLog filter.
 * Labels are translated with `audit.action.{value}` and `audit.area.{area}`. Never rename a value.
 */
enum AuditAction: string
{
    case InvitationCreated = 'invitation.created';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationCancelled = 'invitation.cancelled';
    case InvitationRenewed = 'invitation.renewed';
    case OwnerInvitationCreated = 'owner_invitation.created';
    case OwnerEstablished = 'owner.established';

    case MembershipDisabled = 'membership.disabled';
    case MembershipReactivated = 'membership.reactivated';
    case MembershipAccessUpdated = 'membership.access_updated';
    case RoleTemplateUpdated = 'role.updated';

    case OwnershipTransferRequested = 'ownership.requested';
    case OwnershipTransferAccepted = 'ownership.accepted';
    case OwnershipTransferRejected = 'ownership.rejected';
    case OwnershipTransferCancelled = 'ownership.cancelled';

    case BranchCreated = 'branch.created';
    case BranchUpdated = 'branch.updated';
    case BranchDisabled = 'branch.disabled';
    case BranchReactivated = 'branch.reactivated';

    case FacilityProfileUpdated = 'settings.profile_updated';
    case SetupStepSaved = 'settings.setup_step';
    case SetupCompleted = 'settings.setup_completed';

    case LicenseInstalled = 'license.installed';

    case AttachmentUploaded = 'attachment.uploaded';
    case AttachmentDownloaded = 'attachment.downloaded';

    case SmtpSettingsSaved = 'integrations.smtp_saved';
    case SmtpSettingsTested = 'integrations.smtp_tested';
    case SmtpEnabled = 'integrations.smtp_enabled';
    case SmtpDisabled = 'integrations.smtp_disabled';

    case SupportRequestSubmitted = 'support.request_submitted';
    case SupportAccessRequested = 'support_access.requested';
    case SupportAccessGranted = 'support_access.granted';
    case SupportAccessRejected = 'support_access.rejected';
    case SupportAccessRevoked = 'support_access.revoked';
    case SupportAccessExpired = 'support_access.expired';
    case SupportSignedIn = 'support_access.signed_in';
    case SupportViewed = 'support_access.viewed';

    public function area(): string
    {
        return match ($this) {
            self::InvitationCreated, self::InvitationAccepted, self::InvitationCancelled, self::InvitationRenewed,
            self::OwnerInvitationCreated, self::OwnerEstablished,
            self::MembershipDisabled, self::MembershipReactivated, self::MembershipAccessUpdated => 'team',
            self::RoleTemplateUpdated => 'roles',
            self::OwnershipTransferRequested, self::OwnershipTransferAccepted,
            self::OwnershipTransferRejected, self::OwnershipTransferCancelled => 'ownership',
            self::BranchCreated, self::BranchUpdated, self::BranchDisabled, self::BranchReactivated => 'branches',
            self::FacilityProfileUpdated, self::SetupStepSaved, self::SetupCompleted => 'settings',
            self::LicenseInstalled => 'license',
            self::AttachmentUploaded, self::AttachmentDownloaded => 'files',
            self::SmtpSettingsSaved, self::SmtpSettingsTested, self::SmtpEnabled, self::SmtpDisabled => 'integrations',
            self::SupportRequestSubmitted, self::SupportAccessRequested, self::SupportAccessGranted,
            self::SupportAccessRejected, self::SupportAccessRevoked, self::SupportAccessExpired,
            self::SupportSignedIn, self::SupportViewed => 'support',
        };
    }

    /**
     * @return list<string>
     */
    public static function areas(): array
    {
        return array_values(array_unique(array_map(fn (self $a) => $a->area(), self::cases())));
    }
}
