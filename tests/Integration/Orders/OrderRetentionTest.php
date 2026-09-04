<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Models\Order;
use App\Models\Payment;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Orders\SalesFloor;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->orders = app(OrderService::class);
    $this->floor = SalesFloor::open();

    $this->order = $this->orders->place(new PurchaseIntent(
        $this->floor->customer, $this->floor->catalog->price,
        SalesFloor::AUP_VERSION, true, (string) Str::uuid(),
    ));
});

/** A value of the right shape for a column, different from what is stored. */
function tamperedValue(string $column): string|int
{
    return match ($column) {
        'user_id', 'product_id', 'product_location_price_id', 'total_toman' => 999,
        'cost_snapshot', 'pricing_snapshot' => '{"tampered":true}',
        'aup_accepted_at' => '2020-01-01 00:00:00',
        default => 'tampered',
    };
}

it('refuses direct SQL changes to what the customer was quoted', function (): void {
    // The guard that still holds for a query builder call or someone at a psql
    // prompt, which is where "just fix the price on that one order" happens.
    foreach (Order::IMMUTABLE as $column) {
        expect(fn () => DB::transaction(fn () => DB::table('orders')
            ->where('id', $this->order->id)
            ->update([$column => tamperedValue($column)])))
            ->toThrow(QueryException::class, '', $column);
    }

    $stored = (array) DB::table('orders')->where('id', $this->order->id)->first();

    expect($stored['total_toman'])->toBe(1_500_000)
        ->and($stored['order_number'])->toBe($this->order->order_number)
        ->and($stored['aup_version'])->toBe(SalesFloor::AUP_VERSION);
});

it('allows direct SQL changes to the lifecycle columns', function (): void {
    // Retention is not immutability. The half of the row that moves must move.
    $uuid = (string) Str::uuid();

    $affected = DB::table('orders')->where('id', $this->order->id)->update([
        'status' => OrderStatus::Paid->value,
        'awaiting_payment_expires_at' => now()->addHour(),
        // Phase 7 owns this field and must not be blocked.
        'provisioning_uuid' => $uuid,
        'failure_category' => 'provider_rejected_no_server',
        'failure_reason' => 'a reason',
        'attempts' => 3,
        'provisioned_at' => now(),
        'updated_at' => now(),
    ]);

    $fresh = $this->order->fresh();

    expect($affected)->toBe(1)
        ->and($fresh->status)->toBe(OrderStatus::Paid)
        ->and($fresh->provisioning_uuid)->toBe($uuid)
        ->and($fresh->attempts)->toBe(3)
        ->and($fresh->provisioned_at)->not->toBeNull();
});

it('refuses to delete an order through eloquent', function (): void {
    expect(fn () => $this->order->delete())->toThrow(FinancialRecordDeletionForbidden::class);

    expect(Order::query()->whereKey($this->order->getKey())->exists())->toBeTrue();
});

it('refuses a raw sql delete from orders', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $this->order->id)->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('orders')->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('orders')->count())->toBe(1);
});

it('uses no soft deletes to fake retention', function (): void {
    expect(Schema::hasColumn('orders', 'deleted_at'))->toBeFalse()
        ->and(in_array(Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(Order::class), true))
        ->toBeFalse();
});

it('keeps an order when its customer, product or price row is removed', function (): void {
    // An order is evidence and must outlive the rows it was placed against.
    foreach ([
        fn () => $this->floor->customer->delete(),
        fn () => $this->floor->catalog->product->delete(),
        fn () => $this->floor->catalog->price->delete(),
    ] as $attempt) {
        expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
    }

    expect(Order::query()->count())->toBe(1);
});

it('keeps a paid order and its invoice linked', function (): void {
    $paid = $this->orders->payFromWallet(
        $this->orders->awaitPayment($this->order), $this->floor->customer,
    );

    $invoice = App\Models\Invoice::query()->where('order_id', $paid->id)->sole();

    // The foreign key is real now, and restrictive.
    expect($invoice->order->getKey())->toBe($paid->getKey())
        ->and(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $paid->id)->delete()))
        ->toThrow(QueryException::class);
});

it('wires payments to orders without making the link required', function (): void {
    // A wallet top-up is a payment with no order behind it, and that is the
    // normal case rather than an omission.
    $payment = Payment::factory()->create(['user_id' => $this->floor->customer->id]);

    expect($payment->order_id)->toBeNull()
        ->and($payment->order)->toBeNull();

    expect(fn () => DB::transaction(fn () => DB::table('payments')
        ->where('id', $payment->id)->update(['order_id' => 999_999])))
        ->toThrow(QueryException::class);

    DB::table('payments')->where('id', $payment->id)->update(['order_id' => $this->order->id]);

    expect($payment->fresh()->order->getKey())->toBe($this->order->getKey());
});

it('installs the order guards and nothing more', function (): void {
    $triggers = DB::table('pg_trigger as t')
        ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
        ->where('c.relname', 'orders')
        ->where('t.tgisinternal', false)
        ->pluck('t.tgname')
        ->sort()->values()->all();

    // Three now: retention, the quoted-snapshot freeze, and Phase 7's
    // write-once guard on the provisioning token.
    expect($triggers)->toBe([
        'orders_no_delete', 'orders_no_provisioning_uuid_change', 'orders_no_snapshot_change',
    ]);
});

it('constrains order status at the database level', function (): void {
    foreach (['shipped', 'refunding', ''] as $bad) {
        expect(fn () => DB::transaction(fn () => DB::table('orders')
            ->where('id', $this->order->id)->update(['status' => $bad])))
            ->toThrow(QueryException::class, '', $bad);
    }
});

it('constrains the failure category at the database level', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('orders')
        ->where('id', $this->order->id)->update(['failure_category' => 'made_up'])))
        ->toThrow(QueryException::class);

    // Null stays legal: most orders never fail.
    DB::table('orders')->where('id', $this->order->id)->update(['failure_category' => null]);

    expect($this->order->fresh()->failure_category)->toBeNull();
});

it('refuses a non-positive order total', function (): void {
    foreach ([0, -1] as $bad) {
        expect(fn () => DB::transaction(fn () => DB::table('orders')->insert([
            'user_id' => $this->floor->customer->id,
            'product_id' => $this->floor->catalog->product->id,
            'product_location_price_id' => $this->floor->catalog->price->id,
            'order_number' => OrderService::newOrderNumber(), 'status' => 'pending',
            'total_toman' => $bad, 'idempotency_key' => (string) Str::uuid(),
            'cost_snapshot' => '{}', 'pricing_snapshot' => '{}', 'aup_version' => 'x',
            'aup_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    }
});

it('ships no table belonging to a later phase', function (): void {
    // The scope-creep guard, moved forward with the build. Server actions and
    // notification history arrived with the sales and management phase;
    // Hetzner's own tables and Release 1.1 billing have not.
    foreach ([
        'billing_charges', 'hetzner_server_types', 'usage_samples',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} belongs to a later phase");
    }
});
