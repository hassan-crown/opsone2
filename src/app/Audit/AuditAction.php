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
    case CashProofPolicyUpdated = 'finance_settings.cash_proof_updated';
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
    case CommissionSettingsUpdated = 'commission.settings_updated';
    case CommissionPayoutCreated = 'commission.payout_created';
    case CommissionPayoutReserved = 'commission.payout_reserved';
    case CommissionPayoutPaid = 'commission.payout_paid';
    case CommissionPayoutCancelled = 'commission.payout_cancelled';
    case CommissionPayoutCorrected = 'commission.payout_corrected';
    case SaleCorrectionRequested = 'commission.sale_correction_requested';
    case SaleCorrectionApproved = 'commission.sale_correction_approved';
    case SaleCorrectionRejected = 'commission.sale_correction_rejected';
    case SaleCorrectionCancelled = 'commission.sale_correction_cancelled';
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
    // P7a inventory (ADR-0017).
    case StockLocationSaved = 'stock.location_saved';
    case StockItemUpdated = 'stock.item_updated';
    case InventorySettingsUpdated = 'stock.settings_updated';
    case StockAdjusted = 'stock.adjusted';
    case StockReserved = 'stock.reserved';
    case StockReleased = 'stock.released';
    case StockIssued = 'stock.issued';
    case StockTransferCreated = 'stock.transfer_created';
    case StockTransferMoved = 'stock.transfer_moved';
    case StockCountRecorded = 'stock.count_recorded';
    case ReadyOrderConfirmed = 'order.ready_confirmed';
    case ReadyOrderCancelled = 'order.ready_cancelled';
    // P7 procurement (domain 06, M-13).
    case SupplierCreated = 'supplier.created';
    case SupplierUpdated = 'supplier.updated';
    case SupplierDisabled = 'supplier.disabled';
    case SupplierEnabled = 'supplier.enabled';
    case PurchaseCreated = 'purchase.created';
    case PurchaseUpdated = 'purchase.updated';
    case PurchaseConfirmed = 'purchase.confirmed';
    case PurchaseReceived = 'purchase.received';
    case PurchaseAdjusted = 'purchase.adjusted';
    case PurchaseRemainderCancelled = 'purchase.remainder_cancelled';
    // P7 delivery (domain 06, M-14).
    case DeliveryDrafted = 'delivery.drafted';
    case DeliveryConfirmed = 'delivery.confirmed';
    case DeliveryCancelled = 'delivery.cancelled';
    // P7 cancellation (domain 02/06, M-34) and the settlement follow-up (INT-08).
    case CancellationRequested = 'cancellation.requested';
    case CancellationAccepted = 'cancellation.accepted';
    case CancellationRejected = 'cancellation.rejected';
    case SettlementItemResolved = 'settlement.item_resolved';
    case SettlementClosed = 'settlement.closed';
    // P7 returns (domain 06, M-15).
    case ReturnRequested = 'return.requested';
    case ReturnAccepted = 'return.accepted';
    case ReturnRejected = 'return.rejected';
    case ReturnReceived = 'return.received';
    case ReturnInspected = 'return.inspected';
    case ReturnWithdrawn = 'return.withdrawn';
    case ReturnCorrected = 'return.corrected';
    case ReturnRefundRequested = 'return.refund_requested';
    case ReturnSettingsUpdated = 'return.settings_updated';
    // P8 customer workspace (domain 07, ADR-0022).
    case PortalInvited = 'portal.invited';
    case PortalSignedIn = 'portal.signed_in';
    case PortalSignedOut = 'portal.signed_out';
    case PortalSessionsEnded = 'portal.sessions_ended';
    case PortalSignInLocked = 'portal.sign_in_locked';
    case CancellationWithdrawn = 'cancellation.withdrawn';
    case AppointmentCustomerChangeRequested = 'appointment.customer_change_requested';

    // P8 public site, theme and domain (domain 07, M-17 / M-35).
    case SitePageCreated = 'site_page.created';
    case SitePageDraftSaved = 'site_page.draft_saved';
    case SitePagePublished = 'site_page.published';
    case SitePageUnpublished = 'site_page.unpublished';
    case SitePageDraftDiscarded = 'site_page.draft_discarded';
    case SitePageArchived = 'site_page.archived';
    case SitePageRestored = 'site_page.restored';
    case SiteThemeDraftSaved = 'site_theme.draft_saved';
    case SiteThemeApplied = 'site_theme.applied';
    case SiteThemeDraftDiscarded = 'site_theme.draft_discarded';
    case SiteThemeRestored = 'site_theme.restored';
    case SiteDomainRequested = 'site_domain.requested';
    case SiteDomainVerified = 'site_domain.verified';
    case SiteDomainRemoved = 'site_domain.removed';

    public function area(): string
    {
        return match ($this) {
            self::SitePageCreated, self::SitePageDraftSaved, self::SitePagePublished, self::SitePageUnpublished, self::SitePageDraftDiscarded, self::SitePageArchived, self::SitePageRestored, self::SiteThemeDraftSaved, self::SiteThemeApplied, self::SiteThemeDraftDiscarded, self::SiteThemeRestored, self::SiteDomainRequested, self::SiteDomainVerified, self::SiteDomainRemoved => 'site',
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
            self::OrderBranchTransferred, self::ReadyOrderConfirmed, self::ReadyOrderCancelled => 'orders',
            self::StockLocationSaved, self::StockItemUpdated, self::InventorySettingsUpdated, self::StockAdjusted, self::StockReserved,
            self::StockReleased, self::StockIssued, self::StockTransferCreated, self::StockTransferMoved, self::StockCountRecorded => 'stock',
            self::FlowTemplateChosen, self::FlowDraftSaved, self::FlowPublished, self::FlowDraftDiscarded, self::OrderPaused, self::OrderResumed, self::OrderExecutionFinished, self::OrderReopenedForCorrection, self::StageCompleted, self::ActionAllowed, self::ActionReworked, self::ActionNeedChanged, self::UpcomingWorkEdited, self::MeasurementRecorded, self::MeasurementCorrected => 'workflow',
            self::TaskAssigned, self::TaskRouted, self::TaskReassigned, self::TaskClaimed, self::TaskCompleted, self::TaskCancelled, self::TaskReleased => 'tasks',
            self::AppointmentProposed, self::AppointmentSent, self::AppointmentConfirmed, self::AppointmentChangeRequested, self::AppointmentChangeDiscarded, self::AppointmentChanged, self::AppointmentCompleted, self::AppointmentCancelled, self::AppointmentCustomerChangeRequested => 'appointments',
            self::WorkSettingsUpdated, self::FinanceSettingsUpdated, self::CashProofPolicyUpdated, self::DiscountLimitsUpdated => 'settings',
            self::DocumentCreated, self::DocumentDraftUpdated, self::DocumentSent, self::DocumentApproved, self::DocumentChangesRequested,
            self::DocumentReplaced, self::DocumentExpired, self::DocumentExtended, self::DocumentCancelled, self::AgreementActivated,
            self::ChangeCreated, self::ChangeDraftUpdated, self::ChangePresented, self::ChangeRevisionRequested, self::ChangeApplied,
            self::ChangeWithdrawn, self::DiscountRequested, self::DiscountApproved, self::DiscountRejected, self::DiscountCancelled => 'agreement',
            self::FinanceScheduleSynced, self::FinanceScheduleUpdated, self::PaymentRecorded, self::PaymentGatewayResult,
            self::PaymentReallocated, self::CreditTransferred, self::MoneyReversed, self::ProofSubmitted, self::ProofConfirmed,
            self::ProofCorrectionRequested, self::ProofCancelled, self::CollectionRemindersSent => 'finance',
            self::CommissionSettingsUpdated, self::CommissionPayoutCreated, self::CommissionPayoutReserved, self::CommissionPayoutPaid,
            self::CommissionPayoutCancelled, self::CommissionPayoutCorrected, self::SaleCorrectionRequested,
            self::SaleCorrectionApproved, self::SaleCorrectionRejected, self::SaleCorrectionCancelled => 'commissions',
            self::SupplierCreated, self::SupplierUpdated, self::SupplierDisabled, self::SupplierEnabled,
            self::PurchaseCreated, self::PurchaseUpdated, self::PurchaseConfirmed, self::PurchaseReceived,
            self::PurchaseAdjusted, self::PurchaseRemainderCancelled => 'procurement',
            self::DeliveryDrafted, self::DeliveryConfirmed, self::DeliveryCancelled => 'delivery',
            self::CancellationRequested, self::CancellationAccepted, self::CancellationRejected, self::CancellationWithdrawn,
            self::SettlementItemResolved, self::SettlementClosed => 'cancellation',
            self::ReturnRequested, self::ReturnAccepted, self::ReturnRejected, self::ReturnReceived, self::ReturnInspected,
            self::ReturnWithdrawn, self::ReturnCorrected, self::ReturnRefundRequested, self::ReturnSettingsUpdated => 'returns',
            self::PortalInvited, self::PortalSignedIn, self::PortalSignedOut, self::PortalSessionsEnded, self::PortalSignInLocked => 'portal',
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
