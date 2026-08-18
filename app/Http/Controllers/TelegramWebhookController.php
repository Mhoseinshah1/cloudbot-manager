<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $expected = (string) config('telegram.webhook_secret', '');

        if ($expected === '') {
            Log::error('Telegram webhook secret is not configured; rejecting request.');

            return response()->json(['error' => 'webhook_not_configured'], 500);
        }

        if (! hash_equals($expected, $secret)) {
            Log::warning('Telegram webhook: invalid secret token');

            return response()->json(['error' => 'unauthorized'], 403);
        }

        $update = $request->all();

        if (count($update) === 0) {
            return response()->json(['ok' => true]);
        }

        $updateId = isset($update['update_id']) ? (string) $update['update_id'] : null;
        $idempotencyKey = $updateId !== null ? 'tg-update:'.$updateId : null;

        if ($idempotencyKey !== null && ! Cache::add($idempotencyKey, true, now()->addDay())) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            ProcessTelegramUpdateJob::dispatch($update);
        } catch (\Throwable $e) {
            if ($idempotencyKey !== null) {
                Cache::forget($idempotencyKey);
            }

            Log::error('Telegram update dispatch failed', [
                'error' => $e->getMessage(),
                'update_id' => $updateId,
            ]);

            throw $e;
        }

        return response()->json(['ok' => true]);
    }
}
