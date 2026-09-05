<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderServerStatus;
use App\Provisioning\Data\TokenMatches;

/**
 * Asks a provider what it holds for one provisioning token.
 *
 * `findByProvisioningToken()` answers "here is a server", which is exactly the
 * wrong shape for the question that matters. If a provider's state has drifted
 * and two machines carry one token, a single-result lookup returns one of them
 * and gives no hint that the other exists — and handing a customer one of two
 * candidate servers is precisely the automatic choice the specification forbids.
 *
 * So the inventory is listed and filtered, which can count. The direct lookup is
 * still used, for the one thing listing cannot see: a deleted server. A token
 * whose machine has been removed is spent rather than free, and telling those
 * apart is what stops a create call being made for a replacement nobody bought.
 */
final readonly class TokenLookup
{
    /**
     * Everything this provider associates with the token.
     *
     * @throws \App\Cloud\Exceptions\ProviderException when the provider cannot
     *                                                 be read. A failed lookup is
     *                                                 never evidence of absence.
     */
    public function find(CloudProviderInterface $provider, string $token): TokenMatches
    {
        $live = [];

        foreach ($provider->listServers() as $server) {
            if ($server->provisioningToken === $token && $server->status->exists()) {
                $live[] = $server;
            }
        }

        if ($live !== []) {
            return TokenMatches::of($live);
        }

        // Nothing live. The direct lookup is the only thing that can tell a
        // token that was never used from one whose server has been deleted.
        $known = $provider->findByProvisioningToken($token);

        if ($known instanceof ProviderServerData && $known->status === ProviderServerStatus::Deleted) {
            return TokenMatches::of([], $known);
        }

        if ($known instanceof ProviderServerData && $known->status->exists()) {
            // The inventory listing did not show it — a provider whose list and
            // lookup disagree. Trust the more specific answer rather than
            // concluding the server is gone.
            return TokenMatches::of([$known]);
        }

        return TokenMatches::of([]);
    }
}
