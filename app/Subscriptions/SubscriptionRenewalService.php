<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\ProductLocationPrice;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\SubscriptionRenewal;
use App\Models\User;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\PricingService;
use App\Subscriptions\Data\RenewalQuote;
use App\Subscriptions\Exceptions\RenewalNotAllowed;
use App\Wallet\Exceptions\InsufficientBalance;
use App\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sells one more service period to a customer who already has the service.
 *
 * The rule the whole class is built around is that a renewal *appends*. The new
 * period starts where the old one ended, never at the moment somebody pressed a
 * button — so renewing early does not throw away days already paid for, and
 * renewing inside grace does not quietly reward being late with a longer month.
 * That also gives the renewal its identity: the period end being replaced is
 * unique to one renewal, which is what makes charging twice for it impossible
 * rather than merely unlikely.
 *
 * Money moves through `WalletService` and nowhere else, inside one short
 * transaction with the subscription row locked. Nothing in here speaks to a
 * provider or to Telegram: a renewal that held a PostgreSQL lock across a
 * network call would block the sweep behind somebody else's timeout, and a
 * message sent before the commit is a promise the database can still take back.
 */
final readonly class SubscriptionRenewalService
{
    public function __construct(
        private PricingService $pricing,
        private WalletService $wallet,
        private AuditRecorder $audit,
        private OutboxWriter $outbox,
    ) {}

    /**
     * What this renewal would cost and buy, priced now.
     *
     * @throws RenewalNotAllowed
     */
    public function quote(User $customer, Subscription $subscription): RenewalQuote
    {
        $subscription = $this->assertRenewable($customer, $subscription);
        $server = $this->serverFor($subscription);
        $price = $this->priceRowFor($server);

        try {
            // The renewal path deliberately, not the new-sale path: the
            // new-sales kill switch must not delete the servers of customers
            // trying to pay for them.
            $quote = $this->pricing->quoteRenewal($price);
        } catch (SaleNotAvailable $refused) {
            throw RenewalNotAllowed::priceUnavailable($refused->reason->value);
        }

        $currentEnd = CarbonImmutable::instance($subscription->current_period_end);

        return new RenewalQuote(
            subscriptionId: (int) $subscription->getKey(),
            serverId: (int) $server->getKey(),
            serverName: $server->name,
            currentPeriodEnd: $currentEnd,
            newPeriodEnd: RenewalQuote::endAfter($currentEnd),
            // The current customer price, not the historical one the
            // subscription happens to be carrying.
            priceToman: $quote->sellingPriceToman,
            inGrace: $subscription->status === SubscriptionStatus::Grace,
            graceUntil: $subscription->grace_until === null
                ? null
                : CarbonImmutable::instance($subscription->grace_until),
            quote: $quote,
        );
    }

    /**
     * Charge for one more period, exactly once.
     *
     * `$approvedPrice` is what the customer was shown. It can only refuse the
     * renewal, never set the amount: a caller who could name their own price
     * would be naming it. When today's figure differs, the customer is asked
     * again rather than charged something they did not agree to.
     *
     * A replay — a double tap, a re-delivered callback, a retried job — finds
     * the renewal row its own key already created and returns it without
     * charging again.
     *
     * @throws RenewalNotAllowed
     */
    public function renew(User $customer, Subscription $subscription, ?int $approvedPrice = null): SubscriptionRenewal
    {
        // Priced outside the transaction: reading the catalogue and the rate is
        // work that does not need a lock held over it.
        $quote = $this->quote($customer, $subscription);

        if ($quote->differsFrom($approvedPrice)) {
            throw RenewalNotAllowed::stalePrice($quote->priceToman);
        }

        $settled = DB::transaction(function () use ($customer, $subscription, $quote): SubscriptionRenewal {
            // Lock order is User, then Subscription, then Server — the same
            // order every other financial path takes, so two of them can never
            // deadlock against each other.
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $locked = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Subscription) {
                throw RenewalNotAllowed::serverGone();
            }

            // Re-read under the lock. Between the quote and here the sweep may
            // have moved this subscription into grace, or another confirmation
            // may have renewed it outright.
            $this->assertStateAllowsRenewal($lockedCustomer, $locked);

            $oldEnd = CarbonImmutable::instance($locked->current_period_end);

            // The identity of this renewal: this subscription, this period.
            $key = SubscriptionRenewal::keyFor((int) $locked->getKey(), $oldEnd->getTimestamp());

            $existing = SubscriptionRenewal::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof SubscriptionRenewal) {
                // Somebody already bought this period. Returning their renewal
                // is the whole point of the key.
                return $existing;
            }

            if ($oldEnd->getTimestamp() !== $quote->currentPeriodEnd->getTimestamp()) {
                // The period moved while we were pricing. Whatever the customer
                // was shown describes a period that no longer needs buying.
                throw RenewalNotAllowed::stalePrice($quote->priceToman);
            }

            $this->assertNoExpiryDeletionInFlight($locked, $oldEnd);

            $newEnd = RenewalQuote::endAfter($oldEnd);

            // One debit, through the one authority that may move money. It
            // refuses rather than overdrawing, and its own idempotency key is
            // this renewal's identity, so a retry cannot double-charge even if
            // everything below were to run twice.
            try {
                $this->wallet->debit(
                    $lockedCustomer,
                    $quote->priceToman,
                    $key,
                    'Subscription renewal',
                    $locked,
                    [
                        'subscription_id' => $locked->getKey(),
                        'server_id' => $quote->serverId,
                        'old_period_end' => $oldEnd->toIso8601String(),
                        'new_period_end' => $newEnd->toIso8601String(),
                    ],
                );
            } catch (InsufficientBalance) {
                // Nothing was written. The customer is told, and no part of the
                // service moves: a period half-extended is worse than one not
                // extended at all.
                throw RenewalNotAllowed::insufficientFunds(
                    $quote->priceToman,
                    (int) $lockedCustomer->wallet_balance_toman,
                );
            }

            $invoice = $this->issueInvoice($locked, $quote, $oldEnd, $newEnd, $key);

            $locked->forceFill([
                'status' => SubscriptionStatus::Active->value,
                'current_period_start' => $oldEnd,
                'current_period_end' => $newEnd,
                // Grace is over the moment the period is paid for.
                'grace_until' => null,
                // What the period the customer now holds actually cost. Old
                // invoices keep their own figures; this is not history.
                'price_toman' => $quote->priceToman,
                'last_billed_at' => CarbonImmutable::now(),
                'next_billing_at' => $newEnd,
            ])->save();

            try {
                $renewal = SubscriptionRenewal::query()->create([
                    'subscription_id' => $locked->getKey(),
                    'user_id' => $lockedCustomer->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'old_period_end' => $oldEnd,
                    'new_period_end' => $newEnd,
                    'amount_toman' => $quote->priceToman,
                    'idempotency_key' => $key,
                ]);
            } catch (QueryException $exception) {
                // Two confirmations reached the unique index together. The one
                // that lost reads the winner's row rather than its own.
                $winner = SubscriptionRenewal::query()->where('idempotency_key', $key)->first();

                if ($winner instanceof SubscriptionRenewal) {
                    return $winner;
                }

                throw $exception;
            }

            $this->audit->record(
                AuditEvent::SubscriptionRenewed,
                actor: $lockedCustomer,
                subject: $locked,
                metadata: [
                    'subscription_id' => $locked->getKey(),
                    'server_id' => $quote->serverId,
                    'user_id' => $lockedCustomer->getKey(),
                    'old_period_end' => $oldEnd->toIso8601String(),
                    'new_period_end' => $newEnd->toIso8601String(),
                    'price_toman' => $quote->priceToman,
                    'invoice_id' => $invoice->getKey(),
                ],
            );

            // Inside the transaction, so a customer is never told their service
            // was extended by a transaction that then rolls back.
            $this->outbox->record(
                OutboxTopic::SubscriptionRenewed,
                $locked,
                [
                    'subscription_id' => $locked->getKey(),
                    'server_id' => $quote->serverId,
                    'server_name' => $quote->serverName,
                    'user_id' => $lockedCustomer->getKey(),
                    'amount_toman' => $quote->priceToman,
                    'new_period_end' => $newEnd->toIso8601String(),
                ],
                self::renewedKey((int) $locked->getKey(), $newEnd->getTimestamp()),
            );

            return $renewal;
        });

        return $settled;
    }

    /** One renewal announcement per period bought. */
    public static function renewedKey(int $subscriptionId, int $newPeriodEndEpoch): string
    {
        return 'subscription:'.$subscriptionId.':renewed:'.$newPeriodEndEpoch;
    }

    /**
     * The invoice for one renewal.
     *
     * Numbered from the renewal's own identity, so a replay finds the existing
     * document instead of issuing a second one for the same thirty days.
     */
    private function issueInvoice(
        Subscription $subscription,
        RenewalQuote $quote,
        CarbonImmutable $oldEnd,
        CarbonImmutable $newEnd,
        string $key,
    ): Invoice {
        $number = self::invoiceNumber($subscription, $oldEnd);

        $existing = Invoice::query()->where('number', $number)->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        return Invoice::query()->create([
            'user_id' => $subscription->user_id,
            // Renewals belong to a subscription, not to the original order.
            'order_id' => null,
            'number' => $number,
            'type' => InvoiceType::ServerRenewal,
            'amount_toman' => $quote->priceToman,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
            'line_items' => [[
                'description' => 'Service renewal for '.$quote->serverName,
                'quantity' => 1,
                'unit_price_toman' => $quote->priceToman,
                'total_toman' => $quote->priceToman,
            ]],
            // Exact decimal strings, as the pricing layer produced them.
            'pricing_snapshot' => [
                'subscription_id' => $subscription->getKey(),
                'server_id' => $quote->serverId,
                'renewal_key' => $key,
                'period_start' => $oldEnd->toIso8601String(),
                'period_end' => $newEnd->toIso8601String(),
                'selling_price_toman' => $quote->priceToman,
                'provider_cost' => $quote->quote->providerCost,
                'provider_currency' => $quote->quote->providerCurrency,
                'exchange_rate_id' => $quote->quote->exchangeRateId,
                'exchange_rate' => $quote->quote->exchangeRate,
            ],
        ]);
    }

    /** One invoice per subscription period, whatever arrives twice. */
    private static function invoiceNumber(Subscription $subscription, CarbonImmutable $oldEnd): string
    {
        return sprintf('INV-R%06d-%d', (int) $subscription->getKey(), $oldEnd->getTimestamp());
    }

    /**
     * @throws RenewalNotAllowed
     */
    private function assertRenewable(User $customer, Subscription $subscription): Subscription
    {
        $fresh = Subscription::query()->whereKey($subscription->getKey())->first();

        if (! $fresh instanceof Subscription) {
            throw RenewalNotAllowed::serverGone();
        }

        $this->assertStateAllowsRenewal($customer, $fresh);

        return $fresh;
    }

    /**
     * Everything that must be true for money to change hands.
     *
     * @throws RenewalNotAllowed
     */
    private function assertStateAllowsRenewal(User $customer, Subscription $subscription): void
    {
        if (! $customer->isActive()) {
            // Suspended and banned customers may look; they may not transact.
            throw RenewalNotAllowed::inactiveCustomer();
        }

        // Ownership is established from the row, never from what a caller sent.
        if ((int) $subscription->user_id !== (int) $customer->getKey()) {
            throw RenewalNotAllowed::notYours();
        }

        if (! $subscription->status->isRenewable()) {
            throw RenewalNotAllowed::notRenewable($subscription->status->value);
        }

        if ($subscription->billing_mode !== BillingMode::Monthly
            || $subscription->billing_cycle !== BillingCycle::Monthly) {
            // Release 1.0 sells one shape of service. Renewing anything else
            // here would be activating a billing mode that has no code behind
            // it.
            throw RenewalNotAllowed::notMonthly();
        }

        $server = Server::query()->whereKey($subscription->server_id)->first();

        if (! $server instanceof Server || $server->status === ServerStatus::Terminated) {
            throw RenewalNotAllowed::serverGone();
        }
    }

    /**
     * Refuse to sell a period for a machine that is already being destroyed.
     *
     * Once the lifecycle has durably asked for deletion, the provider may be
     * acting on it this second and there is no safe way to call it back. Taking
     * the customer's money at that point would be charging for something that
     * is going away — so the renewal is refused and a person picks it up.
     *
     * @throws RenewalNotAllowed
     */
    private function assertNoExpiryDeletionInFlight(Subscription $subscription, CarbonImmutable $oldEnd): void
    {
        $pending = ServerAction::query()
            ->where('server_id', $subscription->server_id)
            ->where('action', ServerActionType::Delete->value)
            ->where('idempotency_key', MonthlyLifecycleService::terminationKey(
                (int) $subscription->getKey(),
                $oldEnd->getTimestamp(),
            ))
            ->first();

        if ($pending instanceof ServerAction && $pending->status !== ServerActionStatus::Failed) {
            throw RenewalNotAllowed::terminationInProgress();
        }
    }

    /**
     * @throws RenewalNotAllowed
     */
    private function serverFor(Subscription $subscription): Server
    {
        $server = Server::query()->whereKey($subscription->server_id)->first();

        if (! $server instanceof Server) {
            throw RenewalNotAllowed::serverGone();
        }

        return $server;
    }

    /**
     * The catalogue row this server is priced through.
     *
     * Found from the server's own product and location, so a renewal is priced
     * for the thing the customer actually has rather than for whatever the
     * product's default happens to be today.
     *
     * @throws RenewalNotAllowed
     */
    private function priceRowFor(Server $server): ProductLocationPrice
    {
        $price = ProductLocationPrice::query()
            ->where('product_id', $server->product_id)
            ->where('provider_location_id', $server->provider_location_id)
            ->first();

        if (! $price instanceof ProductLocationPrice) {
            throw RenewalNotAllowed::priceUnavailable('no current price exists for this product and location');
        }

        return $price;
    }
}
