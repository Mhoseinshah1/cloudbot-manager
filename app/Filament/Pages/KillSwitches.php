<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\SettingKey;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\User;
use App\Settings\SettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * The two switches somebody reaches for during an incident.
 *
 * Given their own screen, rather than buried among forty settings, because the
 * moment they are needed is the moment nobody wants to go looking. Each shows
 * its current value plainly — including the case where the row is missing or
 * unreadable, which every reader treats as off and which therefore has to be
 * visible rather than displayed as a confident "disabled".
 *
 * Turning sales off stops new purchases and nothing else: existing customers
 * keep operating and renewing their servers, because a commercial pause is not
 * a decision to end live service. Turning provisioning off stops new provider
 * creates; paid orders stay paid and are picked up when it is switched back on.
 */
class KillSwitches extends Page
{
    use AuthorizesWithPermission;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Kill switches';

    protected static string $view = 'filament.pages.kill-switches';

    public static function viewPermission(): Permission
    {
        return Permission::SettingsView;
    }

    public static function canAccess(): bool
    {
        return self::operatorMay(Permission::SettingsView)
            || self::operatorMay(Permission::SettingsManage);
    }

    /**
     * Both switches, as they actually read right now.
     *
     * @return array<int, array{key: SettingKey, label: string, value: ?bool, description: string}>
     */
    public function switches(): array
    {
        $settings = app(SettingsService::class);

        return [
            [
                'key' => SettingKey::SalesEnabled,
                'label' => 'Sales',
                'value' => $settings->boolean(SettingKey::SalesEnabled),
                'description' => 'New purchases. Existing customers keep managing and renewing their servers either way.',
            ],
            [
                'key' => SettingKey::ProvisioningEnabled,
                'label' => 'Provisioning',
                'value' => $settings->boolean(SettingKey::ProvisioningEnabled),
                'description' => 'New provider creates. Paid orders are preserved and resume when this is switched back on.',
            ],
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->toggleAction(SettingKey::SalesEnabled, 'sales'),
            $this->toggleAction(SettingKey::ProvisioningEnabled, 'provisioning'),
        ];
    }

    private function toggleAction(SettingKey $key, string $name): Action
    {
        return Action::make('toggle_'.$name)
            ->label('Change '.$name)
            ->icon('heroicon-o-power')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Change the '.$name.' switch')
            ->modalDescription('Recorded in the audit log with your reason.')
            ->form([
                \Filament\Forms\Components\Toggle::make('enabled')
                    ->label('Enabled')
                    ->default(fn (): bool => app(SettingsService::class)->boolean($key) === true),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->visible(fn (): bool => self::operatorMay(Permission::SettingsManage))
            ->action(function (array $data) use ($key): void {
                $operator = self::operator();

                if (! $operator instanceof User) {
                    return;
                }

                try {
                    // Through SettingsService, which checks the permission and
                    // writes the audit entry with before and after.
                    app(SettingsService::class)->set($key, (bool) $data['enabled'], $operator);

                    Notification::make()->success()
                        ->title($key->value.' set to '.($data['enabled'] ? 'true' : 'false'))->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Refused')->body($exception->getMessage())->send();
                }
            });
    }
}
