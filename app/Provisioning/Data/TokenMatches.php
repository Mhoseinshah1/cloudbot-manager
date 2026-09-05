<?php

declare(strict_types=1);

namespace App\Provisioning\Data;

use App\Cloud\Data\ProviderServerData;

/**
 * What a provider holds for one provisioning token.
 *
 * Three answers matter and they are not interchangeable: none, exactly one, or
 * more than one. A single-result lookup cannot tell the third from the second,
 * and quietly returning "the" server when two claim the token is how a customer
 * is handed somebody else's machine — so reconciliation asks in a way that can
 * count, and this object keeps the count rather than collapsing it.
 *
 * A tombstone is its own answer again. A token whose server has been deleted has
 * already been spent: asking the provider to create with it would not produce a
 * replacement, and treating it as "none" would lead straight to that mistake.
 */
final readonly class TokenMatches
{
    /**
     * @param  list<ProviderServerData>  $live  Servers the provider still holds.
     * @param  ProviderServerData|null  $tombstone  A deleted server carrying this token.
     */
    private function __construct(
        public array $live,
        public ?ProviderServerData $tombstone,
    ) {}

    /**
     * @param  list<ProviderServerData>  $live
     */
    public static function of(array $live, ?ProviderServerData $tombstone = null): self
    {
        return new self(array_values($live), $tombstone);
    }

    public function count(): int
    {
        return count($this->live);
    }

    /** No live server, and none was ever recorded against this token. */
    public function isAbsent(): bool
    {
        return $this->live === [] && $this->tombstone === null;
    }

    /**
     * The token produced a server which the provider has since removed.
     *
     * Not the same as absent. The token is spent, and a create call carrying it
     * would return the tombstone rather than a new machine.
     */
    public function isTombstoned(): bool
    {
        return $this->live === [] && $this->tombstone !== null;
    }

    public function isUnique(): bool
    {
        return count($this->live) === 1;
    }

    public function isAmbiguous(): bool
    {
        return count($this->live) > 1;
    }

    /** The single match, when there is exactly one. */
    public function sole(): ?ProviderServerData
    {
        return $this->isUnique() ? $this->live[0] : null;
    }

    /**
     * @return list<string>
     */
    public function providerServerIds(): array
    {
        return array_map(
            static fn (ProviderServerData $server): string => $server->providerServerId,
            $this->live,
        );
    }
}
