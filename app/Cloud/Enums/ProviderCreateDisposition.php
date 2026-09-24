<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

/**
 * What a provider actually did when asked to create a server.
 *
 * The distinction exists because a create-specific result with a null credential
 * was answering two different questions with the same silence, and the two are
 * not equivalent:
 *
 *  - a genuinely new server whose provider issues no root password at all —
 *    a key-authenticating provider, and a perfectly valid normalized answer;
 *  - a token that already referred to an existing server, so the create was a
 *    replay and there is no one-time credential left to hand over.
 *
 * Read as the first, the second becomes a claim nobody made: that this machine
 * has no root password. Recovery would then deliver a server credential-free
 * and tell the customer it was ready, when in fact the original create issued a
 * password that a dead worker took with it.
 *
 * So the disposition is stated rather than inferred, and it is an enum rather
 * than a boolean because `created: false` reads as a fact about credentials to
 * anybody skimming, which is exactly the confusion being removed.
 */
enum ProviderCreateDisposition: string
{
    /** This call built the server. What it says about credentials is complete. */
    case Created = 'created';

    /**
     * The token already had a server, so this call built nothing.
     *
     * It carries no credential, and that says nothing at all about what the
     * original create issued. Treating it as evidence is the mistake.
     */
    case Existing = 'existing';
}
