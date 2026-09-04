<?php

declare(strict_types=1);

namespace Tests\Support\Provisioning;

use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\FakeProvider;
use App\Enums\AdminRole;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductLocationPrice;
use App\Models\Provider;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\User;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use App\Pricing\ExchangeRateService;
use App\Settings\SettingsService;
use App\Wallet\WalletService;
use Illuminate\Support\Str;

/**
 * A shop whose catalog the simulated provider actually recognises.
 *
 * The ordinary SalesFloor invents provider-native identifiers, which is right
 * for pricing tests and useless here: FakeProvider validates every create
 * against its own fixed catalog and rejects anything it has never heard of. So
 * this builds the same coherent catalog with the identifiers the simulator
 * knows, under the provider code the registry maps to it.
 *
 * That alignment is the point. Phase 7 is proven end to end against a real
 * provider implementation — one with a database behind it, a unique index on
 * the provisioning token and a delete that leaves a tombstone — rather than
 * against a mock that agrees with whatever the test expects.
 */
final class ProvisioningFloor
{
    public const AUP_VERSION = '2026-01';

    public User $owner;

    public User $customer;

    public Provider $provider;

    public ProviderPlan $plan;

    public ProviderLocation $location;

    public ProviderImage $image;

    public Product $product;

    public ProductLocationPrice $price;

    private function __construct() {}

    /**
     * Open for business, with provisioning switched on.
     *
     * @param  string  $planCode  A FakeCatalog plan. The large plan in the
     *                            secondary location is the simulator's
     *                            permanently sold-out combination.
     */
    public static function open(
        int $walletBalance = 5_000_000,
        int $sellingPriceToman = 1_500_000,
        string $planCode = FakeCatalog::PLAN_SMALL,
        string $locationCode = FakeCatalog::LOCATION_PRIMARY,
    ): self {
        $self = new self;

        $self->owner = User::factory()->create();
        $self->owner->assignRole(AdminRole::Owner->value);

        $settings = app(SettingsService::class);
        $settings->set(SettingKey::SalesEnabled, true, $self->owner);
        $settings->set(SettingKey::FxMaxAgeMinutes, 1_440, $self->owner);
        $settings->set(SettingKey::AupCurrentVersion, self::AUP_VERSION, $self->owner);
        $settings->set(SettingKey::ProvisioningEnabled, true, $self->owner);
        $settings->set(SettingKey::ProvisioningStuckAfterMinutes, 10, $self->owner);

        app(ExchangeRateService::class)->recordManualRate('EUR', '92345.12345678', $self->owner);

        // The code the static registry maps to FakeProvider. Not a random one:
        // ProviderManager resolves implementations by exactly this string.
        $self->provider = Provider::factory()->create([
            'code' => FakeProvider::CODE,
            'name' => 'Fake Provider',
            'enabled' => true,
        ]);

        $self->plan = ProviderPlan::query()->create([
            'provider_id' => $self->provider->getKey(),
            // The simulator's own identifier, so a create is not rejected as
            // an unknown plan.
            'provider_plan_id' => $planCode,
            'name' => 'Fake CX11',
            'vcpu' => 1,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'provider_price_monthly' => '4.510000',
            'provider_currency' => 'EUR',
        ]);

        $self->location = ProviderLocation::query()->create([
            'provider_id' => $self->provider->getKey(),
            'provider_location_id' => $locationCode,
            'name' => 'Falkenstein',
            'country_code' => 'DE',
            'city' => 'Falkenstein',
        ]);

        $self->image = ProviderImage::query()->create([
            'provider_id' => $self->provider->getKey(),
            'provider_image_id' => FakeCatalog::IMAGE_UBUNTU,
            'name' => 'Ubuntu 24.04',
            'os_family' => 'ubuntu',
            'version' => '24.04',
            'architecture' => 'x86',
        ]);

        $self->product = Product::factory()->create([
            'provider_id' => $self->provider->getKey(),
            'provider_plan_id' => $self->plan->getKey(),
        ]);

        $self->price = ProductLocationPrice::factory()->create([
            'product_id' => $self->product->getKey(),
            'provider_location_id' => $self->location->getKey(),
            'default_image_id' => $self->image->getKey(),
            'selling_price_toman' => $sellingPriceToman,
            'provider_cost_snapshot' => '4.510000',
            'provider_currency' => 'EUR',
        ]);

        $self->customer = User::factory()->fromTelegram()->create();

        if ($walletBalance > 0) {
            app(WalletService::class)->credit(
                $self->customer, $walletBalance, 'floor-'.(string) Str::uuid(), 'Wallet top-up',
            );
        }

        return $self;
    }

    /** A customer who has bought and paid for one server. */
    public function paidOrder(): Order
    {
        $orders = app(OrderService::class);

        $order = $orders->place(new PurchaseIntent(
            $this->customer,
            $this->price,
            self::AUP_VERSION,
            true,
            (string) Str::uuid(),
        ));

        return $orders->payFromWallet($orders->awaitPayment($order), $this->customer);
    }

    /** Switch the provisioning kill switch. */
    public function setProvisioning(bool $enabled): void
    {
        app(SettingsService::class)->set(SettingKey::ProvisioningEnabled, $enabled, $this->owner);
    }
}
