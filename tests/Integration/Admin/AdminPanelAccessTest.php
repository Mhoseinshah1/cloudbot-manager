<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Models\User;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    // Enrolment is exercised separately; here the question is who may enter.
    config()->set('cloudbot.admin.require_two_factor', false);
});

it('sends an anonymous visitor to the login page', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active privileged administrator in', function (AdminRole $role): void {
    $admin = User::factory()->create();
    $admin->assignRole($role->value);

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
})->with([
    'owner' => AdminRole::Owner,
    'finance' => AdminRole::Finance,
    'support' => AdminRole::Support,
]);

it('refuses an ordinary customer', function (): void {
    // The overwhelming majority of accounts. Reaching /admin must fail even
    // though they hold a perfectly valid session.
    $customer = User::factory()->fromTelegram()->create();

    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('refuses a user with an email and password but no role', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('refuses a suspended administrator', function (): void {
    // The role is intact; the account standing is not.
    $admin = User::factory()->suspended()->create();
    $admin->assignRole(AdminRole::Owner->value);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('refuses a banned administrator', function (): void {
    $admin = User::factory()->banned()->create();
    $admin->assignRole(AdminRole::Owner->value);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('refuses an administrator whose role was taken away', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(AdminRole::Support->value);

    $this->actingAs($admin)->get('/admin')->assertSuccessful();

    $admin->removeRole(AdminRole::Support->value);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin->fresh())->get('/admin')->assertForbidden();
});

it('serves the panel from the ordinary admin path', function (): void {
    // Not a secret URL. Obscurity is not the control here; the checks above are.
    expect(filament()->getPanel('admin')->getPath())->toBe('admin');
});
