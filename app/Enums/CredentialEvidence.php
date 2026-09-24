<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What is durably known about the root credential of an undelivered machine.
 *
 * Recovery has to decide whether a server that exists remotely but was never
 * handed over needs a password rotated before it can be delivered, and the only
 * honest basis for that is what a create response actually said — recorded
 * before the response could be lost.
 *
 * Three states, and the third is the important one. Collapsing "we never found
 * out" into either certainty is how this goes wrong: read as none, a customer
 * is handed a machine they cannot log into and told it is ready; read as
 * issued, a key-authenticating provider that has no reset capability parks
 * orders it could have delivered perfectly well.
 */
enum CredentialEvidence: string
{
    /**
     * A create was durably observed to have issued a root password.
     *
     * That password is gone — it lived in the memory of a worker that did not
     * survive — so delivery needs a new one.
     */
    case KnownIssued = 'known_issued';

    /**
     * A create was durably observed to have issued no root password.
     *
     * The machine authenticates some other way. Delivering it credential-free
     * is correct, and rotating would be asking a provider for something it does
     * not do.
     */
    case KnownNone = 'known_none';

    /**
     * No create response was ever recorded.
     *
     * The worker died between the provider acting and the fact being written,
     * or the machine was found by a replay that establishes nothing. Neither
     * certainty may be assumed, so recovery takes the conservative path.
     */
    case Unknown = 'unknown';
}
