<?php

use App\Models\User;

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
