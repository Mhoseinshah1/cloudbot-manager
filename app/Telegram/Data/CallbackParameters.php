<?php

declare(strict_types=1);

namespace App\Telegram\Data;

/**
 * The safe hints a pressed button carried.
 *
 * Every field is a bounded scalar this system parsed out of a closed grammar,
 * and none of it is authority. A number here is a request to look at a record,
 * never a claim to own one: the lookup is scoped by customer server-side, so a
 * button naming somebody else's server finds nothing rather than finding
 * theirs.
 *
 * The flow token is what makes an old keyboard harmless. Telegram messages stay
 * on a customer's screen forever, and a button pressed a week later would
 * otherwise still act on whatever the conversation has become. A token that
 * does not match the live flow means the tap is acknowledged and does nothing.
 */
final readonly class CallbackParameters
{
    /** Bounded so a token cannot be used to smuggle a payload. */
    public const MAX_TOKEN = 16;

    /** Nothing this system paginates has a millionth page. */
    public const MAX_PAGE = 10_000;

    public function __construct(
        /** A record id the customer is asking about. Never proof of ownership. */
        public ?int $id = null,
        public ?int $page = null,
        /** Which run of a flow this button belonged to. */
        public ?string $flowToken = null,
        /** Whether an image choice meant "whatever the location's default is". */
        public bool $wantsDefault = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function hasFlowToken(): bool
    {
        return $this->flowToken !== null;
    }

    /**
     * Flattened for the update row.
     *
     * Scalars with distinct names rather than a nested object, because the
     * state and metadata this system stores are deliberately flat — a blob is
     * how arbitrary content gets in.
     *
     * @return array<string, scalar|null>
     */
    public function toMetadata(): array
    {
        $metadata = [];

        if ($this->id !== null) {
            $metadata['param_id'] = $this->id;
        }

        if ($this->page !== null) {
            $metadata['param_page'] = $this->page;
        }

        if ($this->flowToken !== null) {
            $metadata['param_flow'] = $this->flowToken;
        }

        if ($this->wantsDefault) {
            $metadata['param_default'] = true;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $id = $metadata['param_id'] ?? null;
        $page = $metadata['param_page'] ?? null;
        $flow = $metadata['param_flow'] ?? null;

        return new self(
            id: is_int($id) ? $id : null,
            page: is_int($page) ? $page : null,
            flowToken: is_string($flow) && $flow !== '' ? $flow : null,
            wantsDefault: ($metadata['param_default'] ?? false) === true,
        );
    }
}
