<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an order could not be placed or paid.
 *
 * A stable vocabulary, like the pricing refusals it sits beside. Pricing
 * refusals are re-raised as themselves — a sale blocked by a stale rate is
 * still a stale rate — and these cover the reasons that belong to the order
 * rather than to the price.
 */
enum OrderRefusalReason: string
{
    /** The customer is suspended or banned. */
    case InactiveCustomer = 'inactive_customer';

    /** The customer did not accept the terms. */
    case TermsNotAccepted = 'terms_not_accepted';

    /** They accepted a version that is not the current one. */
    case TermsVersionMismatch = 'terms_version_mismatch';

    /** No current terms version is configured, so nothing can be accepted. */
    case TermsNotConfigured = 'terms_not_configured';

    /** No usable operating system image was chosen or defaulted. */
    case NoSelectableImage = 'no_selectable_image';

    /** The order is not in a state where it can be paid. */
    case NotPayable = 'not_payable';

    /** The payment window closed before the customer paid. */
    case PaymentWindowClosed = 'payment_window_closed';

    /** Someone other than the owner tried to act on the order. */
    case NotTheOwner = 'not_the_owner';

    /**
     * The offer moved between the customer seeing it and confirming it.
     *
     * Nothing was created and nothing was charged. The customer is shown the
     * new offer and asked again, because agreeing to one price is not agreeing
     * to whatever replaced it.
     */
    case QuoteChanged = 'quote_changed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
