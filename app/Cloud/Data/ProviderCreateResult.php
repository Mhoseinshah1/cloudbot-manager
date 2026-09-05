<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Cloud\Enums\ProviderCreateDisposition;

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
 * `rootCredential` is null in two unrelated situations, which is why the
 * disposition is stated alongside it. A provider that issues no password at
 * create time answers `Created` with null, and that is a complete fact about
 * the machine. A repeat create that returned an existing server answers
 * `Existing` with null, and that is not a fact about credentials at all — the
 * original create may well have issued one. Nothing may read the second as the
 * first.
 */
final readonly class ProviderCreateResult
{
    public function __construct(
        public ProviderServerData $server,
        /**
         * Whether this call built the server or found it already there.
         *
         * Load-bearing for recovery: only a `Created` answer says anything
         * about what credential this machine has.
         */
        public ProviderCreateDisposition $disposition,
        /**
         * The one-time root password, when this response carried one.
         *
         * Held in memory only. If the local write fails before it is encrypted,
         * it is gone — deliberately, because the alternative is a second
         * durable secret store. Recovery rotates rather than remembers.
         */
        public ?SensitiveRootCredential $rootCredential = null,
    ) {}

    /**
     * A new server this call built, with the credential it issued, if any.
     *
     * A null credential here is a complete statement: this provider issues no
     * root password.
     */
    public static function created(ProviderServerData $server, ?SensitiveRootCredential $credential = null): self
    {
        return new self($server, ProviderCreateDisposition::Created, $credential);
    }

    /**
     * The server this token already had.
     *
     * Never carries a credential, and never implies the absence of one.
     */
    public static function existing(ProviderServerData $server): self
    {
        return new self($server, ProviderCreateDisposition::Existing);
    }

    /** A new server this call built, with no credential issued. */
    public static function withoutCredential(ProviderServerData $server): self
    {
        return self::created($server);
    }

    /** Whether this call built the server, as opposed to finding it. */
    public function isNew(): bool
    {
        return $this->disposition === ProviderCreateDisposition::Created;
    }

    /**
     * Whether this answer establishes that the machine has no root password.
     *
     * Only a create that actually built something can establish it. An
     * `Existing` replay establishes nothing.
     */
    public function provesNoCredential(): bool
    {
        return $this->isNew() && ! $this->hasCredential();
    }

    public function hasCredential(): bool
    {
        return $this->rootCredential instanceof SensitiveRootCredential
            && ! $this->rootCredential->isEmpty();
    }
}
