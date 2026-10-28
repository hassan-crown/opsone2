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

    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerContactAdded = 'customer.contact_added';
    case CustomerContactUpdated = 'customer.contact_updated';
    case CustomerContactPrimaryChanged = 'customer.contact_primary_changed';
    case CustomerContactRemoved = 'customer.contact_removed';

    case InterestCreated = 'interest.created';
    case InterestUpdated = 'interest.updated';
    case InterestFollowed = 'interest.followed';
    case InterestConverted = 'interest.converted';
    case InterestClosed = 'interest.closed';
    case InterestReopened = 'interest.reopened';
    case CatalogProductCreated = 'catalog_product.created';
    case CatalogProductUpdated = 'catalog_product.updated';
    case CatalogProductDisabled = 'catalog_product.disabled';
    case CatalogProductEnabled = 'catalog_product.enabled';
    case CatalogMaterialCreated = 'catalog_material.created';
    case CatalogMaterialUpdated = 'catalog_material.updated';
    case CatalogMaterialDisabled = 'catalog_material.disabled';
    case CatalogMaterialEnabled = 'catalog_material.enabled';
    case CatalogReadyCreated = 'catalog_ready.created';
    case CatalogReadyUpdated = 'catalog_ready.updated';
    case CatalogReadyStatusChanged = 'catalog_ready.status_changed';
    case CatalogVariantCreated = 'catalog_variant.created';
    case CatalogVariantUpdated = 'catalog_variant.updated';
    case CatalogVariantStatusChanged = 'catalog_variant.status_changed';

    case CustomerBranchTransferred = 'customer.branch_transferred';

    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderLineAdded = 'order.line_added';
    case OrderLineRemoved = 'order.line_removed';
    case OrderBranchTransferred = 'order.branch_transferred';

    case FlowTemplateChosen = 'flow.template_chosen';
    case FlowDraftSaved = 'flow.draft_saved';
    case FlowPublished = 'flow.published';
    case FlowDraftDiscarded = 'flow.draft_discarded';
    case OrderPaused = 'order.paused';
    case OrderResumed = 'order.resumed';
    case OrderExecutionFinished = 'order.execution_finished';
    case OrderReopenedForCorrection = 'order.reopened_for_correction';
    case StageCompleted = 'order.stage_completed';
    case ActionAllowed = 'order.action_allowed';
    case ActionReworked = 'order.action_reworked';
    case ActionNeedChanged = 'order.action_need_changed';
    case UpcomingWorkEdited = 'order.upcoming_work_edited';
    case MeasurementRecorded = 'order.measurement_recorded';
    case MeasurementCorrected = 'order.measurement_corrected';
    case TaskAssigned = 'task.assigned';
    case TaskRouted = 'task.routed';
    case TaskReassigned = 'task.reassigned';
    case TaskClaimed = 'task.claimed';
    case TaskCompleted = 'task.completed';
    case TaskCancelled = 'task.cancelled';
    case TaskReleased = 'task.released';
    case AppointmentProposed = 'appointment.proposed';
    case AppointmentSent = 'appointment.sent';
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentChangeRequested = 'appointment.change_requested';
    case AppointmentChangeDiscarded = 'appointment.change_discarded';
    case AppointmentChanged = 'appointment.changed';
    case AppointmentCompleted = 'appointment.completed';
    case AppointmentCancelled = 'appointment.cancelled';
    case WorkSettingsUpdated = 'work_settings.updated';
    case FinanceSettingsUpdated = 'finance_settings.updated';
    case DiscountLimitsUpdated = 'discount_limits.updated';
    case DocumentCreated = 'agreement_document.created';
    case DocumentDraftUpdated = 'agreement_document.draft_updated';
    case DocumentSent = 'agreement_document.sent';
    case DocumentApproved = 'agreement_document.approved';
    case DocumentChangesRequested = 'agreement_document.changes_requested';
    case DocumentReplaced = 'agreement_document.replaced';
    case DocumentExpired = 'agreement_document.expired';
    case DocumentExtended = 'agreement_document.extended';
    case DocumentCancelled = 'agreement_document.cancelled';
    case AgreementActivated = 'agreement.activated';
    case ChangeCreated = 'agreement_change.created';
    case ChangeDraftUpdated = 'agreement_change.draft_updated';
    case ChangePresented = 'agreement_change.presented';
    case ChangeRevisionRequested = 'agreement_change.revision_requested';
    case ChangeApplied = 'agreement_change.applied';
    case ChangeWithdrawn = 'agreement_change.withdrawn';
    case DiscountRequested = 'discount_decision.requested';
    case DiscountApproved = 'discount_decision.approved';
    case DiscountRejected = 'discount_decision.rejected';
    case DiscountCancelled = 'discount_decision.cancelled';
    case FinanceScheduleSynced = 'finance.schedule_synced';
    case FinanceScheduleUpdated = 'finance.schedule_updated';
    case PaymentRecorded = 'finance.payment_recorded';
    case PaymentGatewayResult = 'finance.payment_gateway_result';
    case PaymentReallocated = 'finance.payment_reallocated';
    case CreditTransferred = 'finance.credit_transferred';
    case MoneyReversed = 'finance.money_reversed';
    case ProofSubmitted = 'finance.proof_submitted';
    case ProofConfirmed = 'finance.proof_confirmed';
    case ProofCorrectionRequested = 'finance.proof_correction_requested';
    case ProofCancelled = 'finance.proof_cancelled';
    case CollectionRemindersSent = 'finance.reminders_sent';
    // P6c settlement (ADR-0015).
    case RefundRequested = 'refund.requested';
    case RefundStarted = 'refund.started';
    case RefundExecuted = 'refund.executed';
    case RefundFailed = 'refund.failed';
    case RefundCancelled = 'refund.cancelled';
    case RefundRetried = 'refund.retried';
    case PaymentCorrected = 'finance.payment_corrected';
    case DisputeOpened = 'dispute.opened';
    case DisputeAwaiting = 'dispute.awaiting_external';
    case DisputeResumed = 'dispute.resumed';
    case DisputeResolved = 'dispute.resolved';
    case ExternalDocumentRequested = 'external_document.requested';
    case ExternalDocumentAttached = 'external_document.attached';
    case ExternalDocumentAccepted = 'external_document.accepted';
    case ExternalDocumentCorrectionRequested = 'external_document.correction_requested';

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
            self::CustomerCreated, self::CustomerUpdated, self::CustomerContactAdded, self::CustomerContactUpdated,
            self::CustomerContactPrimaryChanged, self::CustomerContactRemoved, self::CustomerBranchTransferred => 'customers',
            self::InterestCreated, self::InterestUpdated, self::InterestFollowed, self::InterestConverted,
            self::InterestClosed, self::InterestReopened => 'interests',
            self::CatalogProductCreated, self::CatalogProductUpdated, self::CatalogProductDisabled, self::CatalogProductEnabled,
            self::CatalogMaterialCreated, self::CatalogMaterialUpdated, self::CatalogMaterialDisabled, self::CatalogMaterialEnabled,
            self::CatalogReadyCreated, self::CatalogReadyUpdated, self::CatalogReadyStatusChanged,
            self::CatalogVariantCreated, self::CatalogVariantUpdated, self::CatalogVariantStatusChanged => 'catalog',
            self::OrderCreated, self::OrderUpdated, self::OrderLineAdded, self::OrderLineRemoved,
            self::OrderBranchTransferred => 'orders',
            self::FlowTemplateChosen, self::FlowDraftSaved, self::FlowPublished, self::FlowDraftDiscarded, self::OrderPaused, self::OrderResumed, self::OrderExecutionFinished, self::OrderReopenedForCorrection, self::StageCompleted, self::ActionAllowed, self::ActionReworked, self::ActionNeedChanged, self::UpcomingWorkEdited, self::MeasurementRecorded, self::MeasurementCorrected => 'workflow',
            self::TaskAssigned, self::TaskRouted, self::TaskReassigned, self::TaskClaimed, self::TaskCompleted, self::TaskCancelled, self::TaskReleased => 'tasks',
            self::AppointmentProposed, self::AppointmentSent, self::AppointmentConfirmed, self::AppointmentChangeRequested, self::AppointmentChangeDiscarded, self::AppointmentChanged, self::AppointmentCompleted, self::AppointmentCancelled => 'appointments',
            self::WorkSettingsUpdated, self::FinanceSettingsUpdated, self::DiscountLimitsUpdated => 'settings',
            self::DocumentCreated, self::DocumentDraftUpdated, self::DocumentSent, self::DocumentApproved, self::DocumentChangesRequested,
            self::DocumentReplaced, self::DocumentExpired, self::DocumentExtended, self::DocumentCancelled, self::AgreementActivated,
            self::ChangeCreated, self::ChangeDraftUpdated, self::ChangePresented, self::ChangeRevisionRequested, self::ChangeApplied,
            self::ChangeWithdrawn, self::DiscountRequested, self::DiscountApproved, self::DiscountRejected, self::DiscountCancelled => 'agreement',
            self::FinanceScheduleSynced, self::FinanceScheduleUpdated, self::PaymentRecorded, self::PaymentGatewayResult,
            self::PaymentReallocated, self::CreditTransferred, self::MoneyReversed, self::ProofSubmitted, self::ProofConfirmed,
            self::ProofCorrectionRequested, self::ProofCancelled, self::CollectionRemindersSent => 'finance',
            self::RefundRequested, self::RefundStarted, self::RefundExecuted, self::RefundFailed, self::RefundCancelled, self::RefundRetried, self::PaymentCorrected, self::DisputeOpened, self::DisputeAwaiting, self::DisputeResumed, self::DisputeResolved, self::ExternalDocumentRequested, self::ExternalDocumentAttached, self::ExternalDocumentAccepted, self::ExternalDocumentCorrectionRequested => 'finance',
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
