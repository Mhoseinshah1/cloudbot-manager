<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A root password a provider issued once, held only long enough to encrypt it.
 *
 * Everything about this class is arranged so that the plaintext can leave it in
 * exactly one way: somebody calling `reveal()` on purpose. A credential does not
 * escape through a bug in a log line, a `var_dump` in an incident, a serialized
 * job payload or a model cast — it escapes because a program handed it to
 * something, and the only way to make that safe is to make every accidental
 * route impossible rather than discouraged.
 *
 * So the plaintext is private, there is no `__toString`, `jsonSerialize` and
 * `__debugInfo` are both defined to return a placeholder, and the class is not
 * an Eloquent attribute, a queueable payload or anything else with an implicit
 * serializer. `readonly` keeps it from being mutated into something else after
 * a caller has checked what it holds.
 *
 * It is deliberately transient. Nothing here encrypts, and nothing here should:
 * durable encryption belongs to the one place the specification names for it,
 * `servers.root_password_encrypted`, through that model's encrypted cast. A
 * value object that could persist itself would become a second secret store by
 * accident, which is precisely what this design refuses to have.
 */
final readonly class SensitiveRootCredential implements JsonSerializable
{
    /** What every accidental serialization route yields instead of a password. */
    public const REDACTED = '[redacted]';

    public function __construct(
        #[SensitiveParameter]
        private string $plaintext,
    ) {}

    /**
     * The password, for the persistence boundary and nothing else.
     *
     * The only method that returns plaintext, named so that a reviewer reading
     * a call site can see immediately that a secret is being taken out. There
     * are exactly two legitimate callers: the code that writes it to the
     * encrypted server column, and the tests that prove it got there.
     */
    public function reveal(): string
    {
        return $this->plaintext;
    }

    /** Whether this credential carries anything at all. */
    public function isEmpty(): bool
    {
        return $this->plaintext === '';
    }

    /**
     * What `json_encode` sees.
     *
     * Not because anything should be encoding a credential, but because
     * something eventually will — an array of DTOs dumped into a log, a queue
     * payload assembled by a helper, a test fixture. This makes that harmless
     * rather than catastrophic.
     */
    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * What `var_dump`, `dd()` and Symfony's dumper see.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['plaintext' => self::REDACTED];
    }

    /**
     * What `serialize()` does. Nothing: it refuses.
     *
     * Fail closed rather than redact. Serializing this object means somebody
     * put a credential somewhere durable — a queued job, a session, a cache
     * entry, a failed-job payload — and none of those are places it may go.
     * Quietly returning a placeholder would hide that mistake and leave a
     * credential-shaped hole downstream, where whatever unserialized it would
     * carry on holding a value that is no longer a password and behaves like
     * one. A refusal surfaces the architectural error at the moment it is made.
     *
     * The redacted routes above stay redacted on purpose: `json_encode`,
     * `var_dump` and `print_r` happen in logs and debuggers, where throwing
     * would turn a diagnostic into an outage. Those are accidents to neutralise.
     * Durable serialization is a design decision to refuse.
     *
     * The message carries no secret, and nothing about the value it is
     * protecting.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'A '.self::class.' may not be serialized. A root credential belongs in '
            .'servers.root_password_encrypted and nowhere else — not in a queue payload, '
            .'a session or a cache entry.'
        );
    }
}
