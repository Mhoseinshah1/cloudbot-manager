<?php

declare(strict_types=1);

namespace App\Orders\Data;

use App\Enums\ImageSelectionMode;
use App\Pricing\Data\PriceQuote;
use Brick\Math\BigDecimal;

/**
 * What the customer was actually shown before they pressed pay.
 *
 * A customer approves a specific offer: this server, in this place, running
 * this image, for this many Toman, under these terms. Between seeing that and
 * confirming it, an operator can change the price, the rate can move, and the
 * terms can be replaced — and charging the new figure because the customer
 * agreed to the old one is taking money for something they never saw.
 *
 * So the flow sends back what it displayed, and the order boundary compares it
 * against the quote it just fetched itself. This is emphatically not where the
 * price comes from. It is a claim about what was shown, and the only thing it
 * can do is stop a sale — a mismatch refuses, it never sets a price. A customer
 * who could send a lower figure and have it honoured would be naming their own
 * price, which is why the comparison is one-directional.
 */
final readonly class ApprovedQuote
{
    public function __construct(
        public int $sellingPriceToman,
        public int $productId,
        public int $productLocationPriceId,
        public ImageSelectionMode $imageSelectionMode,
        public ?int $providerImageId,
        /**
         * The image the customer actually saw named, however it was chosen.
         *
         * Kept beside the intention rather than instead of it. The intention is
         * what a retry is compared against — asking for "the default" stays a
         * different request from naming an image — but a customer shown
         * "Ubuntu 24.04" must not silently receive Debian because an operator
         * repointed the location's default while they were reading the screen.
         */
        public int $resolvedProviderImageId,
        public string $aupVersion,
        /** Which rate the shown price was computed against, when one applied. */
        public ?int $exchangeRateId,
        /** That rate's exact decimal value, as a string. */
        public ?string $exchangeRate,
        /**
         * The currency that rate converts from, when the preview knew it.
         *
         * Optional because older previews did not carry it; the price row
         * already pins the currency, so its absence narrows nothing.
         */
        public ?string $exchangeRateCurrency = null,
    ) {}

    /**
     * Whether today's quote is still the offer the customer approved.
     *
     * Every field that a customer would notice changing, plus the FX authority
     * the preview was priced against.
     *
     * The rate is compared by *value*, not by row identity. Rates are appended
     * rather than updated, so recording the same number again — an operator
     * re-entering today's rate, an import that runs twice — mints a new id for
     * an identical figure. Refusing the sale for that told a customer their
     * price had changed when nothing about it had, and sent them round the
     * confirmation loop for a number that was never different. The snapshot is
     * what the price was computed from; which historical row happened to carry
     * it is an audit fact, and the order keeps it either way.
     *
     * A materially different figure still refuses, and so does a different
     * currency.
     */
    public function stillMatches(
        PriceQuote $quote,
        string $currentAupVersion,
        ImageSelectionMode $mode,
        ?int $imageId,
        int $resolvedImageId,
    ): bool {
        if ($this->sellingPriceToman !== $quote->sellingPriceToman) {
            return false;
        }

        if ($this->productId !== $quote->productId || $this->productLocationPriceId !== $quote->productLocationPriceId) {
            return false;
        }

        if ($this->imageSelectionMode !== $mode || $this->providerImageId !== $imageId) {
            return false;
        }

        if ($this->resolvedProviderImageId !== $resolvedImageId) {
            return false;
        }

        if ($this->aupVersion !== $currentAupVersion) {
            return false;
        }

        // Only checked when the preview was bound to a rate. A preview that
        // never depended on FX must not start failing because one moved.
        if ($this->exchangeRateCurrency !== null
            && strcasecmp($this->exchangeRateCurrency, $quote->providerCurrency) !== 0) {
            // A different currency is a different price, whatever the digits say.
            return false;
        }

        return ! ($this->exchangeRate !== null && ! self::sameDecimal($this->exchangeRate, $quote->exchangeRate));
    }

    /**
     * What the flow will show again if the offer moved.
     *
     * @return array<string, scalar|null>
     */
    public static function summarize(PriceQuote $quote, string $aupVersion): array
    {
        return [
            'selling_price_toman' => $quote->sellingPriceToman,
            'aup_version' => $aupVersion,
        ];
    }

    /**
     * Compare two exact decimals without turning either into a float.
     *
     * "92345.10" and "92345.1" are the same rate written differently, and a
     * customer must not be sent round the confirmation loop by trailing zeroes.
     * BigDecimal compares the digits; a float comparison would answer a
     * slightly different question about slightly different numbers.
     *
     * Through `brick/math` rather than `bccomp()`, which this package does not
     * require: on a host without ext-bcmath the bare function call raises an
     * `Error` in the middle of placing an order. The comparison is the same.
     */
    private static function sameDecimal(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (! self::isDecimal($left) || ! self::isDecimal($right)) {
            return false;
        }

        return BigDecimal::of($left)->compareTo(BigDecimal::of($right)) === 0;
    }

    private static function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }
}
