<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\User;
use App\Settings\SettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * The business controls, rendered from the keys the code actually knows.
 *
 * Built from `SettingKey` rather than from the settings table, so there is no
 * way to type a key nobody reads or to store a value under the wrong type. Each
 * field is rendered from the key's declared type and written through
 * `SettingsService`, which validates the type, refuses an operator without
 * `settings.manage` and records the change with its before and after.
 *
 * Every read in this system is strict about these: a boolean that is not
 * exactly `true` or `false`, or a number that is not a number, reads as absent
 * and the feature fails closed. Editing raw rows would be the easiest way to
 * produce exactly that, which is why this screen exists instead.
 */
class ManageSettings extends Page implements HasForms
{
    use AuthorizesWithPermission;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Settings';

    protected static string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function viewPermission(): Permission
    {
        return Permission::SettingsView;
    }

    public static function managePermission(): ?Permission
    {
        return Permission::SettingsManage;
    }

    public static function canAccess(): bool
    {
        return self::operatorMay(Permission::SettingsView)
            || self::operatorMay(Permission::SettingsManage);
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[self::field($key)] = match ($key->type()) {
                SettingType::Boolean => $settings->boolean($key),
                SettingType::Integer => $settings->integer($key),
                SettingType::Json => $settings->dayThresholds($key),
                default => $settings->string($key),
            };
        }

        // `getForm()` rather than the magic `$this->form` property, so the
        // registered form is resolved through the typed accessor.
        $this->getForm('form')?->fill($values);
    }

    public function form(Form $form): Form
    {
        $fields = [];

        foreach (SettingKey::cases() as $key) {
            $fields[] = self::fieldFor($key);
        }

        return $form
            ->schema($fields)
            ->statePath('data')
            // Read-only for an operator who may see settings but not change
            // them; the save action is hidden from them as well.
            ->disabled(fn (): bool => ! self::operatorMay(Permission::SettingsManage));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->requiresConfirmation()
                ->modalDescription('Each changed value is written through SettingsService and recorded in the audit log.')
                ->visible(fn (): bool => self::operatorMay(Permission::SettingsManage))
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $operator = self::operator();

        if (! $operator instanceof User || ! self::operatorMay(Permission::SettingsManage)) {
            return;
        }

        $form = $this->getForm('form');

        if (! $form instanceof Form) {
            return;
        }

        $state = $form->getState();
        $settings = app(SettingsService::class);
        $saved = 0;

        foreach (SettingKey::cases() as $key) {
            $value = $state[self::field($key)] ?? null;

            if ($value === null || $value === '') {
                // Left blank means "do not set". Writing null would make a
                // configured control unreadable, and every reader treats an
                // unreadable control as off.
                continue;
            }

            try {
                $settings->set($key, self::coerce($key, $value), $operator);
                $saved++;
            } catch (Throwable $exception) {
                Notification::make()->danger()
                    ->title('Refused: '.$key->value)->body($exception->getMessage())->send();

                return;
            }
        }

        Notification::make()->success()->title($saved.' setting(s) saved.')->send();
    }

    /** The typed input for one key. */
    private static function fieldFor(SettingKey $key): TextInput|Toggle|TagsInput
    {
        $name = self::field($key);
        $label = $key->value;

        return match ($key->type()) {
            SettingType::Boolean => Toggle::make($name)->label($label),
            SettingType::Integer => TextInput::make($name)->label($label)->numeric()->integer(),
            SettingType::Json => TagsInput::make($name)
                ->label($label)
                ->helperText('Whole numbers only. Anything else makes the setting unreadable and the feature inert.'),
            default => TextInput::make($name)->label($label)->maxLength(500),
        };
    }

    /**
     * Give the service the exact type the key declares.
     *
     * `SettingsService::set()` refuses a mismatch rather than coercing, because
     * the coercions PHP would apply are the dangerous ones — "false" is a
     * non-empty string and therefore truthy.
     *
     * @return bool|int|string|list<int>
     */
    private static function coerce(SettingKey $key, mixed $value): bool|int|string|array
    {
        return match ($key->type()) {
            SettingType::Boolean => (bool) $value,
            SettingType::Integer => (int) $value,
            SettingType::Json => array_values(array_map(
                static fn (mixed $entry): int => (int) $entry,
                is_array($value) ? $value : [$value],
            )),
            default => (string) $value,
        };
    }

    private static function field(SettingKey $key): string
    {
        return str_replace('.', '__', $key->value);
    }
}
