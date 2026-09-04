<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/*
 * Integration tests run against a real PostgreSQL 16 and Redis 7. RefreshDatabase
 * gives each test a clean schema built by the real migrations.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Integration');

/*
 * The role and permission map is cached in Redis, which RefreshDatabase cannot
 * roll back. Left alone it would describe rows from a previous test that no
 * longer exist, so it is cleared before each one.
 */
pest()->beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
})->in('Integration');
