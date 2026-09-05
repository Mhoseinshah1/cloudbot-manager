<?php

declare(strict_types=1);

namespace App\Enums;

use App\Audit\AuditEvent;
use App\Cloud\Enums\ProviderCapability;

/**
 * The things a customer can ask us to do to their server.
 *
 * A closed vocabulary, because these values reach a provider. An action name
 * assembled from a callback string would be a customer choosing which remote
 * operation to invoke; a case of this enum is one this system decided to offer.
 *
 * Each one names the capability it needs. Whether a given provider offers that
 * capability is answered by asking the adapter, never by a table someone keeps
 * in step by hand.
 */
enum ServerActionType: string
{
    case PowerOn = 'power_on';

    case PowerOff = 'power_off';

    case Reboot = 'reboot';

    case Delete = 'delete';

    /** Not a provider operation at all: reading what we already hold. */
    case RootPasswordReveal = 'root_password_reveal';

    /**
     * The optional capability this needs, or null when the core contract has it.
     */
    public function requiredCapability(): ?ProviderCapability
    {
        return match ($this) {
            self::PowerOn, self::PowerOff => ProviderCapability::PowerControl,
            self::Reboot => ProviderCapability::Reboot,
            // Delete is in the core contract; a reveal never leaves this system.
            self::Delete, self::RootPasswordReveal => null,
        };
    }

    /** Whether performing this involves calling a provider at all. */
    public function isRemote(): bool
    {
        return $this !== self::RootPasswordReveal;
    }

    /** Whether this destroys the machine. */
    public function isDestructive(): bool
    {
        return $this === self::Delete;
    }

    /**
     * The power state this action intends to leave the server in, if any.
     *
     * Reboot is deliberately absent. A rebooted server ends up on, but "it is
     * on" is not evidence that a reboot happened — treating it as evidence is
     * how an uncertain reboot gets silently marked done, or sent twice.
     */
    public function intendedPowerState(): ?ServerPowerState
    {
        return match ($this) {
            self::PowerOn => ServerPowerState::On,
            self::PowerOff => ServerPowerState::Off,
            default => null,
        };
    }

    /**
     * What is written when this action has actually happened.
     *
     * A delete completes as `server.terminated` rather than as a delete event,
     * because what an investigation needs to find is the moment a customer's
     * machine and their service ended — not the moment somebody pressed a
     * button, which is recorded separately below.
     */
    public function completionAuditEvent(): string
    {
        return match ($this) {
            self::PowerOn => AuditEvent::ServerPowerOn,
            self::PowerOff => AuditEvent::ServerPowerOff,
            self::Reboot => AuditEvent::ServerReboot,
            self::Delete => AuditEvent::ServerTerminated,
            self::RootPasswordReveal => AuditEvent::ServerPasswordRevealed,
        };
    }

    /**
     * What is written when this action is asked for, if anything.
     *
     * Only the destructive one. A reboot that was requested and never reached
     * the provider is a curiosity; a delete that was is the first question
     * anybody asks about a machine that is still running.
     */
    public function requestAuditEvent(): ?string
    {
        return $this === self::Delete ? AuditEvent::ServerDeleteRequested : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
