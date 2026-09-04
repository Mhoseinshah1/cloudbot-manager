<?php

declare(strict_types=1);

namespace Tests\Support\Orders;

use App\Enums\AdminRole;
use App\Enums\SettingKey;
use App\Models\User;
use App\Pricing\ExchangeRateService;
use App\Settings\SettingsService;
use App\Wallet\WalletService;
use Illuminate\Support\Str;
use Tests\Support\Catalog\CatalogBuilder;

/**
 * A business that is open, with a catalog, a rate, and a customer who can pay.
 *
 * Placing an order needs sales enabled, a fresh exchange rate, a current terms
 * version, a coherent catalog and a funded customer. Assembling all of that in
 * every test would bury the one condition each test is actually about.
 */
final class SalesFloor
{
    public const AUP_VERSION = '2026-01';

    public User $owner;

    public User $customer;

    public CatalogBuilder $catalog;

    private function __construct() {}

    public static function open(int $walletBalance = 5_000_000): self
    {
        $self = new self;

        $self->owner = User::factory()->create();
        $self->owner->assignRole(AdminRole::Owner->value);

        $settings = app(SettingsService::class);
        $settings->set(SettingKey::SalesEnabled, true, $self->owner);
        $settings->set(SettingKey::FxMaxAgeMinutes, 1_440, $self->owner);
        $settings->set(SettingKey::AupCurrentVersion, self::AUP_VERSION, $self->owner);

        app(ExchangeRateService::class)->recordManualRate('EUR', '92345.12345678', $self->owner);

        $self->catalog = CatalogBuilder::make();
        $self->customer = User::factory()->fromTelegram()->create();

        if ($walletBalance > 0) {
            app(WalletService::class)->credit(
                $self->customer, $walletBalance, 'floor-'.(string) Str::uuid(), 'Wallet top-up',
            );
        }

        return $self;
    }
}
