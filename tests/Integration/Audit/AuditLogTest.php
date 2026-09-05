<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Exceptions\AuditLogIsAppendOnly;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Secrets\SecretScrubber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('records an event with its actor and subject', function (): void {
    $actor = User::factory()->create();
    $subject = User::factory()->fromTelegram()->create();

    $entry = app(AuditRecorder::class)->record(
        AuditEvent::RoleAssigned,
        actor: $actor,
        subject: $subject,
        before: ['roles' => []],
        after: ['roles' => ['support']],
        metadata: ['reason' => 'onboarding'],
    );

    expect($entry->event)->toBe(AuditEvent::RoleAssigned)
        ->and($entry->actor_type)->toBe($actor->getMorphClass())
        ->and((int) $entry->actor_id)->toBe($actor->id)
        ->and((int) $entry->subject_id)->toBe($subject->id)
        ->and($entry->before)->toBe(['roles' => []])
        ->and($entry->after)->toBe(['roles' => ['support']])
        ->and($entry->metadata)->toBe(['reason' => 'onboarding']);
});

it('records a console action with no logged-in actor', function (): void {
    $entry = app(AuditRecorder::class)->recordFromConsole(
        AuditEvent::AdminCreated,
        subject: User::factory()->create(),
        metadata: ['role' => 'owner'],
    );

    expect($entry->actor_type)->toBe('console')
        ->and($entry->actor_id)->toBeNull();
});

it('scrubs credentials out of everything it stores', function (): void {
    // The audit trail is read during incidents by people who should not need
    // to see a password to understand what happened.
    $entry = app(AuditRecorder::class)->record(
        AuditEvent::TwoFactorConfirmed,
        metadata: [
            'email' => 'owner@example.test',
            'password' => 'hunter2-the-real-one',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => ['aaaaa-bbbbb'],
            'provider' => ['api_token' => 'live-token-value'],
        ],
    );

    $stored = json_encode($entry->fresh()->metadata);

    expect($stored)->not->toContain('hunter2-the-real-one')
        ->and($stored)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($stored)->not->toContain('live-token-value')
        ->and($stored)->not->toContain('aaaaa-bbbbb')
        // Non-secret context survives, or the entry would be useless.
        ->and($stored)->toContain('owner@example.test')
        ->and($stored)->toContain(SecretScrubber::REDACTED);
});

it('refuses an update through the model', function (): void {
    $entry = app(AuditRecorder::class)->record(AuditEvent::AdminCreated);

    expect(fn () => $entry->update(['event' => 'tampered']))
        ->toThrow(AuditLogIsAppendOnly::class);
});

it('refuses a delete through the model', function (): void {
    $entry = app(AuditRecorder::class)->record(AuditEvent::AdminCreated);

    expect(fn () => $entry->delete())->toThrow(AuditLogIsAppendOnly::class);
});

it('refuses an update issued straight to postgresql', function (): void {
    // The model guard is bypassed by any query builder call. This is the guard
    // that still holds, and it is the one that matters.
    app(AuditRecorder::class)->record(AuditEvent::AdminCreated);

    expect(fn () => DB::table('audit_logs')->update(['event' => 'tampered']))
        ->toThrow(QueryException::class);
});

it('refuses a delete issued straight to postgresql', function (): void {
    app(AuditRecorder::class)->record(AuditEvent::AdminCreated);

    expect(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class);
});

it('leaves the entry intact after a rejected tamper attempt', function (): void {
    $entry = app(AuditRecorder::class)->record(AuditEvent::AdminCreated);

    // Wrapped in a nested transaction so the rejection rolls back to a
    // savepoint. PostgreSQL aborts a transaction outright after an error, and
    // without this the assertion below could not run.
    try {
        DB::transaction(function () use ($entry): void {
            DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'tampered']);
        });
    } catch (QueryException) {
        // expected
    }

    expect(AuditLog::query()->find($entry->id)->event)->toBe(AuditEvent::AdminCreated);
});

it('has no updated_at, because rows are never updated', function (): void {
    expect(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue();
});

it('keeps entries when the actor they name is removed', function (): void {
    // History must outlive the records it refers to, so there is no foreign key
    // that could take an entry with it.
    $actor = User::factory()->create();
    $entry = app(AuditRecorder::class)->record(AuditEvent::AdminCreated, actor: $actor);

    $actor->delete();

    expect(AuditLog::query()->find($entry->id))->not->toBeNull();
});
