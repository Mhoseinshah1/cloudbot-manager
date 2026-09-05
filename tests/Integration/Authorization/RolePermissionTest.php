<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();
});

it('provisions exactly the three privileged roles', function (): void {
    expect(RoleModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['owner', 'finance', 'support']);
});

it('provisions every declared permission', function (): void {
    expect(PermissionModel::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Permission::values());
});

it('can be run repeatedly without duplicating anything', function (): void {
    // The installer and every update run this. Running it twice must be
    // indistinguishable from running it once.
    app(RoleProvisioner::class)->sync();
    app(RoleProvisioner::class)->sync();

    expect(RoleModel::query()->count())->toBe(3)
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

it('revokes a permission a role should no longer hold', function (): void {
    // Definition in code is the whole truth. A permission granted by hand, or
    // left behind by an earlier release, must not survive the next sync.
    RoleModel::findByName(AdminRole::Support->value)
        ->givePermissionTo(Permission::WalletAdjust->value);

    app(RoleProvisioner::class)->sync();

    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    expect($support->can(Permission::WalletAdjust->value))->toBeFalse();
});

it('gives the owner full operational access', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole(AdminRole::Owner->value);

    foreach (Permission::cases() as $permission) {
        expect($owner->can($permission->value))->toBeTrue("owner should hold {$permission->value}");
    }
});

it('gives finance the financial permissions', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    foreach (Permission::financial() as $permission) {
        expect($finance->can($permission->value))->toBeTrue("finance should hold {$permission->value}");
    }
});

it('does not give finance power over servers or provisioning', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole(AdminRole::Finance->value);

    expect($finance->can(Permission::ServersManage->value))->toBeFalse()
        ->and($finance->can(Permission::OrdersManage->value))->toBeFalse()
        ->and($finance->can(Permission::RolesManage->value))->toBeFalse();
});

it('gives support no financial permission at all', function (): void {
    // The rule that matters most in this phase: support handles customers and
    // servers, and can never move or reveal money.
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    foreach (Permission::financial() as $permission) {
        expect($support->can($permission->value))->toBeFalse("support must not hold {$permission->value}");
    }
});

it('gives support its operational permissions', function (): void {
    $support = User::factory()->create();
    $support->assignRole(AdminRole::Support->value);

    expect($support->can(Permission::CustomersManage->value))->toBeTrue()
        ->and($support->can(Permission::OrdersManage->value))->toBeTrue()
        ->and($support->can(Permission::ServersManage->value))->toBeTrue()
        ->and($support->can(Permission::AuditView->value))->toBeTrue();
});

it('gives an ordinary customer no privileged role or permission', function (): void {
    $customer = User::factory()->fromTelegram()->create();

    expect($customer->roles)->toBeEmpty()
        ->and($customer->isPrivileged())->toBeFalse();

    foreach (Permission::cases() as $permission) {
        expect($customer->can($permission->value))->toBeFalse();
    }
});

it('treats every privileged role as able to reach the panel', function (): void {
    foreach (AdminRole::cases() as $role) {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        expect($user->isPrivileged())->toBeTrue("{$role->value} should be privileged");
    }
});

it('answers the privilege question without roles seeded', function (): void {
    // On a database where nothing has been provisioned, the gate must say no
    // rather than raising, because an exception here would be caught somewhere
    // and turned into an allow.
    PermissionModel::query()->delete();
    RoleModel::query()->delete();
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect(User::factory()->create()->isPrivileged())->toBeFalse();
});
