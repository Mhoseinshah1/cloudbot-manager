<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changes a customer's account status, on an operator's authority.
 *
 * A status change decides whether somebody can buy, renew or operate their
 * servers, so it is a domain operation with a locked row and an audit entry
 * rather than a form field. The reason is required for the two that take
 * something away: an account suspended with no stated reason is unreviewable
 * afterwards, and the person who has to answer the customer six weeks later has
 * nothing to go on.
 *
 * Nothing here touches the wallet. Money is `WalletService`'s, always.
 */
final readonly class CustomerAccountService
{
    public function __construct(private AuditRecorder $audit) {}

    public function suspend(User $customer, User $operator, string $reason): User
    {
        return $this->transition($customer, $operator, UserStatus::Suspended, $reason);
    }

    public function ban(User $customer, User $operator, string $reason): User
    {
        return $this->transition($customer, $operator, UserStatus::Banned, $reason);
    }

    /** Reactivation needs no reason: it restores the ordinary state. */
    public function reactivate(User $customer, User $operator, ?string $reason = null): User
    {
        return $this->transition($customer, $operator, UserStatus::Active, $reason, requiresReason: false);
    }

    private function transition(
        User $customer,
        User $operator,
        UserStatus $status,
        ?string $reason,
        bool $requiresReason = true,
    ): User {
        if (! $operator->isActive() || ! $operator->checkPermissionTo(Permission::CustomersManage->value)) {
            throw new RuntimeException('You may not change a customer account status.');
        }

        if ($requiresReason && ($reason === null || trim($reason) === '')) {
            throw new RuntimeException('A reason is required to suspend or ban an account.');
        }

        return DB::transaction(function () use ($customer, $operator, $status, $reason): User {
            $locked = User::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $before = $locked->status;

            if ($before === $status) {
                // Already there. Recording a second audit entry would claim a
                // change nobody made.
                return $locked;
            }

            $locked->forceFill(['status' => $status->value])->save();

            $this->audit->record(
                AuditEvent::CustomerStatusChanged,
                // The operator who clicked, never "system": an admin action
                // with no name attached is the one nobody can account for.
                actor: $operator,
                subject: $locked,
                before: ['status' => $before->value],
                after: ['status' => $status->value],
                metadata: [
                    'user_id' => $locked->getKey(),
                    'from' => $before->value,
                    'to' => $status->value,
                    'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
                ],
            );

            return $locked;
        });
    }
}
