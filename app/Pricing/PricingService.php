<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Models\ExchangeRate;
use App\Models\ProductLocationPrice;
use App\Models\Provider;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Pricing\Data\PriceQuote;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Settings\SettingsService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether a new sale may happen, and on what terms.
 *
 * The one place that answers "can we sell this, here, right now, and for what".
 * Every reason it might say no is a refusal with a stable enum attached, so the
 * flows that ask can explain themselves without reading messages.
 *
 * It fails closed throughout. A disabled provider, a stale rate, an unreadable
 * setting and a missing cost all end the same way, because none of them is
 * evidence that selling is safe. The only path that returns a quote is the one
 * where every question was answered.
 *
 * No network call happens here and none may be added. This runs while a
 * customer waits, and a provider's availability API being slow must not become
 * this system being slow. Local catalog state, synchronised separately, is what
 * it reads.
 *
 * All arithmetic is exact decimal. There is no float anywhere in this class.
 */
final readonly class PricingService
{
    public function __construct(
        private SettingsService $settings,
        private ExchangeRateService $rates,
    ) {}

    /**
     * Price one product in one location, or refuse.
     *
     * @throws SaleNotAvailable when a new sale must not proceed.
     */
    public function quoteNewSale(ProductLocationPrice $locationPrice, ?DateTimeInterface $at = null): PriceQuote
    {
        // Immutable: the moment this decision was made is a fact about the
        // quote, and nothing downstream may move it.
        $evaluatedAt = ExchangeRateService::instant($at);

        $this->assertSalesEnabled();

        // Loaded fresh rather than trusting whatever the caller happened to
        // have in memory. A quote decides whether to take money; it reads the
        // rows as they are now.
        $price = ProductLocationPrice::query()
            ->with(['product.provider', 'product.providerPlan', 'providerLocation', 'defaultImage'])
            ->whereKey($locationPrice->getKey())
            ->first();

        if (! $price instanceof ProductLocationPrice) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::UnavailableLocation,
                'That product location price no longer exists.',
            );
        }

        $product = $price->product;
        $provider = $product->provider;
        $plan = $product->providerPlan;
        $location = $price->providerLocation;
        $image = $price->defaultImage;

        $this->assertCatalogIsCoherent($price, $provider, $plan, $location, $image);
        $this->assertProductIsSellable($product->active, $provider, $plan);
        $this->assertLocationIsSellable($price->active, $location);

        $cost = $this->requireProviderCost($price);
        $rate = $this->requireFreshRate($price->provider_currency, $evaluatedAt);

        // Exact throughout. multipliedBy on two BigDecimals keeps every digit
        // of both scales, so a cost of 4.550000 at a rate of 92345.12345678
        // produces the exact product and not something near it.
        $rateDecimal = BigDecimal::of($rate->rate_to_toman);
        $convertedCost = $cost->multipliedBy($rateDecimal);

        // Not rounded, not floored, not ceiled. The specification defines no
        // rule for turning fractional Toman into whole Toman, and choosing one
        // here would silently decide a business question on every sale.
        $sellingPrice = $price->selling_price_toman;
        $grossMargin = BigDecimal::of($sellingPrice)->minus($convertedCost);

        return new PriceQuote(
            productId: (int) $product->getKey(),
            productLocationPriceId: (int) $price->getKey(),
            providerId: (int) $provider->getKey(),
            providerCode: $provider->code,
            providerPlanId: (int) $plan->getKey(),
            providerPlanCode: $plan->provider_plan_id,
            providerLocationId: (int) $location->getKey(),
            providerLocationCode: $location->provider_location_id,
            defaultImageId: $image instanceof ProviderImage ? (int) $image->getKey() : null,
            providerCost: (string) $cost,
            providerCurrency: $price->provider_currency,
            exchangeRateId: (int) $rate->getKey(),
            exchangeRate: (string) $rateDecimal,
            exchangeRateEffectiveFrom: CarbonImmutable::instance($rate->effective_from),
            convertedProviderCostToman: (string) $convertedCost,
            sellingPriceToman: $sellingPrice,
            grossMarginToman: (string) $grossMargin,
            billingMode: $product->billing_mode,
            billingCycle: $product->billing_cycle,
            evaluatedAt: $evaluatedAt,
        );
    }

    /**
     * The operator's off switch, and the case where nobody set one.
     *
     * A missing or malformed row does not mean "yes". Someone has to have said
     * so, explicitly, for a sale to proceed.
     */
    private function assertSalesEnabled(): void
    {
        $enabled = $this->settings->boolean(SettingKey::SalesEnabled);

        if ($enabled === null) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::SalesConfigurationMissing,
                'The '.SettingKey::SalesEnabled->value.' setting is missing or unreadable.',
            );
        }

        if ($enabled === false) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::SalesDisabled,
                'New sales are currently disabled.',
            );
        }
    }

    /**
     * Refuse rows that do not belong together.
     *
     * A product for one provider priced through another provider's location
     * would provision on the wrong account, against the wrong credentials, at
     * the wrong cost. It is not a customer-facing situation; it means the
     * catalog was built wrong, and continuing would turn a configuration
     * mistake into a real server somewhere unexpected.
     */
    private function assertCatalogIsCoherent(
        ProductLocationPrice $price,
        Provider $provider,
        ProviderPlan $plan,
        ProviderLocation $location,
        ?ProviderImage $image,
    ): void {
        $providerId = $provider->getKey();

        $mismatches = [];

        if ($plan->provider_id !== $providerId) {
            $mismatches[] = 'provider plan';
        }

        if ($location->provider_id !== $providerId) {
            $mismatches[] = 'provider location';
        }

        if ($image instanceof ProviderImage && $image->provider_id !== $providerId) {
            $mismatches[] = 'default image';
        }

        if ($mismatches !== []) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::InvalidCatalogRelationship,
                'Belongs to another provider: '.implode(', ', $mismatches).'.',
            );
        }

        if ($price->provider_currency !== $plan->provider_currency) {
            // The cost was snapshotted in one currency and the plan is billed
            // in another. Converting the wrong currency produces a plausible
            // number and a wrong margin.
            throw SaleNotAvailable::because(
                SaleRefusalReason::InvalidCatalogRelationship,
                "Price currency {$price->provider_currency} does not match plan currency {$plan->provider_currency}.",
            );
        }
    }

    private function assertProductIsSellable(bool $productActive, Provider $provider, ProviderPlan $plan): void
    {
        if (! $productActive) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableProduct, 'That product is not active.');
        }

        if (! $provider->enabled) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableProduct, 'That provider is disabled.');
        }

        if (! $plan->enabled) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableProduct, 'That provider plan is disabled.');
        }
    }

    /**
     * `enabled` is our decision not to sell here; `available` is the provider
     * saying it has no capacity. Both stop a sale, for different reasons.
     */
    private function assertLocationIsSellable(bool $priceActive, ProviderLocation $location): void
    {
        if (! $priceActive) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableLocation, 'That location price is not active.');
        }

        if (! $location->enabled) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableLocation, 'That location is disabled.');
        }

        if (! $location->available) {
            throw SaleNotAvailable::because(SaleRefusalReason::UnavailableLocation, 'That location has no capacity.');
        }
    }

    /**
     * Say, once per refusal, that a rate has gone stale.
     *
     * The specification wants an operator told when stale FX starts blocking
     * sales, because the sales stopping is the symptom and nobody watching a
     * dashboard would know why. Admin alerting proper arrives with the
     * notification phase; until then a structured log line is what exists, and
     * it carries only identifiers and times — no credentials, no metadata, no
     * model dump.
     *
     * Best effort, and deliberately so. The staleness decision is already made
     * by the time this runs, and the refusal is thrown whatever happens here:
     * a log sink being down is an operational problem, not a reason to sell at
     * a rate nobody stands behind, and equally not a reason to hand the caller
     * an infrastructure error instead of the real answer.
     */
    private function warnRateIsStale(
        ExchangeRate $rate,
        string $currency,
        int $maxAgeMinutes,
        CarbonImmutable $evaluatedAt,
    ): void {
        try {
            Log::warning('pricing.fx_rate_stale', [
                'currency' => $currency,
                'exchange_rate_id' => $rate->getKey(),
                'effective_from' => CarbonImmutable::instance($rate->effective_from)->toIso8601String(),
                'evaluated_at' => $evaluatedAt->toIso8601String(),
                'threshold_minutes' => $maxAgeMinutes,
            ]);
        } catch (Throwable) {
            // Swallowed on purpose. The caller gets SaleNotAvailable either
            // way; losing the warning must not change the answer.
        }
    }

    /**
     * A sale with an unknown cost is a sale with an unknown margin.
     *
     * Refused rather than treated as zero. Zero cost would report the whole
     * selling price as profit and look healthier than a correctly configured
     * product, which is precisely the wrong incentive.
     */
    private function requireProviderCost(ProductLocationPrice $price): BigDecimal
    {
        $cost = $price->provider_cost_snapshot;

        if ($cost === null || $cost === '') {
            throw SaleNotAvailable::because(
                SaleRefusalReason::MissingProviderCost,
                'No provider cost has been recorded for that location.',
            );
        }

        return BigDecimal::of($cost);
    }

    /**
     * The rate that applies now, if there is one and it is recent enough.
     *
     * Staleness is measured against a threshold an operator sets. If nobody set
     * one, there is no basis for calling any rate fresh, so the sale stops —
     * a number invented here would be a business decision made by accident, on
     * every sale, forever.
     */
    private function requireFreshRate(string $currency, CarbonImmutable $evaluatedAt): ExchangeRate
    {
        $maxAgeMinutes = $this->settings->integer(SettingKey::FxMaxAgeMinutes);

        if ($maxAgeMinutes === null || $maxAgeMinutes < 0) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::SalesConfigurationMissing,
                'The '.SettingKey::FxMaxAgeMinutes->value.' setting is missing or unreadable.',
            );
        }

        $rate = $this->rates->currentRate($currency, $evaluatedAt);

        if (! $rate instanceof ExchangeRate) {
            throw SaleNotAvailable::because(
                SaleRefusalReason::MissingFxRate,
                "No exchange rate applies to {$currency} yet.",
            );
        }

        // The instant comparison, not a rounded age. A rate 60 minutes and one
        // second old is past a 60-minute limit, and truncating that to 60 would
        // keep selling for another 59 seconds on a rate nobody stands behind.
        if ($this->rates->isStale($rate, $maxAgeMinutes, $evaluatedAt)) {
            $this->warnRateIsStale($rate, $currency, $maxAgeMinutes, $evaluatedAt);

            $age = $this->rates->ageInMinutes($rate, $evaluatedAt);

            throw SaleNotAvailable::because(
                SaleRefusalReason::StaleFxRate,
                "The {$currency} rate is about {$age} minutes old; the limit is {$maxAgeMinutes}.",
            );
        }

        return $rate;
    }
}
