<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    /**
     * Handle incoming updates from Telegram Bot Webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();

        if (empty($update)) {
            return response()->json(['ok' => false, 'message' => 'Empty payload'], 400);
        }

        try {
            $user = $this->telegram->processUpdate($update);

            return response()->json([
                'ok' => true,
                'user_linked' => $user ? $user->id : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram webhook handler exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 200); // Telegram recommends 200 to acknowledge receipt even on internal error to prevent retry loops
        }
    }
}
