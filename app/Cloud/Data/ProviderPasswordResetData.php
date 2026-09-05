<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Cloud\Enums\ProviderActionStatus;

/**
 * What a provider answered when asked to issue a new root password.
 *
 * Normalized like every other provider answer, and for the same reason: the
 * code deciding whether a credential is usable must never read a raw response
 * or a provider's prose. Two facts, and only two.
 *
 * The action identity and status exist because a reset is an operation a
 * provider may still be performing. A `Running` reset is not a credential to
 * store yet — the password is known but the machine may not accept it — so the
 * caller polls the normalized action API before persisting anything.
 *
 * The credential itself is a `SensitiveRootCredential` rather than a string, so
 * the redaction rules travel with it rather than depending on every call site
 * remembering them.
 */
final readonly class ProviderPasswordResetData
{
    public function __construct(
        public string $providerActionId,
        public string $providerServerId,
        public ProviderActionStatus $status,
        /**
         * The newly issued password.
         *
         * Null when a provider accepts the reset but reveals the password only
         * on completion. The caller must then treat the reset as unusable until
         * a later call produces one, rather than persisting a server nobody can
         * log into.
         */
        public ?SensitiveRootCredential $rootCredential,
        public SafeMetadata $metadata,
    ) {}

    /** Whether this answer alone is enough to deliver a server. */
    public function isUsable(): bool
    {
        return $this->status === ProviderActionStatus::Success
            && $this->rootCredential instanceof SensitiveRootCredential
            && ! $this->rootCredential->isEmpty();
    }
}
