<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a provisioning attempt got before it stopped.
 *
 * The stage decides what an error means, and no error category can supply it.
 * A timeout while asking whether a plan is in stock and a timeout while creating
 * a server carry the same category and opposite consequences: the first cannot
 * have made a machine, the second may have. Reading only the category would
 * refund a customer who has a server, or strand one who does not.
 */
enum ProvisioningStage: string
{
    /** Nothing had been sent that could create anything. */
    case BeforeCreate = 'before_create';

    /** The create call was in flight. A remote server may exist. */
    case Create = 'create';

    /** The provider answered; storing that answer locally is what failed. */
    case Persist = 'persist';

    /**
     * A machine exists and its one-time credential was lost before delivery.
     *
     * Its own stage, and counted on its own, because it is not a create. The
     * create budget on `orders.attempts` governs how many machines this order
     * may ask for; spending one of those on rotating a password would let a run
     * of reset failures retire an order whose machine is sitting there working.
     */
    case CredentialRecovery = 'credential_recovery';

    /**
     * Whether a failure at this stage could have left a remote server behind.
     *
     * The question the refund boundary actually asks. Only `BeforeCreate` can
     * answer no.
     */
    public function mayHaveCreatedRemotely(): bool
    {
        return $this !== self::BeforeCreate;
    }

    /**
     * Whether this stage counts against the provider create budget.
     *
     * Only the create does. Reads, persistence retries and credential recovery
     * all leave forensic rows without ever asking for a machine.
     */
    public function spendsCreateBudget(): bool
    {
        return $this === self::Create;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
