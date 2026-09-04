<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Audit event names.
 *
 * Declared as constants so that the string written at the call site and the
 * string searched for during an investigation cannot diverge by a typo.
 *
 * Only the events this phase can actually emit are listed. Money, provider and
 * server events arrive with the code that performs those actions.
 */
final class AuditEvent
{
    public const AdminCreated = 'admin.created';

    public const RolesSynced = 'admin.roles_synced';

    public const RoleAssigned = 'admin.role_assigned';

    public const TwoFactorEnrolmentStarted = 'admin.two_factor_enrolment_started';

    public const TwoFactorConfirmed = 'admin.two_factor_confirmed';

    public const TwoFactorChallengePassed = 'admin.two_factor_challenge_passed';

    public const TwoFactorReset = 'admin.two_factor_reset';

    public const SettingChanged = 'setting.changed';

    public const WalletCredit = 'wallet.credit';

    public const WalletDebit = 'wallet.debit';

    public const WalletRefund = 'wallet.refund';

    public const WalletAdjusted = 'wallet.adjusted';

    public const PaymentVerified = 'payment.verified';

    public const InvoiceIssued = 'invoice.issued';

    public const ExchangeRateRecorded = 'fx.rate_recorded';

    public const OrderCreated = 'order.created';

    public const OrderAwaitingPayment = 'order.awaiting_payment';

    public const OrderPaid = 'order.paid';

    public const OrderFailed = 'order.failed';

    public const OrderNeedsAttention = 'order.needs_attention';

    public const OrderRefunded = 'order.refunded';

    public const OrderExpired = 'order.expired';

    public const OrderCancelled = 'order.cancelled';

    /*
     * Provisioning.
     *
     * The order events say what happened to the purchase; the provisioning
     * events say what happened at the provider. They are separate because one
     * order can produce several attempts, and an investigation needs to read
     * them as a sequence rather than as one overwritten fact.
     */

    public const OrderProvisioningStarted = 'order.provisioning_started';

    public const ProvisioningAttemptStarted = 'provisioning.attempt_started';

    public const ProvisioningAttemptFailed = 'provisioning.attempt_failed';

    public const ProvisioningReconciled = 'provisioning.reconciled';

    public const OrderProvisioned = 'order.provisioned';

    public const ServerCreated = 'server.created';

    public const SubscriptionCreated = 'subscription.created';

    /*
     * Inventory drift. What the provider holds, against what we sold.
     */

    public const InventoryOrphanDetected = 'inventory.orphan_detected';

    public const InventoryRemoteMissing = 'inventory.remote_missing';

    public const InventoryDriftCorrected = 'inventory.drift_corrected';

    /*
     * Server management. What a customer asked for, and what happened.
     *
     * The request and the outcome are separate events for the destructive one:
     * a delete that was asked for and never reached the provider is the first
     * thing anybody looks for when a machine is still running.
     */

    public const ServerPowerOn = 'server.power_on';

    public const ServerPowerOff = 'server.power_off';

    public const ServerReboot = 'server.reboot';

    public const ServerDeleteRequested = 'server.delete_requested';

    public const ServerTerminated = 'server.terminated';

    public const ServerPasswordRevealed = 'server.password_revealed';
}
