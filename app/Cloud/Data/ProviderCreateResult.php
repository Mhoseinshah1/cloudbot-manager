<?php

declare(strict_types=1);

namespace App\Cloud\Data;

/**
 * What a provider answered when asked to build a server.
 *
 * A separate type from `ProviderServerData` for one reason, and it is the whole
 * reason: a create response can carry a one-time root password, and every other
 * provider read must not. `getServer()`, `listServers()` and
 * `findByProvisioningToken()` are called by reconciliation, inventory,
 * comparisons and logs, constantly; putting a credential field on the shape they
 * return would put a credential in all of those.
 *
 * So the credential travels in exactly one place — the return value of the one
 * call that can produce it — and lives only as long as the frame that carries it
 * to encrypted storage. Nothing here is durable, and nothing here encrypts:
 * `servers.root_password_encrypted` is the single place a root password is ever
 * stored, and this type exists so it can get there.
 *
 * `rootCredential` is null for a provider that issues no password at create
 * time, and null for a repeat create that returned an existing server — a
 * one-time credential is issued once, and a provider replaying an earlier
 * result has none left to give.
 */
final readonly class ProviderCreateResult
{
    public function __construct(
        public ProviderServerData $server,
        /**
         * The one-time root password, when this response carried one.
         *
         * Held in memory only. If the local write fails before it is encrypted,
         * it is gone — deliberately, because the alternative is a second
         * durable secret store. Recovery rotates rather than remembers.
         */
        public ?SensitiveRootCredential $rootCredential = null,
    ) {}

    /** A create that produced a server and no credential. */
    public static function withoutCredential(ProviderServerData $server): self
    {
        return new self($server);
    }

    public function hasCredential(): bool
    {
        return $this->rootCredential instanceof SensitiveRootCredential
            && ! $this->rootCredential->isEmpty();
    }
}
