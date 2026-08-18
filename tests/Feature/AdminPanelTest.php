<?php

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Database\Seeders\FakeProviderSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('shows the admin login page', function () {
    $this->get('/admin/login')->assertStatus(200);
});

it('redirects guests to login when visiting the admin panel', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('blocks non-admin users from the panel', function () {
    $customer = User::factory()->create(['is_admin' => false]);

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('lets an admin reach the dashboard', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertStatus(200);
});

it('lets an admin open the product resource pages', function () {
    $this->seed(FakeProviderSeeder::class);
    $admin = User::factory()->create(['is_admin' => true]);

    // List + create pages render the billing mode / hourly price form schema.
    $this->actingAs($admin)->get('/admin/products')->assertStatus(200);
    $this->actingAs($admin)->get('/admin/products/create')->assertStatus(200);
});

it('lets an admin open the product edit page', function () {
    $this->seed(FakeProviderSeeder::class);
    $admin = User::factory()->create(['is_admin' => true]);

    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();

    $this->actingAs($admin)->get('/admin/products/'.$product->id.'/edit')->assertStatus(200);
});

it('lets an admin open the server edit page with the hourly billing ledger relation manager', function () {
    $this->seed(FakeProviderSeeder::class);
    $user = User::factory()->create();

    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();
    $order = app(OrderService::class)->place($user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);
    app(PaymentService::class)->provision($order->fresh());

    $server = $order->fresh()->server;
    expect($server)->not->toBeNull();

    $admin = User::factory()->create(['is_admin' => true]);

    // Edit page renders the read-only ServerBillingPeriodRelationManager.
    $this->actingAs($admin)->get('/admin/servers/'.$server->id.'/edit')->assertStatus(200);
});

it('shows hourly billing form fields conditionally per billing mode', function () {
    $this->seed(FakeProviderSeeder::class);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $hourlyModel = 'wire:model="data.hourly_price_toman"';
    $capModel = 'wire:model="data.monthly_cap_toman"';

    // Monthly: neither hourly field is rendered.
    Livewire::test(CreateProduct::class)
        ->assertDontSee($hourlyModel, false)
        ->assertDontSee($capModel, false);

    // Hourly: hourly price rendered, cap hidden.
    Livewire::test(CreateProduct::class)
        ->set('data.billing_mode', 'hourly')
        ->assertSee($hourlyModel, false)
        ->assertDontSee($capModel, false);

    // Hourly capped: both fields rendered.
    Livewire::test(CreateProduct::class)
        ->set('data.billing_mode', 'hourly_capped')
        ->assertSee($hourlyModel, false)
        ->assertSee($capModel, false);
});
