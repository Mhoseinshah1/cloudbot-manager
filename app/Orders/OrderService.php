<?php

declare(strict_types=1);

namespace App\Orders;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Billing\InvoiceService;
use App\Enums\ImageSelectionMode;
use App\Enums\OrderRefusalReason;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\ProviderImage;
use App\Models\User;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\OrderIdempotencyConflict;
use App\Orders\Exceptions\OrderNotPlaceable;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Pricing\Data\PriceQuote;
use App\Pricing\FxAuthorityLock;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use App\Wallet\WalletService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates orders and takes payment for them.
 *
 * The two things it must never do are create the same order twice and charge
 * for it twice, and both are solved the same way: a unique key in the database
 * decides, not a check in PHP. A check would let two concurrent requests both
 * look, both see nothing, and both write.
 *
 * Price is never accepted from a caller. Every first creation asks
 * PricingService for a fresh quote, which re-runs the sales kill switch, the
 * catalog checks and FX freshness at that moment — so an order cannot be placed
 * against a price that was valid ten minutes ago.
 *
 * No network call happens here, and none may be added: this runs while a
 * customer waits, and part of it runs with their wallet row locked.
 */
final readonly class OrderService
{
    public function __construct(
        private PricingService $pricing,
        private SettingsService $settings,
        private WalletService $wallet,
        private InvoiceService $invoices,
        private OrderStateMachine $states,
        private AuditRecorder $audit,
        private PurchasePolicyService $policy,
        private OutboxWriter $outbox,
        private FxAuthorityLock $fx,
    ) {}

    /**
     * Place an order, or return the one this key already placed.
     *
     * A retry arriving after the first attempt succeeded must find that order,
     * not make a second business decision. That matters most when the world has
     * moved: the rate changed, the price changed, sales were switched off. None
     * of those affect the answer, because the answer is "you already bought
     * this" — the existing order is looked up by key alone and returned
     * untouched, never repriced against today.
     *
     * A retry describing something else is refused rather than answered.
     */
    public function place(PurchaseIntent $intent): Order
    {
        $existing = $this->findByKey($intent->idempotencyKey);

        if ($existing instanceof Order) {
            $this->assertSameIntention($existing, $intent);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($intent): Order {
                // The customer's row, locked before the abuse limits are
                // counted and held until this order exists. Two purchases
                // racing for the last permitted slot queue here; counted
                // without the lock, both would see room and both would pass.
                //
                // User first, matching the order WalletService takes, so a
                // purchase and a concurrent wallet movement queue rather than
                // deadlock.
                $customer = User::query()->whereKey($intent->user->getKey())->lockForUpdate()->first();

                if (! $customer instanceof User) {
                    throw new ModelNotFoundException('The customer no longer exists.');
                }

                // Every mutable row that authorizes this sale, held until this
                // order exists or does not. Reading them before the transaction
                // was the defect: an administrator could switch sales off,
                // replace the terms, reprice the catalog or withdraw the image
                // in the gap between the read and the insert, and the order
                // committed anyway, carrying authorization that had already
                // been withdrawn.
                //
                // Re-reading inside the transaction is not enough on its own
                // either — under read committed an administrator's commit still
                // lands between our read and our insert. These are share locks,
                // so the administrator's write waits for us and ours sees
                // whatever they already committed. Concurrent purchases hold
                // shared locks together and do not block each other.
                $currency = $this->lockSaleControls($intent);

                if ($currency !== null) {
                    // The one authority a row lock cannot hold. Rates are
                    // appended rather than updated, so a share lock on the
                    // current row does nothing to stop the next one committing
                    // and becoming the authority a moment before this order
                    // does. Held shared until this transaction ends, so
                    // concurrent purchases never wait on each other and only a
                    // rate writer does.
                    $this->fx->shared($currency);
                }

                // Authoritative now, on rows nobody may change until we commit.
                $this->assertCustomerMayBuy($customer);
                $aupVersion = $this->requireAcceptedTerms($intent);

                $quote = $this->pricing->quoteNewSale($intent->locationPrice);
                $image = $this->requireSelectableImage($intent, $quote);
                $selectionMode = $intent->imageSelectionMode();

                // The image is resolved by the quote, so it is locked here
                // rather than above — the same rule, one step later in the
                // documented order.
                $this->lockImage((int) $image->getKey());
                $image = $this->requireSelectableImage($intent, $quote);

                // What the customer approved, against what it costs now. Only
                // on this path: a retry carrying an existing key returned
                // above without ever asking for a price, which is what stops
                // "did my purchase work?" from being answered by today's rate.
                $this->assertOfferUnchanged($intent, $quote, $image, $selectionMode, $aupVersion);

                $this->policy->assertMayPurchase($customer);

                $order = Order::query()->create([
                    'user_id' => $intent->user->getKey(),
                    'product_id' => $quote->productId,
                    'product_location_price_id' => $quote->productLocationPriceId,
                    'order_number' => self::newOrderNumber(),
                    // Exactly what was quoted. Not recomputed, not adjusted.
                    'total_toman' => $quote->sellingPriceToman,
                    'idempotency_key' => $intent->idempotencyKey,
                    'cost_snapshot' => self::costSnapshot($quote),
                    'pricing_snapshot' => self::pricingSnapshot($quote, $image, $selectionMode),
                    'aup_version' => $aupVersion,
                    // Server-side. A customer-supplied acceptance time proves
                    // nothing about when they accepted.
                    'aup_accepted_at' => CarbonImmutable::now(),
                ]);

                $this->audit->record(
                    AuditEvent::OrderCreated,
                    actor: $intent->user,
                    subject: $order,
                    after: ['status' => $order->status->value],
                    metadata: [
                        'order_id' => $order->getKey(),
                        'order_number' => $order->order_number,
                        'user_id' => $order->user_id,
                        'product_id' => $order->product_id,
                        'total_toman' => $order->total_toman,
                        'aup_version' => $order->aup_version,
                    ],
                );

                return $order;
            });
        } catch (QueryException $exception) {
            // Two requests carrying one key arrived together. Whichever landed
            // first is the order; the other must not become a second one.
            $winner = $this->findByKey($intent->idempotencyKey);

            if ($winner instanceof Order) {
                $this->assertSameIntention($winner, $intent);

                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Share-lock every mutable row that authorizes a new sale.
     *
     * A documented, fixed order, because a lock order that varies is a deadlock
     * waiting for load: settings, then the location price, then the product,
     * then the provider, plan and location behind it. The image comes after,
     * once the quote has resolved which one this order would be built from.
     *
     * Share rather than exclusive, deliberately. Two customers buying at once
     * hold these together and never wait on each other; only an administrator
     * changing one of them waits, which is exactly the interleaving that used
     * to let a withdrawn authorization commit an order anyway.
     *
     * Nothing is locked that a sale does not depend on, and no lock is taken
     * before the customer's own row, which stays the first lock in the system's
     * global order.
     *
     * @return string|null The provider currency this sale prices in, read from
     *                     the locked row, or null when the catalog line is gone.
     */
    private function lockSaleControls(PurchaseIntent $intent): ?string
    {
        // Ordered by key, so two callers take them in the same sequence.
        $keys = [
            SettingKey::AupCurrentVersion->value,
            SettingKey::FxMaxAgeMinutes->value,
            SettingKey::SalesEnabled->value,
        ];
        sort($keys);

        DB::table('settings')->whereIn('key', $keys)->orderBy('key')->sharedLock()->get();

        $price = DB::table('product_location_prices')
            ->where('id', $intent->locationPrice->getKey())
            ->sharedLock()
            ->first();

        if ($price === null) {
            // Gone since the customer chose it. The quote below refuses it with
            // the reason a customer can be shown; nothing to lock here.
            return null;
        }

        $currency = is_string($price->provider_currency) ? $price->provider_currency : null;

        $product = DB::table('products')
            ->where('id', $price->product_id)
            ->sharedLock()
            ->first();

        if ($product === null) {
            return $currency;
        }

        DB::table('providers')->where('id', $product->provider_id)->sharedLock()->get();
        DB::table('provider_plans')->where('id', $product->provider_plan_id)->sharedLock()->get();
        DB::table('provider_locations')->where('id', $price->provider_location_id)->sharedLock()->get();

        return $currency;
    }

    /**
     * Share-lock the image this order would actually be built from.
     *
     * Last in the documented order, because which image that is only becomes
     * known once the quote has resolved the location's default.
     */
    private function lockImage(int $imageId): void
    {
        DB::table('provider_images')->where('id', $imageId)->sharedLock()->get();
    }

    /**
     * Ask the customer to pay, with an optional deadline.
     *
     * No default deadline. The specification names none, and a timeout invented
     * here would start expiring real orders on a rule nobody agreed to.
     */
    public function awaitPayment(Order $order, ?DateTimeInterface $expiresAt = null): Order
    {
        $moved = $this->states->transition(
            $order,
            OrderStatus::Pending,
            OrderStatus::AwaitingPayment,
            $expiresAt === null ? [] : ['awaiting_payment_expires_at' => CarbonImmutable::instance(
                CarbonImmutable::parse($expiresAt),
            )],
        );

        $this->audit->record(
            AuditEvent::OrderAwaitingPayment,
            subject: $moved,
            after: ['status' => $moved->status->value],
            metadata: [
                'order_id' => $moved->getKey(),
                'expires_at' => $moved->awaiting_payment_expires_at?->toIso8601String(),
            ],
        );

        return $moved;
    }

    /**
     * Pay for an order out of the customer's wallet balance.
     *
     * Release 1.0's only purchase path. A gateway funds the wallet; the wallet
     * buys the server. Keeping those separate means every purchase inherits the
     * ledger's guarantees — a balance that cannot go negative, one movement per
     * idempotency key, an immutable record of every change — instead of each
     * gateway having to reimplement them.
     *
     * Locks the customer before the order, matching the order WalletService
     * takes, so a purchase and a concurrent wallet movement queue behind the
     * same lock rather than deadlocking against each other.
     */
    public function payFromWallet(Order $order, User $payer): Order
    {
        $this->assertOwner($order, $payer);
        $this->assertCustomerMayBuy($payer);

        return DB::transaction(function () use ($order, $payer): Order {
            // User first, then order.
            $customer = User::query()->whereKey($payer->getKey())->lockForUpdate()->first();
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $customer instanceof User || ! $locked instanceof Order) {
                throw new ModelNotFoundException('The order or its owner no longer exists.');
            }

            // Re-checked on the locked row: the instance the caller passed was
            // read earlier and may belong to someone else by now.
            $this->assertOwner($locked, $customer);

            if ($locked->status->isFunded()) {
                // Already paid, by an earlier call or a concurrent one — and
                // still true if provisioning has moved it on since. Return what
                // exists rather than charging again.
                return $locked;
            }

            if ($locked->status !== OrderStatus::AwaitingPayment) {
                throw OrderNotPlaceable::because(
                    OrderRefusalReason::NotPayable,
                    "An order in {$locked->status->value} cannot be paid.",
                );
            }

            if ($locked->paymentWindowHasClosed()) {
                // Checked before any money moves.
                throw OrderNotPlaceable::because(
                    OrderRefusalReason::PaymentWindowClosed,
                    'That order was not paid before its payment window closed.',
                );
            }

            // Keyed on the order, so a replay resolves to this same ledger
            // entry rather than debiting the customer a second time. The wallet
            // refuses to go negative, so an insufficient balance stops here
            // with nothing written.
            $this->wallet->debit(
                $customer,
                $locked->total_toman,
                $locked->paymentIdempotencyKey(),
                'Server purchase',
                $locked,
            );

            $paid = $this->states->transitionLocked($locked, OrderStatus::Paid);

            $invoice = $this->invoices->issueForOrder($paid);

            // The promise to build it, written with the money that paid for it.
            //
            // Dispatching a job after this transaction commits would be the
            // obvious thing and is not enough: a worker that dies between the
            // commit and the dispatch leaves an order at paid with no
            // provisioning token, which is a state the stuck-provisioning sweep
            // cannot see — it looks for provisioning that started and stalled,
            // and this never started. The row closes that gap, because a sweep
            // over unprocessed intents finds it whatever crashed.
            //
            // Delivery may still duplicate the dispatch; that is safe, because
            // one durable token yields one remote machine however many jobs
            // arrive.
            $this->outbox->record(
                OutboxTopic::ProvisioningRequested,
                $paid,
                [
                    'order_id' => $paid->getKey(),
                    'order_number' => $paid->order_number,
                    'user_id' => $paid->user_id,
                ],
                self::provisioningRequestKey($paid),
            );

            $this->audit->record(
                AuditEvent::OrderPaid,
                actor: $customer,
                subject: $paid,
                after: ['status' => $paid->status->value],
                metadata: [
                    'order_id' => $paid->getKey(),
                    'order_number' => $paid->order_number,
                    'user_id' => $paid->user_id,
                    'total_toman' => $paid->total_toman,
                    'invoice_number' => $invoice->number,
                ],
            );

            return $paid;
        });
    }

    /**
     * Carry one purchase intent to a funded order, from wherever it stopped.
     *
     * The three durable steps behind a purchase — create, ask for payment,
     * take payment — commit separately, because each is its own transaction and
     * no two of them can be made one. A worker can die between any pair, and
     * the same Telegram update, the same queued job or the same impatient
     * customer then arrives again carrying the identical intent.
     *
     * Replaying that intent by repeating the steps does not work. `place()`
     * correctly returns the order it already made, and calling `awaitPayment()`
     * on it then asks the state machine for a move it does not have — pending is
     * the only status that transition accepts. So an order that was created and
     * never asked for payment gets stranded, and one that was already paid
     * throws instead of acknowledging a purchase that succeeded.
     *
     * This reads the order as it actually stands and performs only the step
     * that is missing:
     *
     *   pending          -> ask for payment, then pay
     *   awaiting_payment -> pay
     *   funded           -> nothing; the money already moved, the invoice
     *                       exists, and the provisioning intent is written
     *   anything else    -> refused, because it cannot be paid for
     *
     * Every guarantee still comes from the layer underneath: one debit per
     * order idempotency key, one invoice, one provisioning outbox row. This
     * removes the case where a resumable purchase was never resumed at all.
     *
     * @throws OrderNotPlaceable
     */
    public function settleFromWallet(Order $order, User $payer): Order
    {
        // Read fresh. The instance the caller holds was loaded before whatever
        // crash is being recovered from, and its status is exactly the fact
        // that changed.
        $fresh = Order::query()->whereKey($order->getKey())->first();

        if (! $fresh instanceof Order) {
            throw new ModelNotFoundException('That order no longer exists.');
        }

        $this->assertOwner($fresh, $payer);

        if ($fresh->status->isFunded()) {
            // The customer's funds are already committed to this order. Paying
            // again would be a second debit for one purchase, and asking for
            // payment again would move a paid order backwards.
            return $fresh;
        }

        if ($fresh->status === OrderStatus::Pending) {
            $fresh = $this->awaitPayment($fresh);
        }

        if ($fresh->status !== OrderStatus::AwaitingPayment) {
            // Expired, cancelled or refunded. A purchase intent does not revive
            // an order the business has already closed.
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NotPayable,
                "An order in {$fresh->status->value} cannot be paid.",
            );
        }

        return $this->payFromWallet($fresh, $payer);
    }

    /**
     * Expire an unpaid order whose window has closed.
     *
     * Moves no money, issues no invoice, owes no refund: nothing was ever
     * taken. Returns null when the order was not expirable, which includes the
     * case that matters — the customer paid a moment ago.
     */
    public function expire(Order $order): ?Order
    {
        $expired = $this->states->expireIfWindowClosed($order);

        if ($expired instanceof Order) {
            $this->audit->record(
                AuditEvent::OrderExpired,
                subject: $expired,
                after: ['status' => $expired->status->value],
                metadata: ['order_id' => $expired->getKey(), 'order_number' => $expired->order_number],
            );
        }

        return $expired;
    }

    /**
     * Let a customer call off an order they have not paid for.
     */
    public function cancel(Order $order, User $customer): Order
    {
        $this->assertOwner($order, $customer);

        $cancelled = $this->states->transition($order, $order->status, OrderStatus::Cancelled);

        $this->audit->record(
            AuditEvent::OrderCancelled,
            actor: $customer,
            subject: $cancelled,
            after: ['status' => $cancelled->status->value],
            metadata: ['order_id' => $cancelled->getKey(), 'order_number' => $cancelled->order_number],
        );

        return $cancelled;
    }

    /**
     * One of this customer's orders, or nothing.
     *
     * The customer-facing lookup, scoped by owner in the query rather than
     * checked after loading. A global find with an ownership check afterwards
     * is the same thing until someone forgets the check, and then it is an
     * order-numbered window into other people's purchases.
     */
    public function findForCustomer(User $customer, int|string $orderId): ?Order
    {
        $order = Order::query()
            ->where('user_id', $customer->getKey())
            ->whereKey($orderId)
            ->first();

        return $order instanceof Order ? $order : null;
    }

    /**
     * A globally unique order number.
     *
     * A ULID, not a counter. Two workers incrementing a counter collide, and
     * the number is quoted to customers and support, so a collision is not an
     * abstract problem. The unique index remains the final guard.
     */
    public static function newOrderNumber(): string
    {
        return 'ORD-'.strtoupper((string) Str::ulid());
    }

    /**
     * One request to build one order, however many times payment is retried.
     */
    public static function provisioningRequestKey(Order $order): string
    {
        return 'provisioning:order:'.$order->getKey().':requested';
    }

    /**
     * Refuse a purchase whose offer moved since the customer approved it.
     *
     * The comparison is against the quote just fetched from PricingService, so
     * the approved figures never become the price — they can only fail to match
     * one. A customer sending back a figure of their own choosing gets a
     * refusal, not a discount.
     *
     * Nothing is created and no money moves: the flow shows the new offer and
     * asks again.
     *
     * @throws OrderNotPlaceable
     */
    private function assertOfferUnchanged(
        PurchaseIntent $intent,
        PriceQuote $quote,
        ProviderImage $image,
        ImageSelectionMode $selectionMode,
        string $aupVersion,
    ): void {
        $approved = $intent->approved;

        if ($approved === null) {
            return;
        }

        // The intention, and separately the image this order would actually be
        // built from. Both matter: asking for the default stays a different
        // request from naming an image, and a default that has been repointed
        // since the customer read the screen is a change they should see.
        $imageId = $selectionMode === ImageSelectionMode::Explicit ? (int) $image->getKey() : null;

        if ($approved->stillMatches($quote, $aupVersion, $selectionMode, $imageId, (int) $image->getKey())) {
            return;
        }

        throw OrderNotPlaceable::because(
            OrderRefusalReason::QuoteChanged,
            'The offer changed after the customer approved it.',
        );
    }

    /**
     * Confirm a retry describes the same purchase as the order it found.
     *
     * Every field that changes what was bought, compared against the order as
     * stored. Deliberately not compared against today's price or rate: the
     * question is whether this is the same purchase, and a purchase does not
     * become a different one because the exchange rate moved.
     */
    private function assertSameIntention(Order $existing, PurchaseIntent $intent): void
    {
        if ($existing->user_id !== $intent->user->getKey()) {
            throw OrderIdempotencyConflict::on($intent->idempotencyKey, 'customer');
        }

        if ($existing->product_location_price_id !== $intent->locationPrice->getKey()) {
            throw OrderIdempotencyConflict::on($intent->idempotencyKey, 'product or location');
        }

        if ($existing->aup_version !== $intent->acceptedAupVersion) {
            throw OrderIdempotencyConflict::on($intent->idempotencyKey, 'accepted terms version');
        }

        // Compared both ways round. The mode first: asking for the location's
        // default and naming an image are different requests even when they
        // resolve to the same image today, because the default can change
        // between the original and the retry.
        $snapshotMode = $existing->pricing_snapshot['image']['selection_mode'] ?? null;

        if ($snapshotMode !== $intent->imageSelectionMode()->value) {
            throw OrderIdempotencyConflict::on($intent->idempotencyKey, 'image selection');
        }

        // Then, for an explicit choice, the image itself. A default-mode retry
        // is deliberately not re-resolved against today's default: the question
        // is whether the original request succeeded, not what it would resolve
        // to now.
        $snapshotImageId = $existing->pricing_snapshot['image']['provider_image_id'] ?? null;

        if ($intent->providerImageId !== null && $snapshotImageId !== $intent->providerImageId) {
            throw OrderIdempotencyConflict::on($intent->idempotencyKey, 'selected image');
        }
    }

    private function assertCustomerMayBuy(User $user): void
    {
        if (! $user->isActive()) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::InactiveCustomer,
                'That account cannot place orders.',
            );
        }
    }

    /**
     * Confirm the customer accepted the terms that are currently in force.
     *
     * Both halves matter. Accepting nothing is not buying; accepting last
     * month's terms is agreeing to something the business no longer offers, and
     * treating it as current would leave an order recording consent that was
     * never given to these terms.
     */
    private function requireAcceptedTerms(PurchaseIntent $intent): string
    {
        $current = $this->settings->string(SettingKey::AupCurrentVersion);

        if ($current === null || trim($current) === '') {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::TermsNotConfigured,
                'No current '.SettingKey::AupCurrentVersion->value.' is configured.',
            );
        }

        if (! $intent->aupAccepted) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::TermsNotAccepted,
                'The customer has not accepted the terms.',
            );
        }

        if ($intent->acceptedAupVersion !== $current) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::TermsVersionMismatch,
                'The accepted terms version is not the current one.',
            );
        }

        return $current;
    }

    /**
     * The image this order will be built from.
     *
     * Chosen explicitly or taken from the location's default, and validated
     * either way: it must exist, belong to the same provider, be enabled, and
     * not be deprecated. Phase 7 reads this from the snapshot rather than from
     * whatever a Telegram conversation still remembers.
     */
    private function requireSelectableImage(PurchaseIntent $intent, PriceQuote $quote): ProviderImage
    {
        $imageId = $intent->providerImageId ?? $quote->defaultImageId;

        if ($imageId === null) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NoSelectableImage,
                'No operating system image was chosen and that location has no default.',
            );
        }

        $image = ProviderImage::query()->whereKey($imageId)->first();

        if (! $image instanceof ProviderImage) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NoSelectableImage,
                'That image does not exist.',
            );
        }

        if ($image->provider_id !== $quote->providerId) {
            // Building this order would create a server from another provider's
            // image, on an account that has never heard of it.
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NoSelectableImage,
                'That image belongs to another provider.',
            );
        }

        if (! $image->enabled || $image->deprecated) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NoSelectableImage,
                'That image is not offered.',
            );
        }

        return $image;
    }

    private function assertOwner(Order $order, User $user): void
    {
        if ($order->user_id !== $user->getKey()) {
            throw OrderNotPlaceable::because(
                OrderRefusalReason::NotTheOwner,
                'That order belongs to someone else.',
            );
        }
    }

    /**
     * What the provider charges us, frozen.
     *
     * Decimals stay strings. A float here would put rounding error into every
     * margin figure computed from this order for the rest of its life.
     *
     * @return array<string, scalar|null>
     */
    private static function costSnapshot(PriceQuote $quote): array
    {
        return [
            'provider_id' => $quote->providerId,
            'provider_code' => $quote->providerCode,
            'provider_plan_id' => $quote->providerPlanId,
            'provider_plan_code' => $quote->providerPlanCode,
            'provider_location_id' => $quote->providerLocationId,
            'provider_location_code' => $quote->providerLocationCode,
            'provider_cost' => $quote->providerCost,
            'provider_currency' => $quote->providerCurrency,
            'exchange_rate_id' => $quote->exchangeRateId,
            'exchange_rate' => $quote->exchangeRate,
            'exchange_rate_effective_from' => $quote->exchangeRateEffectiveFrom->toIso8601String(),
            'converted_provider_cost_toman' => $quote->convertedProviderCostToman,
            'gross_margin_toman' => $quote->grossMarginToman,
        ];
    }

    /**
     * What the customer was told, frozen, plus the image they will get.
     *
     * Only the image's safe identity — enough for Phase 7 to build the server
     * without consulting anything mutable, and nothing from a provider payload.
     *
     * @return array<string, mixed>
     */
    private static function pricingSnapshot(
        PriceQuote $quote,
        ProviderImage $image,
        ImageSelectionMode $selectionMode,
    ): array {
        return [
            'product_id' => $quote->productId,
            'product_location_price_id' => $quote->productLocationPriceId,
            'selling_price_toman' => $quote->sellingPriceToman,
            'billing_mode' => $quote->billingMode->value,
            'billing_cycle' => $quote->billingCycle->value,
            'evaluated_at' => $quote->evaluatedAt->toIso8601String(),
            'image' => [
                'provider_image_id' => (int) $image->getKey(),
                'provider_native_id' => $image->provider_image_id,
                'name' => $image->name,
                'os_family' => $image->os_family,
                'version' => $image->version,
                'architecture' => $image->architecture,
                // How it was chosen, not just what it was. A retry that
                // switches between naming an image and taking the default is a
                // different request, and without this the order could not tell.
                'selection_mode' => $selectionMode->value,
            ],
        ];
    }

    private function findByKey(string $idempotencyKey): ?Order
    {
        $order = Order::query()->where('idempotency_key', $idempotencyKey)->first();

        return $order instanceof Order ? $order : null;
    }
}
