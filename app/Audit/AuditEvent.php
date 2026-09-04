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

    public const OrderRefunded = 'order.refunded';

    public const OrderExpired = 'order.expired';

    public const OrderCancelled = 'order.cancelled';
}
