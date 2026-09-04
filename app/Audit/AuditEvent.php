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

    public const TwoFactorReset = 'admin.two_factor_reset';

    public const SettingChanged = 'setting.changed';
}
