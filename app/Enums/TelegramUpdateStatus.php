<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far one Telegram update has got.
 *
 * Three values, and deliberately no fourth. There is no `processing` state,
 * because a row that says "in progress" is indistinguishable from one whose
 * worker died — and treating a crashed job as permanently in progress is how
 * an update is silently never handled. An update that is being worked on stays
 * `received`, and the only thing that moves it on is finishing.
 *
 * This vocabulary is about delivery, not business flow. What a customer is
 * doing lives in conversation state, not here.
 */
enum TelegramUpdateStatus: string
{
    /** Recorded, not yet handled. Also where a crashed attempt leaves it. */
    case Received = 'received';

    /** Handled to completion, exactly once. */
    case Processed = 'processed';

    /** Handling raised something. The row is kept, and may be retried. */
    case Failed = 'failed';

    /** Whether this update still has work owing. */
    public function isPending(): bool
    {
        return $this !== self::Processed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
