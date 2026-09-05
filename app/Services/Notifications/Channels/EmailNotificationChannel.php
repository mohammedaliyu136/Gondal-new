<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\GondalEventNotification;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class EmailNotificationChannel implements NotificationChannelInterface
{
    public function getKey(): string
    {
        return 'email';
    }

    public function send(
        User $recipient,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): bool {
        if (empty($recipient->email)) {
            return false;
        }

        $this->applyDynamicSmtpSettings();

        try {
            $recipient->notify(new GondalEventNotification(
                event: $event,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                channels: ['mail'],
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error("EmailNotificationChannel failed for user [{$recipient->id}] {$recipient->email}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Dynamically override mailer configuration from Settings if SMTP settings are provided in Admin Settings.
     */
    public function applyDynamicSmtpSettings(): void
    {
        $smtpHost = Settings::string('mail.smtp_host', '');

        if ($smtpHost !== '') {
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $smtpHost);
            Config::set('mail.mailers.smtp.port', Settings::integer('mail.smtp_port', 587));
            Config::set('mail.mailers.smtp.username', Settings::string('mail.smtp_username', ''));
            Config::set('mail.mailers.smtp.password', Settings::string('mail.smtp_password', ''));

            $encryption = Settings::string('mail.smtp_encryption', 'tls');
            Config::set('mail.mailers.smtp.encryption', ($encryption === 'none' || empty($encryption)) ? null : $encryption);

            if (! app()->isProduction()) {
                Config::set('mail.mailers.smtp.stream', [
                    'ssl' => [
                        'allow_self_signed' => true,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
            }

            $fromAddress = Settings::string('mail.from_address', '');
            if ($fromAddress !== '') {
                Config::set('mail.from.address', $fromAddress);
            }

            $fromName = Settings::string('mail.from_name', '');
            if ($fromName !== '') {
                Config::set('mail.from.name', $fromName);
            }
        }
    }
}
