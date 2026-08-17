<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an audit entry with subject and before/after payloads', function () {
    $user = User::factory()->create();

    $order = Order::query()->create([
        'order_number' => 'ORD-TEST-000001',
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING,
        'total_toman' => 100000,
    ]);

    $log = app(AuditService::class)->record(
        'order.created',
        $order,
        $user,
        before: ['status' => 'draft'],
        after: ['status' => Order::STATUS_PENDING],
    );

    expect($log)->toBeInstanceOf(AuditLog::class);
    expect($log->action)->toBe('order.created');
    expect($log->subject_type)->toBe(Order::class);
    expect($log->subject_id)->toBe($order->id);
    expect($log->user_id)->toBe($user->id);
    expect($log->before)->toBe(['status' => 'draft']);
    expect($log->after)->toBe(['status' => Order::STATUS_PENDING]);
});

it('stores audit entries in append-only fashion (no updated_at)', function () {
    $log = app(AuditService::class)->record('settings.changed');

    expect($log->created_at)->not->toBeNull();
    expect($log->getAttributes())->not->toHaveKey('updated_at');
});
