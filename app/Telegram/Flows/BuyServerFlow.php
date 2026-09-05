<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\ImageSelectionMode;
use App\Enums\OrderRefusalReason;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductLocationPrice;
use App\Models\ProviderImage;
use App\Models\User;
use App\Orders\Data\ApprovedQuote;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\OrderNotPlaceable;
use App\Orders\Exceptions\PurchaseNotAllowed;
use App\Orders\OrderService;
use App\Orders\PurchasePolicyService;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\TelegramApiClient;
use App\Wallet\Exceptions\InsufficientBalance;

/**
 * Choosing a server, seeing what it costs, and paying for it.
 *
 * The shape is the specification's: product, location, image, a fresh price,
 * the terms, then payment. What makes it safe is what happens between the
 * steps.
 *
 * The price shown is always fetched again at confirmation, and compared against
 * what the customer approved. Between reading a screen and pressing a button a
 * person can be gone for a day: an operator may have changed the price, the
 * exchange rate may have moved, the terms may have been replaced. Charging the
 * new figure because they agreed to the old one is taking money for something
 * they never saw, so a change refuses the sale, shows the new offer, and asks
 * again.
 *
 * The purchase intent is generated once, when the flow begins, and becomes the
 * order's idempotency key. A double-tapped confirm, a Telegram retry and a
 * requeued job all carry the same intent, and one intent is one order — not
 * because this code checks, but because PostgreSQL refuses the second insert.
 *
 * Nothing here calls a provider. Availability at the provider is re-checked
 * where servers are actually built; asking from the interactive worker would
 * make every customer's tap wait on somebody else's network.
 */
final readonly class BuyServerFlow
{
    /** Small enough to read on a phone, and to keep a keyboard sane. */
    private const PER_PAGE = 6;

    public function __construct(
        private TelegramApiClient $telegram,
        private FlowState $state,
        private PricingService $pricing,
        private OrderService $orders,
        private PurchasePolicyService $policy,
        private SettingsService $settings,
    ) {}

    /**
     * Start again, from a clean flow.
     *
     * Always a new token and a new purchase intent. Resuming a half-finished
     * purchase from an unknown point is how somebody confirms a server they no
     * longer remember choosing.
     */
    public function start(FlowContext $context, int $page = 1): void
    {
        if (! $context->customer->isActive()) {
            $this->say($context->chatId, BuyMessages::RESTRICTED);

            return;
        }

        // Told early, before they choose anything. The real enforcement is in
        // the order boundary, where the customer's row is locked; this is the
        // courtesy of not letting somebody pick a server they cannot buy.
        $refusal = $this->purchaseRefusal($context->customer);

        if ($refusal !== null) {
            $this->say($context->chatId, $refusal);

            return;
        }

        $token = $this->state->begin($context->telegramUserId, FlowState::BUY_SERVER, [
            'stage' => FlowState::STAGE_PRODUCT,
            'purchase_intent_id' => FlowState::newPurchaseIntentId(),
            'page' => $page,
        ]);

        $this->showProducts($context->chatId, $token, $page);
    }

    /**
     * Another page of products, inside the same flow.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function page(FlowContext $context, array $state, int $page): void
    {
        $token = (string) FlowState::stringOf($state, 'flow_ref');

        $this->state->advance($context->telegramUserId, $state, ['page' => $page]);
        $this->showProducts($context->chatId, $token, $page);
    }

    /**
     * A product was chosen. Offer the locations it is actually sold in.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function chooseProduct(FlowContext $context, array $state, int $productId): void
    {
        $product = $this->sellableProducts()->firstWhere('id', $productId);

        if (! $product instanceof Product) {
            // Withdrawn, or never theirs to choose. The same answer either way.
            $this->say($context->chatId, BuyMessages::OPTION_GONE);

            return;
        }

        $locations = $this->locationsFor($product);

        if ($locations->isEmpty()) {
            $this->say($context->chatId, BuyMessages::NO_LOCATIONS);

            return;
        }

        $token = (string) FlowState::stringOf($state, 'flow_ref');

        $this->state->advance($context->telegramUserId, $state, [
            'stage' => FlowState::STAGE_LOCATION,
            'product_id' => $productId,
            // Anything chosen later in the previous run of this step is no
            // longer true. Cleared rather than left to be read by accident.
            'product_location_price_id' => null,
            'provider_image_id' => null,
            'image_selection_mode' => null,
        ]);

        $buttons = $locations->map(fn (ProductLocationPrice $price): array => [[
            'text' => BuyMessages::locationLabel($price),
            'callback_data' => CallbackGrammar::buyLocation($token, (int) $price->getKey()),
        ]])->all();

        $this->telegram->sendMessage($context->chatId, BuyMessages::CHOOSE_LOCATION, [
            'inline_keyboard' => [...$buttons, [BuyMessages::cancelButton($token)]],
        ]);
    }

    /**
     * A location was chosen. Offer the images that provider actually has.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function chooseLocation(FlowContext $context, array $state, int $locationPriceId): void
    {
        $productId = FlowState::intOf($state, 'product_id');

        if ($productId === null) {
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        // Re-checked against the product this flow actually chose. A button can
        // name any row; only one belonging to this product may be taken.
        $price = $this->locationsFor($this->productOrFail($productId))
            ->firstWhere('id', $locationPriceId);

        if (! $price instanceof ProductLocationPrice) {
            $this->say($context->chatId, BuyMessages::OPTION_GONE);

            return;
        }

        $token = (string) FlowState::stringOf($state, 'flow_ref');

        $next = $this->state->advance($context->telegramUserId, $state, [
            'stage' => FlowState::STAGE_IMAGE,
            'product_location_price_id' => $locationPriceId,
            'provider_image_id' => null,
            'image_selection_mode' => null,
        ]);

        $images = $this->imagesFor($price);

        if ($images->isEmpty() && $price->default_image_id === null) {
            $this->say($context->chatId, BuyMessages::NO_IMAGES);

            return;
        }

        $buttons = $images->map(fn (ProviderImage $image): array => [[
            'text' => BuyMessages::imageLabel($image),
            'callback_data' => CallbackGrammar::buyImage($token, (int) $image->getKey()),
        ]])->all();

        if ($price->default_image_id !== null) {
            // A real choice of its own, not the absence of one. Phase 6 records
            // which it was, because a retry that switches between naming an
            // image and taking the default is a different purchase.
            $buttons[] = [[
                'text' => BuyMessages::DEFAULT_IMAGE,
                'callback_data' => CallbackGrammar::buyDefaultImage($token),
            ]];
        }

        unset($next);

        $this->telegram->sendMessage($context->chatId, BuyMessages::CHOOSE_IMAGE, [
            'inline_keyboard' => [...$buttons, [BuyMessages::cancelButton($token)]],
        ]);
    }

    /**
     * An image was chosen. Price it, now, and show what it costs.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function chooseImage(FlowContext $context, array $state): void
    {
        $price = $this->locationPriceFrom($state);

        if (! $price instanceof ProductLocationPrice) {
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        $imageId = null;

        if (! $context->parameters->wantsDefault) {
            // Must be an image this provider actually offers. A button can name
            // any row, including another provider's.
            $image = $this->imagesFor($price)->firstWhere('id', $context->id());

            if (! $image instanceof ProviderImage) {
                $this->say($context->chatId, BuyMessages::OPTION_GONE);

                return;
            }

            $imageId = (int) $image->getKey();
        }

        $mode = $imageId === null ? ImageSelectionMode::Default : ImageSelectionMode::Explicit;

        $next = $this->state->advance($context->telegramUserId, $state, [
            'provider_image_id' => $imageId,
            'image_selection_mode' => $mode->value,
        ]);

        $this->preview($context, $next);
    }

    /**
     * Show the offer: a fresh quote, and the terms to accept.
     *
     * The quote is fetched here rather than assembled from the catalog rows,
     * because fetching it is what re-runs the sales kill switch, the FX
     * freshness check and the catalog checks. A price computed by hand would
     * skip all three.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function preview(FlowContext $context, array $state): void
    {
        $price = $this->locationPriceFrom($state);

        if (! $price instanceof ProductLocationPrice) {
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        try {
            $quote = $this->pricing->quoteNewSale($price);
        } catch (SaleNotAvailable $unavailable) {
            $this->say($context->chatId, BuyMessages::saleUnavailable($unavailable->reason->value));

            return;
        }

        $aup = $this->settings->string(SettingKey::AupCurrentVersion);

        if ($aup === null) {
            // Nobody can accept terms that have not been declared.
            $this->say($context->chatId, BuyMessages::TERMS_MISSING);

            return;
        }

        $token = (string) FlowState::stringOf($state, 'flow_ref');
        $mode = FlowState::imageModeOf($state);
        $imageId = FlowState::intOf($state, 'provider_image_id');
        $resolved = $imageId ?? $quote->defaultImageId;

        if ($resolved === null) {
            $this->say($context->chatId, BuyMessages::NO_IMAGES);

            return;
        }

        // Snapshotted into the conversation so the confirmation can send back
        // exactly what was displayed. Scalars only: the quote object itself
        // would be a stale copy of a row that has since changed, which is the
        // very thing this comparison exists to catch.
        $this->state->advance($context->telegramUserId, $state, [
            'stage' => FlowState::STAGE_TERMS,
            'preview_price_toman' => $quote->sellingPriceToman,
            'preview_exchange_rate_id' => $quote->exchangeRateId,
            'preview_exchange_rate' => $quote->exchangeRate,
            'preview_aup_version' => $aup,
            'preview_image_id' => $resolved,
        ]);

        $this->telegram->sendMessage(
            $context->chatId,
            BuyMessages::preview($price, $quote, $aup, $mode, $this->imageName($resolved)),
            ['inline_keyboard' => [
                [['text' => BuyMessages::ACCEPT_TERMS, 'callback_data' => CallbackGrammar::buyAcceptTerms($token)]],
                [BuyMessages::cancelButton($token)],
            ]],
        );
    }

    /**
     * The terms were accepted. Show the balance and the pay button.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function acceptTerms(FlowContext $context, array $state): void
    {
        $approved = FlowState::intOf($state, 'preview_price_toman');
        $version = FlowState::stringOf($state, 'preview_aup_version');

        if ($approved === null || $version === null) {
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        $current = $this->settings->string(SettingKey::AupCurrentVersion);

        if ($current === null || $current !== $version) {
            // The terms changed while they were reading. Accepting the old ones
            // is not accepting these, so the offer is shown again.
            $this->preview($context, $state);

            return;
        }

        $token = (string) FlowState::stringOf($state, 'flow_ref');
        $balance = ($context->customer->fresh() ?? $context->customer)->wallet_balance_toman;

        if ($balance < $approved) {
            // A courtesy, not a decision. The wallet itself refuses to go
            // negative under a row lock, and a concurrent debit can still make
            // this insufficient a moment later — so nothing is created here,
            // and the shortfall is simply shown.
            $this->telegram->sendMessage(
                $context->chatId,
                BuyMessages::insufficient($balance, $approved),
                ['inline_keyboard' => [[BuyMessages::walletButton()], [BuyMessages::cancelButton($token)]]],
            );

            return;
        }

        $this->state->advance($context->telegramUserId, $state, ['stage' => FlowState::STAGE_PREVIEW]);

        $this->telegram->sendMessage(
            $context->chatId,
            BuyMessages::confirm($approved, $balance),
            ['inline_keyboard' => [
                [['text' => BuyMessages::PAY, 'callback_data' => CallbackGrammar::buyConfirm($token)]],
                [BuyMessages::cancelButton($token)],
            ]],
        );
    }

    /**
     * Place the order and pay for it.
     *
     * Every guard is re-run here rather than trusted from the screens before
     * it. The customer's status, the abuse limits, the terms, the price: all of
     * them can change between a preview and a confirmation, and the whole point
     * of this step is that it happens at the moment money moves.
     *
     * @param  array<string, scalar|null>  $state
     */
    public function confirm(FlowContext $context, array $state): void
    {
        if (! $context->customer->isActive()) {
            $this->say($context->chatId, BuyMessages::RESTRICTED);

            return;
        }

        if (FlowState::stringOf($state, 'stage') !== FlowState::STAGE_PREVIEW) {
            // A confirmation that arrived before a preview was ever shown is
            // not a purchase, whatever button produced it.
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        $price = $this->locationPriceFrom($state);
        $intentId = FlowState::stringOf($state, 'purchase_intent_id');
        $version = FlowState::stringOf($state, 'preview_aup_version');
        $approvedPrice = FlowState::intOf($state, 'preview_price_toman');
        $approvedImage = FlowState::intOf($state, 'preview_image_id');

        if (! $price instanceof ProductLocationPrice || $intentId === null || $version === null
            || $approvedPrice === null || $approvedImage === null) {
            $this->say($context->chatId, BuyMessages::EXPIRED);

            return;
        }

        $intent = new PurchaseIntent(
            user: $context->customer,
            locationPrice: $price,
            acceptedAupVersion: $version,
            aupAccepted: true,
            // Derived from the durable purchase identity generated when this
            // flow began. A double-tapped confirm, a Telegram retry and a
            // requeued job all carry this same key, and PostgreSQL turns them
            // into one order.
            idempotencyKey: self::orderKey($intentId),
            providerImageId: FlowState::intOf($state, 'provider_image_id'),
            approved: new ApprovedQuote(
                sellingPriceToman: $approvedPrice,
                productId: (int) $price->product_id,
                productLocationPriceId: (int) $price->getKey(),
                imageSelectionMode: FlowState::imageModeOf($state),
                providerImageId: FlowState::intOf($state, 'provider_image_id'),
                resolvedProviderImageId: $approvedImage,
                aupVersion: $version,
                exchangeRateId: FlowState::intOf($state, 'preview_exchange_rate_id'),
                exchangeRate: FlowState::stringOf($state, 'preview_exchange_rate'),
            ),
        );

        try {
            $order = $this->orders->place($intent);
            $order = $this->orders->payFromWallet($this->orders->awaitPayment($order), $context->customer);
        } catch (OrderNotPlaceable $refused) {
            $this->handleRefusal($context, $state, $refused);

            return;
        } catch (PurchaseNotAllowed $blocked) {
            $this->say($context->chatId, BuyMessages::purchaseBlocked($blocked));

            return;
        } catch (SaleNotAvailable $unavailable) {
            $this->say($context->chatId, BuyMessages::saleUnavailable($unavailable->reason->value));

            return;
        } catch (InsufficientBalance) {
            // A concurrent debit spent it between the screen and the button.
            $this->say($context->chatId, BuyMessages::SPENT_ELSEWHERE);

            return;
        }

        // Done with. The flow is forgotten so a stale keyboard cannot re-enter
        // it, and the order's own idempotency key covers any late retry.
        $this->state->forget($context->telegramUserId);

        $this->telegram->sendMessage($context->chatId, BuyMessages::ordered($order->order_number), [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    public function cancel(FlowContext $context, array $state): void
    {
        unset($state);

        $this->state->forget($context->telegramUserId);

        $this->telegram->sendMessage($context->chatId, BuyMessages::CANCELLED, [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }

    /**
     * What a refusal means for the customer in front of us.
     *
     * @param  array<string, scalar|null>  $state
     */
    private function handleRefusal(FlowContext $context, array $state, OrderNotPlaceable $refused): void
    {
        if ($refused->reason === OrderRefusalReason::QuoteChanged
            || $refused->reason === OrderRefusalReason::TermsVersionMismatch) {
            // Nothing was created and nothing was charged. They are shown the
            // new offer and asked again, because agreeing to one price is not
            // agreeing to whatever replaced it.
            $this->say($context->chatId, BuyMessages::OFFER_CHANGED);
            $this->preview($context, $state);

            return;
        }

        $this->say($context->chatId, BuyMessages::refusal($refused->reason));
    }

    /**
     * The order key one purchase intent produces.
     *
     * Deterministic, and derived from a random identity rather than from
     * anything a customer could choose or a clock could repeat.
     */
    public static function orderKey(string $purchaseIntentId): string
    {
        return 'telegram:purchase:'.$purchaseIntentId;
    }

    private function showProducts(int $chatId, string $token, int $page): void
    {
        $products = $this->sellableProducts();

        if ($products->isEmpty()) {
            $this->say($chatId, BuyMessages::NOTHING_FOR_SALE);

            return;
        }

        $pages = max(1, (int) ceil($products->count() / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $slice = $products->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $buttons = $slice->map(fn (Product $product): array => [[
            'text' => BuyMessages::productLabel($product),
            'callback_data' => CallbackGrammar::buyProduct($token, (int) $product->getKey()),
        ]])->values()->all();

        $navigation = [];

        if ($page > 1) {
            $navigation[] = ['text' => BuyMessages::PREVIOUS, 'callback_data' => CallbackGrammar::buyPage($token, $page - 1)];
        }

        if ($page < $pages) {
            $navigation[] = ['text' => BuyMessages::NEXT, 'callback_data' => CallbackGrammar::buyPage($token, $page + 1)];
        }

        $keyboard = $buttons;

        if ($navigation !== []) {
            $keyboard[] = $navigation;
        }

        $keyboard[] = [BuyMessages::cancelButton($token)];

        $this->telegram->sendMessage($chatId, BuyMessages::chooseProduct($page, $pages), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    /**
     * Products a customer may actually be offered.
     *
     * Filtered on the local catalog only. Every one of these joins is a row an
     * operator can switch off, and the provider is never asked — a customer
     * opening a menu must not wait on somebody else's network, and the real
     * availability check happens where servers are built.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function sellableProducts(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->with(['provider', 'providerPlan'])
            ->where('active', true)
            ->whereHas('provider', fn ($query) => $query->where('enabled', true))
            ->whereHas('providerPlan', fn ($query) => $query->where('enabled', true))
            ->whereHas('locationPrices', function ($query): void {
                $query->where('active', true)
                    ->whereHas('providerLocation', fn ($inner) => $inner->where('enabled', true)->where('available', true));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * The locations one product is actually sold in.
     *
     * @return \Illuminate\Support\Collection<int, ProductLocationPrice>
     */
    private function locationsFor(Product $product): \Illuminate\Support\Collection
    {
        return ProductLocationPrice::query()
            ->with('providerLocation')
            ->where('product_id', $product->getKey())
            ->where('active', true)
            ->whereHas('providerLocation', fn ($query) => $query->where('enabled', true)->where('available', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * The images that price row's provider offers.
     *
     * Scoped to the provider behind the product, so a button naming another
     * provider's image finds nothing.
     *
     * @return \Illuminate\Support\Collection<int, ProviderImage>
     */
    private function imagesFor(ProductLocationPrice $price): \Illuminate\Support\Collection
    {
        $product = $price->product;

        return ProviderImage::query()
            ->where('provider_id', $product->provider_id)
            ->where('enabled', true)
            ->where('deprecated', false)
            ->orderBy('id')
            ->limit(self::PER_PAGE * 2)
            ->get();
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    private function locationPriceFrom(array $state): ?ProductLocationPrice
    {
        $productId = FlowState::intOf($state, 'product_id');
        $priceId = FlowState::intOf($state, 'product_location_price_id');

        if ($productId === null || $priceId === null) {
            return null;
        }

        $price = ProductLocationPrice::query()
            ->with(['product', 'providerLocation'])
            ->where('product_id', $productId)
            ->whereKey($priceId)
            ->first();

        return $price instanceof ProductLocationPrice ? $price : null;
    }

    private function productOrFail(int $productId): Product
    {
        $product = Product::query()->whereKey($productId)->first();

        return $product instanceof Product ? $product : new Product;
    }

    private function imageName(int $imageId): string
    {
        $image = ProviderImage::query()->whereKey($imageId)->first();

        return $image instanceof ProviderImage ? BuyMessages::imageLabel($image) : '—';
    }

    /** Why this customer cannot buy right now, in their own language. */
    private function purchaseRefusal(User $customer): ?string
    {
        try {
            $this->policy->assertMayPurchase($customer);

            return null;
        } catch (PurchaseNotAllowed $blocked) {
            return BuyMessages::purchaseBlocked($blocked);
        }
    }

    private function say(int $chatId, string $message): void
    {
        $this->telegram->sendMessage($chatId, $message, [
            'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
        ]);
    }
}
