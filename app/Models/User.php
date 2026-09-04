<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A customer or an administrator.
 *
 * Both live in this table because they are the same kind of thing to the
 * system: an identity that can own resources. What separates an administrator
 * is holding a privileged role, not a column on this record.
 *
 * @property int $id
 * @property string|null $email
 * @property UserStatus $status
 * @property UserCreatedVia $created_via
 * @property int $wallet_balance_toman
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * Deliberately excludes wallet_balance_toman.
     *
     * The balance is owned by the wallet service that arrives in a later phase,
     * which mutates it under a row lock alongside a ledger entry. Leaving it
     * out means no controller, seeder or admin form can move money by passing
     * an extra key.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'created_via',
        'locale',
        'timezone',
        'phone',
    ];

    /**
     * Defaults that match the database's own.
     *
     * Without these a freshly created model reports a null balance until it is
     * reloaded, and arithmetic on null money is exactly the kind of bug this
     * system cannot afford.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'wallet_balance_toman' => 0,
        'status' => UserStatus::Active->value,
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'created_via' => UserCreatedVia::class,
            'wallet_balance_toman' => 'integer',
            'wallet_locked_at' => 'datetime',
            // Encrypted at rest: a database backup or a leaked dump must not
            // hand over working second factors.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * A user may have more than one Telegram identity.
     *
     * The specification does not require one account per user, so this is not
     * constrained to a single record.
     *
     * @return HasMany<TelegramAccount, $this>
     */
    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    /**
     * This customer's orders.
     *
     * The relation customer-facing code goes through, so that "their orders"
     * is expressed in the query rather than checked after loading everyone's.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The servers this customer owns.
     *
     * The relation exists so that every customer-facing lookup can start from
     * the customer — `$user->servers()->whereKey($id)` cannot return somebody
     * else's machine, whereas a global find with an ownership check afterwards
     * is the same thing only until someone forgets the check.
     *
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * Whether this account holds any privileged role.
     */
    public function isPrivileged(): bool
    {
        // checkPermissionTo, not hasPermissionTo: the latter throws when the
        // permission has never been seeded, and a gate that throws on a fresh
        // database is a gate that gets caught and turned into "allow".
        return $this->checkPermissionTo(Permission::AdminAccess->value);
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Server-side gate for the admin panel.
     *
     * Two conditions, both required: the account must be in good standing, and
     * it must hold a privileged role. A suspended or banned administrator is
     * refused here even though their role is intact.
     *
     * Two-factor enrolment is enforced separately, by middleware, so that an
     * administrator who has not yet enrolled can still reach the page that
     * lets them do it.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActive() && $this->isPrivileged();
    }
}
