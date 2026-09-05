# Telegram Bot & Email Notifications Setup Guide

This guide explains how to set up the Telegram Bot integration, onboard users to receive instant notifications on Telegram, configure outbound SMTP email notifications, and troubleshoot issues.

---

## Table of Contents
1. [Overview & Architecture](#overview--architecture)
2. [Step 1: Create a Telegram Bot via @BotFather](#step-1-create-a-telegram-bot-via-botfather)
3. [Step 2: Configure Bot in Application Settings](#step-2-configure-bot-in-application-settings)
4. [Step 3: Setting Up Webhook vs Local Polling](#step-3-setting-up-webhook-vs-local-polling)
   - [Option A: Local Development (Polling Command)](#option-a-local-development-polling-command)
   - [Option B: Production Environment (Webhook)](#option-b-production-environment-webhook)
5. [Step 4: User Telegram Onboarding Flow](#step-4-user-telegram-onboarding-flow)
   - [Method 1: One-Click Bot Deep-Link (Recommended)](#method-1-one-click-bot-deep-link-recommended)
   - [Method 2: Manual Chat ID Entry](#method-2-manual-chat-id-entry)
6. [Step 5: Dynamic SMTP Email Setup](#step-5-dynamic-smtp-email-setup)
7. [Step 6: Notification Channels & Event Preferences](#step-6-notification-channels--event-preferences)
8. [Domain Notification Services Reference (Developers)](#domain-notification-services-reference-developers)
9. [Troubleshooting & FAQ](#troubleshooting--faq)

---

## 1. Overview & Architecture

The application provides a modular, contract-driven notification engine:
- **Core Interface**: `App\Services\Notifications\Contracts\NotificationServiceInterface`
- **Channel Drivers**:
  - `InAppNotificationChannel` (Database alerts stored in `notifications` table)
  - `EmailNotificationChannel` (Outbound mail using dynamic SMTP or system `.env` fallback)
  - `TelegramNotificationChannel` (Instant mobile/desktop alerts via Telegram Bot API)
- **Domain Services**:
  - `MilkCollectionNotificationService` (Milk arrivals, point rejections, batch discrepancies, delivery logs)
  - `ApprovalNotificationService` (Approval queues, approvals/rejections, overdue approval reminders)
  - `HrNotificationService` (Leave requests, leave approvals/rejections, payroll generation & disbursement)
- **Granular Toggles**: Each user can toggle In-App, Email, and Telegram per notification event in **Notifications &gt; Preferences**.

---

## 2. Step 1: Create a Telegram Bot via @BotFather

1. Open Telegram (Desktop or Mobile app) and search for `@BotFather` (or open [https://t.me/botfather](https://t.me/botfather)).
2. Start the chat by sending `/start`.
3. Create a new bot by sending:
   ```text
   /newbot
   ```
4. Choose a friendly name for your bot (e.g. `Gondal Dairy Alerts`).
5. Choose a unique username ending in `bot` (e.g. `gondal_dairy_bot` or `GondalAlertsBot`).
6. BotFather will provide an **HTTP API Token**, which looks like:
   ```text
   7123456789:AAFlKj90_ABC1234567890defGhIjKlMnOp
   ```
7. *(Optional)* Set a description or profile photo using BotFather commands `/setdescription` and `/setuserpic`.

---

## 3. Step 2: Configure Bot in Application Settings

1. Log in to the application as an Administrator.
2. Navigate to **Admin &gt; Settings** (`/admin/settings`).
3. Click the **Email & Telegram** tab.
4. Scroll down to the **Telegram Bot Integration** card:
   - **Enable Telegram Notifications**: Check this box.
   - **Telegram Bot Token**: Paste the token received from BotFather.
   - **Bot Username**: Enter the bot's username without the `@` symbol (e.g. `gondal_dairy_bot`).
5. Click **Save Notification Settings**.
6. Click the **Test Bot Connection** button. If the token is valid, you will see a success message showing the bot's name and username.

---

## 4. Step 3: Setting Up Webhook vs Local Polling

Telegram needs to send messages (such as `/start <token>`) back to your application so users can be automatically onboarded.

### Option A: Local Development (Polling Command)
If you are running on `http://localhost/devgondal` without a public HTTPS domain:
1. Open a terminal in the project directory:
   ```bash
   php artisan telegram:poll
   ```
2. Keep this command running in the background. It will continuously poll Telegram for updates and link users automatically as they click `/start`.

### Option B: Production Environment (Webhook)
If your application is hosted on a public domain with an SSL certificate (`https://yourdomain.com`):
1. In **Admin &gt; Settings &gt; Email & Telegram**, make sure your Bot Token is saved.
2. Run the webhook registration command or make an HTTP request to Telegram:
   ```bash
   php artisan tinker --execute="app(App\Services\Notifications\Telegram\TelegramService::class)->setWebhook(url('/api/telegram/webhook'));"
   ```
3. Telegram will now deliver incoming messages directly to `POST /api/telegram/webhook`.

---

## 5. Step 4: User Telegram Onboarding Flow

Every registered user can link their personal Telegram account in seconds.

### Method 1: One-Click Bot Deep-Link (Recommended)
1. Go to **Notifications** from the top user menu (`/notifications`).
2. At the top of the page, the **Telegram Notifications** status card will display:
   - *"Your Telegram is not connected yet."*
3. Click the green button: **Connect with Telegram**.
4. Telegram opens automatically with a start link containing your unique secure token (e.g. `https://t.me/gondal_dairy_bot?start=tok_abc123...`).
5. Click **START** in Telegram.
6. The bot verifies the token and replies:
   > *🎉 Account linked successfully! Welcome, John Doe. You will now receive notifications here.*
7. Refresh your Notifications page: the card will show **Telegram Connected**, and a **Send Test Alert** button will appear.

### Method 2: Manual Chat ID Entry
If a user cannot use the deep-link:
1. Open Telegram and search for `@userinfobot` or send `/start` to your bot.
2. The bot or `@userinfobot` will display the user's numeric **Chat ID** (e.g. `123456789`).
3. On `/notifications`, click **Enter Chat ID manually**.
4. Paste the Chat ID and click **Save & Connect**.

---

## 6. Step 5: Dynamic SMTP Email Setup

You can configure outbound mail directly within the application without modifying server `.env` files.

1. Navigate to **Admin &gt; Settings &gt; Email & Telegram**.
2. Under **Outbound SMTP Configuration**:
   - **Mail Driver / Mailer**: `smtp`
   - **SMTP Host**: e.g., `smtp.mailgun.org`, `smtp.gmail.com`, or `sandbox.smtp.mailtrap.io`
   - **SMTP Port**: `587` (TLS) or `465` (SSL)
   - **Encryption**: `tls` or `ssl`
   - **SMTP Username**: your mail server username
   - **SMTP Password**: your mail server password / app password
   - **From Email Address**: `notifications@yourdomain.com`
   - **From Name**: `Gondal Dairy Farm`
3. Click **Save Notification Settings**.
4. Click **Send Test Email**, enter your email address, and verify receipt.

---

## 7. Step 6: Notification Channels & Event Preferences

Users have full control over what alerts they receive and on which channels:
1. Go to **Notifications** (`/notifications`).
2. Scroll to **Notification Preferences**.
3. You will see three toggle columns for every system event:
   - **In-App** (Notification bell icon and dropdown in the top navigation bar)
   - **Email** (HTML message delivered via SMTP)
   - **Telegram** (Direct instant message with emojis and action links)
4. Check or uncheck preferred channels and click **Save Preferences**.

---

## 8. Domain Notification Services Reference (Developers)

When developing new features, inject the domain notification interfaces:

### Milk Collection Notifications
```php
use App\Services\Notifications\Contracts\MilkCollectionNotificationServiceInterface;

public function recordDelivery(
    MilkCollectionNotificationServiceInterface $milkNotifier,
    MilkBatch $batch
) {
    // ...
    $milkNotifier->notifyDeliveryRecorded($batch, $supervisorUsers);
}
```

Available methods:
- `notifyConsignmentArrival($route, $transporter, $volume, $recipientUsers)`
- `notifyBatchDiscrepancy($batch, $expectedVolume, $actualVolume, $recipientUsers)`
- `notifyCollectionPointRejection($point, $reason, $recipientUsers)`
- `notifyDeliveryRecorded($batch, $recipientUsers)`

### Approval Notifications
```php
use App\Services\Notifications\Contracts\ApprovalNotificationServiceInterface;

$approvalNotifier->notifyApprovalQueued($requisition, $approverUsers);
$approvalNotifier->notifyRequisitionDecided($requisition, 'approved', $requestor);
$approvalNotifier->notifyOverdueApproval($requisition, $approverUsers, 48);
```

### HR Notifications
```php
use App\Services\Notifications\Contracts\HrNotificationServiceInterface;

$hrNotifier->notifyLeaveRequested($leaveRequest, $managers);
$hrNotifier->notifyLeaveDecided($leaveRequest, 'approved', $employeeUser);
$hrNotifier->notifyPayrollGenerated($payrollRun, $financeStaff);
$hrNotifier->notifyPayrollDisbursed($payrollRun, $disbursedCount, $totalAmount, $management);
```

---

## 9. Troubleshooting & FAQ

### 1. Telegram test alert says "User has not connected their Telegram chat ID"
Make sure you followed the onboarding link (`/start <token>`) or entered your Chat ID manually in `/notifications`.

### 2. Deep-link says "Bot username is not configured"
Go to `/admin/settings` &gt; **Email & Telegram** and make sure **Bot Username** is filled in (e.g. `gondal_dairy_bot`) and saved.

### 3. Telegram `/start` command does nothing in local development
Ensure the polling worker is running:
```bash
php artisan telegram:poll
```
In production, make sure the webhook is registered with HTTPS:
```bash
php artisan tinker --execute="app(App\Services\Notifications\Telegram\TelegramService::class)->setWebhook(url('/api/telegram/webhook'));"
```

### 4. Emails fail with SSL/Authentication error
- Verify host, port (`587` for TLS, `465` for SSL), and credentials in `/admin/settings`.
- For Gmail, generate an **App Password** from Google Account &gt; Security &gt; 2-Step Verification &gt; App Passwords.
