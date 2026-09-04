<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/*
 * Integration tests run against a real PostgreSQL 16 and Redis 7. RefreshDatabase
 * gives each test a clean schema built by the real migrations.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Integration');
