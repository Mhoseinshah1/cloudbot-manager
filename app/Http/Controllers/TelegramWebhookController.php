<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Validate webhook secret
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $expected = config('telegram.webhook_secret', '');

        if ($expected !== '' && hash_equals($expected, $secret) === false) {
            Log::warning('Telegram webhook: invalid secret token');

            return response()->json(['error' => 'unauthorized'], 403);
        }

        $update = $request->all();

        if (count($update) === 0) {
            return response()->json(['ok' => true]);
        }

        // Process asynchronously for speed — webhook must return quickly
        dispatch(function () use ($update) {
            try {
                app(TelegramUpdateRouter::class)->handle($update);
            } catch (\Throwable $e) {
                Log::error('Telegram update processing failed', [
                    'error' => $e->getMessage(),
                    'update_id' => $update['update_id'] ?? null,
                ]);
            }
        })->onQueue('telegram');

        return response()->json(['ok' => true]);
    }
}
