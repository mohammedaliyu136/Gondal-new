<?php

namespace App\Console\Commands;

use App\Services\Notifications\Telegram\TelegramService;
use Illuminate\Console\Command;

class TelegramPollUpdatesCommand extends Command
{
    protected $signature = 'telegram:poll {--timeout=30 : Long polling timeout in seconds}';

    protected $description = 'Long-poll Telegram Bot API for incoming updates (ideal for local testing and onboarding)';

    public function handle(TelegramService $telegram): int
    {
        $botInfo = $telegram->getMe();

        if (! ($botInfo['success'] ?? false)) {
            $this->error('Failed to connect to Telegram: ' . ($botInfo['message'] ?? 'Unknown error'));
            $this->warn('Please configure telegram.bot_token in Admin Settings or .env');

            return self::FAILURE;
        }

        $bot = $botInfo['bot'];
        $this->info("🤖 Started Telegram polling for @{$bot['username']} ({$bot['first_name']})");
        $this->info('Press Ctrl+C to stop.');

        $offset = 0;
        $timeout = (int) $this->option('timeout');

        while (true) {
            $updates = $telegram->getUpdates($offset, 50, $timeout);

            foreach ($updates as $update) {
                $updateId = $update['update_id'] ?? 0;
                $offset = max($offset, $updateId + 1);

                $user = $telegram->processUpdate($update);

                $msg = $update['message'] ?? ($update['edited_message'] ?? []);
                $from = $msg['from']['username'] ?? ($msg['from']['first_name'] ?? 'Unknown');
                $text = $msg['text'] ?? '';

                if ($user) {
                    $this->info("✅ Linked Telegram user @{$from} to Gondal ERP staff: [{$user->id}] {$user->name}");
                } else {
                    $this->line("📨 Received update from @{$from}: {$text}");
                }
            }

            usleep(500000); // 0.5s pause
        }

        return self::SUCCESS;
    }
}
