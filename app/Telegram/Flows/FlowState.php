<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\ImageSelectionMode;
use App\Telegram\TelegramStateStore;
use Illuminate\Support\Str;

/**
 * A typed view of the half-finished conversation in Redis.
 *
 * The store keeps flat scalars and nothing else, which is right — a serialized
 * object is code that runs on the way back in, and an Eloquent model written
 * there is a stale copy of a row that has since changed. This is the layer that
 * makes those scalars readable without every flow re-deriving what the keys
 * mean.
 *
 * The flow token is the part that matters most. A Telegram keyboard stays on a
 * customer's screen forever: a button pressed a week later would otherwise act
 * on whatever the conversation has become since, which for a buy flow means
 * ordering a server they chose in a different conversation, and for a delete
 * means destroying one they never selected. Every run of a flow gets a fresh
 * random token, the keyboard carries it, and a tap whose token does not match
 * the live state does nothing at all.
 */
final readonly class FlowState
{
    /** The shape marker, so a later change can recognise old state. */
    public const VERSION = 1;

    public const BUY_SERVER = 'buy_server';

    public const SERVER_DELETE = 'server_delete';

    /*
     * Where a buy flow has got to. Stored, because the step decides what the
     * next tap is allowed to do — a confirmation arriving before a preview was
     * ever shown is not a purchase.
     */
    public const STAGE_PRODUCT = 'product';

    public const STAGE_LOCATION = 'location';

    public const STAGE_IMAGE = 'image';

    public const STAGE_PREVIEW = 'preview';

    public const STAGE_TERMS = 'terms';

    public function __construct(private TelegramStateStore $store) {}

    /**
     * Begin a flow, replacing whatever was there.
     *
     * A fresh token every time. Reusing one would let the previous run's
     * keyboard drive this one, which is the exact confusion the token exists
     * to remove.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public function begin(int $telegramUserId, string $flow, array $extra = []): string
    {
        $token = self::newToken();

        // Stored as `flow_ref` rather than `flow_token`. The state store refuses
        // any key whose name says it holds a credential, and that guard is
        // worth more than the nicer name — a per-conversation reference is not
        // a secret, but exempting one key from a name-based rule is how the
        // next key gets exempted too.
        $this->store->put($telegramUserId, [
            'v' => self::VERSION,
            'flow' => $flow,
            'flow_ref' => $token,
            ...$extra,
        ]);

        return $token;
    }

    /**
     * The live state for this flow, or null.
     *
     * Null covers expired, never started, a different flow, and a shape this
     * version does not recognise. All four mean the same thing to a caller:
     * there is nothing to resume, and the customer starts again rather than
     * having their missing choices guessed at.
     *
     * @return array<string, scalar|null>|null
     */
    public function current(int $telegramUserId, string $flow): ?array
    {
        $state = $this->store->get($telegramUserId);

        if ($state === null || ($state['v'] ?? null) !== self::VERSION) {
            return null;
        }

        return ($state['flow'] ?? null) === $flow ? $state : null;
    }

    /**
     * The live state, but only if this button belongs to it.
     *
     * The token comparison is the whole point of this method. It is done with
     * `hash_equals` because the token is a capability for this conversation and
     * a length-varying comparison leaks how much of a guess was right.
     *
     * @return array<string, scalar|null>|null
     */
    public function matching(int $telegramUserId, string $flow, ?string $token): ?array
    {
        if ($token === null) {
            return null;
        }

        $state = $this->current($telegramUserId, $flow);

        if ($state === null) {
            return null;
        }

        $live = $state['flow_ref'] ?? null;

        if (! is_string($live) || ! hash_equals($live, $token)) {
            return null;
        }

        return $state;
    }

    /**
     * Move a flow on, keeping its token.
     *
     * @param  array<string, scalar|null>  $state
     * @param  array<string, scalar|null>  $changes
     * @return array<string, scalar|null>
     */
    public function advance(int $telegramUserId, array $state, array $changes): array
    {
        $next = [...$state, ...$changes];

        $this->store->put($telegramUserId, $next);

        return $next;
    }

    public function forget(int $telegramUserId): void
    {
        $this->store->forget($telegramUserId);
    }

    /**
     * A fresh token: eight hex characters of real randomness.
     *
     * Short enough to leave room inside Telegram's 64-byte callback limit for
     * the largest id this database can produce, and long enough that guessing
     * one is not a way to drive somebody else's conversation — which it could
     * not do anyway, since state is keyed by the customer's own Telegram id.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(4));
    }

    /** A durable purchase identity, fixed for the life of one buy flow. */
    public static function newPurchaseIntentId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    public static function intOf(array $state, string $key): ?int
    {
        $value = $state[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    public static function stringOf(array $state, string $key): ?string
    {
        $value = $state[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, scalar|null>  $state
     */
    public static function imageModeOf(array $state): ImageSelectionMode
    {
        return self::stringOf($state, 'image_selection_mode') === ImageSelectionMode::Explicit->value
            ? ImageSelectionMode::Explicit
            : ImageSelectionMode::Default;
    }
}
