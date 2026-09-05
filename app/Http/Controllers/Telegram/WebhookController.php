<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Telegram\Exceptions\TelegramNotConfigured;
use App\Telegram\TelegramUpdateNormalizer;
use App\Telegram\TelegramUpdateRecorder;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Where Telegram delivers.
 *
 * Intentionally thin, and thin for a reason rather than for tidiness. Telegram
 * gives a webhook a short window and re-delivers anything that does not answer
 * in time — so slow work here does not merely delay a customer, it manufactures
 * duplicate deliveries of the very update being handled slowly.
 *
 * So this validates, records, queues and returns. It never calls Telegram back,
 * never touches a provider, and never runs business logic; all of that happens
 * on the interactive worker, after the 200 has gone out.
 *
 * The row is written before anything is handled. That ordering is the whole
 * deduplication guarantee: an update recorded and then not handled can be
 * retried safely, while one handled and then recorded can be handled twice.
 */
final class WebhookController
{
    public function __construct(
        private readonly Config $config,
        private readonly TelegramUpdateNormalizer $normalizer,
        private readonly TelegramUpdateRecorder $recorder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->assertFromTelegram($request);

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $normalized = $this->normalizer->normalize($payload);

        if ($normalized === null) {
            // No `update_id`, so there is nothing to deduplicate on and no safe
            // way to handle it even once. Answered 200 so Telegram does not
            // retry something that can never be accepted.
            return new JsonResponse(['ok' => true], 200);
        }

        $recorded = $this->recorder->record($normalized);
        $update = $recorded['update'];

        // Queued for a new update, and also for one recorded earlier that never
        // finished. That second case is the important one: if a previous
        // request committed the row and then failed to enqueue, Telegram's
        // retry is the only thing left that can repair it — and a duplicate
        // that simply returned 200 would strand the update forever.
        //
        // Dispatching twice is harmless: the job is idempotent and the row's
        // status is the guard.
        if ($update->isPending()) {
            $this->enqueue($update);
        }

        return new JsonResponse(['ok' => true], 200);
    }

    /**
     * Prove this came from Telegram.
     *
     * The secret is one Telegram echoes back in a header on every delivery,
     * having been given it at `setWebhook`. Compared in constant time, because
     * a timing-variable comparison against a secret is a way to learn it a byte
     * at a time.
     *
     * A server with no configured secret fails closed. The alternative —
     * accepting anything when unconfigured — means a misdeployment quietly
     * turns the endpoint into one anybody on the internet can post to.
     */
    private function assertFromTelegram(Request $request): void
    {
        $expected = $this->config->get('telegram.webhook_secret');

        if (! is_string($expected) || trim($expected) === '') {
            throw TelegramNotConfigured::missingWebhookSecret();
        }

        $presented = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            // Nothing has been recorded and nothing queued at this point: an
            // unauthenticated request must not be able to fill the table or the
            // queue.
            throw new AccessDeniedHttpException('This request did not come from Telegram.');
        }
    }

    /**
     * Hand the work to the interactive worker.
     *
     * Dispatched, not run. Laravel queues on the destruction of the pending
     * dispatch, so it is released explicitly rather than left to PHP's
     * temporary lifetime — a job that is only queued once the response has been
     * sent is a job that a failed request silently loses.
     */
    private function enqueue(TelegramUpdate $update): void
    {
        $pending = ProcessTelegramUpdateJob::dispatch((int) $update->getKey());
        unset($pending);
    }
}
