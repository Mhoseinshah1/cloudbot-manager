<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\Permission;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\FailedJob;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

/**
 * Work the queue gave up on.
 *
 * The exception is shown as its first line only, and the serialized payload is
 * not shown at all. A failed-job record contains whatever the job was carrying,
 * and while this system deliberately puts only row ids in job payloads, a
 * screen that dumps them wholesale is one refactor away from displaying
 * something it should not.
 *
 * Retry pushes the job back onto its queue through Laravel's own mechanism. It
 * is safe here because the destructive work in this system carries its own
 * durable idempotency — a provisioning token, a server action's reserved
 * attempt, a renewal's unique key — so a job that already had its effect finds
 * that out rather than repeating it. Forgetting a job is owner-level and
 * deletes only the failure record.
 */
class FailedJobs extends Page implements HasTable
{
    use AuthorizesWithPermission;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = 'Failed jobs';

    protected static string $view = 'filament.pages.table-page';

    public static function viewPermission(): Permission
    {
        return Permission::JobsView;
    }

    public static function canAccess(): bool
    {
        return self::operatorMay(Permission::JobsView);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FailedJob::query()->select([
                'id', 'uuid', 'queue', 'exception', 'failed_at',
            ]))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('uuid')->label('UUID')->searchable()->limit(18),
                TextColumn::make('queue')->searchable()->sortable(),
                TextColumn::make('failed_at')->dateTime()->sortable(),
                TextColumn::make('exception')
                    ->label('Exception')
                    // The first line only. The stack trace and the serialized
                    // payload stay out of the browser.
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : mb_substr(strtok($state, "\n") ?: '', 0, 160))
                    ->wrap(),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Retry this job')
                    ->modalDescription('Pushes it back onto its queue. Destructive work is protected by its own durable idempotency.')
                    ->visible(fn (): bool => self::operatorMay(Permission::JobsManage))
                    ->action(function (FailedJob $record): void {
                        $operator = self::operator();

                        if (! $operator instanceof User) {
                            return;
                        }

                        Artisan::call('queue:retry', ['id' => [(string) $record->uuid]]);

                        app(AuditRecorder::class)->record(
                            AuditEvent::FailedJobRetried,
                            actor: $operator,
                            metadata: [
                                'failed_job_uuid' => (string) $record->uuid,
                                'queue' => (string) $record->queue,
                            ],
                        );

                        Notification::make()->success()->title('Job queued for retry.')->send();
                    }),
                Action::make('forget')
                    ->label('Forget')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this failure record')
                    ->modalDescription('Removes the record only. The work is not performed and cannot be retried afterwards.')
                    // Owner-level: throwing away the evidence that something
                    // failed is not a support decision.
                    ->visible(fn (): bool => self::operatorMay(Permission::SettingsManage))
                    ->action(function (FailedJob $record): void {
                        Artisan::call('queue:forget', ['id' => (string) $record->uuid]);

                        Notification::make()->success()->title('Failure record removed.')->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('failed_at', 'desc')
            ->paginated([25, 50]);
    }

}
