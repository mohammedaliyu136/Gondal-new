@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
  <div class="page-head">
    <div>
      <h1>Notifications</h1>
      <p>{{ number_format($unreadCount) }} unread notification(s)</p>
    </div>
    <div class="page-actions">
      <a href="#modal-preferences" class="btn btn-outline">Notification Preferences</a>
      <form method="POST" action="{{ route('notifications.read-all') }}" style="display:inline">
        @csrf
        <button type="submit" class="btn btn-primary">Mark all read</button>
      </form>
    </div>
  </div>

  {{-- Telegram Onboarding & Status Banner --}}
  <div class="card mb-16" style="border: 1px solid #e2e8f0; background: #ffffff;">
    <div class="card-head" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 18px;">
      <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 1.6rem;">🤖</span>
        <div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <strong style="font-size: 1rem; color: #0f172a;">Telegram Notifications Integration</strong>
            @if ($telegramConnected)
              <span class="badge success plain">CONNECTED</span>
            @else
              <span class="badge warning plain">NOT CONNECTED</span>
            @endif
          </div>
          <div class="text-small text-muted" style="margin-top: 2px;">
            @if ($telegramConnected)
              Linked to Chat ID <code>{{ auth()->user()->telegram_chat_id }}</code>
              @if (auth()->user()->telegram_username)
                (@<span>{{ auth()->user()->telegram_username }}</span>)
              @endif
              &mdash; instant alerts are active.
            @else
              Connect your Telegram account to receive instant push alerts for milk collection, approvals, and HR requests.
            @endif
          </div>
        </div>
      </div>

      <div style="display: flex; gap: 8px; align-items: center;">
        <a href="#modal-telegram-guide" class="btn btn-sm btn-outline">
          📖 Onboarding Guide
        </a>
        @if ($telegramConnected)
          <form method="POST" action="{{ route('notifications.telegram.test') }}" style="margin: 0; display: inline;">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline" style="border-color: #0284c7; color: #0284c7;">
              ⚡ Send Test Alert
            </button>
          </form>
          <form method="POST" action="{{ route('notifications.telegram.disconnect') }}" style="margin: 0; display: inline;" onsubmit="return confirm('Disconnect your Telegram integration?');">
            @csrf
            <button type="submit" class="btn btn-sm btn-ghost danger" style="padding: 4px 8px;">
              Disconnect
            </button>
          </form>
        @else
          @if (!empty($botUsername))
            <a href="#modal-qr-connect" class="btn btn-sm btn-primary font-bold" style="background: #0088cc; border-color: #0088cc;" onclick="startTelegramStatusPolling();">
              <span>📷</span> Scan QR Code to Connect
            </a>
            <a href="{{ $telegramOnboardingUrl }}" target="_blank" class="btn btn-sm btn-outline" style="border-color: #0088cc; color: #0088cc;">
              <span>💻</span> Open Desktop Bot
            </a>
          @endif
          <a href="#modal-connect-telegram" class="btn btn-sm btn-outline">
            Manual Chat ID
          </a>
        @endif
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-head"><div><h3>Inbox</h3></div></div>
      <div class="card-body">
        @forelse ($notifications as $notification)
          <div class="notif {{ $notification->isUnread() ? 'unread' : '' }}">
            <div class="n-ic">{!! $notification->isUnread() ? '&#128276;' : '&#128227;' !!}</div>
            <div class="grow">
              <div class="n-title">{{ $notification->title }}</div>
              @if ($notification->body)<div class="n-sub">{{ $notification->body }}</div>@endif
              <div class="n-time">
                {{ \App\Support\Wat::relative($notification->created_at) }}
                &middot; {{ \Illuminate\Support\Str::headline($notification->type) }}
                @if ($notification->channel_flags)
                  &middot; {{ implode(', ', array_map(fn ($c) => str_replace('_', '-', $c), $notification->channel_flags)) }}
                @endif
              </div>
            </div>
            <div class="flex">
              @if ($notification->action_url)
                <a href="{{ $notification->action_url }}" class="btn btn-ghost btn-sm">Open</a>
              @endif
              @if ($notification->isUnread())
                <form method="POST" action="{{ route('notifications.read', $notification) }}">
                  @csrf
                  <button type="submit" class="btn btn-ghost btn-sm">Mark read</button>
                </form>
              @endif
            </div>
          </div>
        @empty
          @include('partials.empty', ['title' => 'Nothing here yet', 'icon' => '&#128276;'])
        @endforelse
      </div>
      @include('partials.pagination', ['paginator' => $notifications, 'noun' => 'notifications'])
    </div>
  </div>

  {{-- Preferences Modal with In-App, Email, SMS, and Telegram --}}
  <div id="modal-preferences" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog wide">
      <div class="modal-head">
        <div>
          <h3>Notification Preferences</h3>
          <p>Toggle notifications on or off per operational event across channels</p>
        </div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('notifications.preferences') }}">
        @csrf
        <input type="hidden" name="_modal" value="modal-preferences" /> @method('PUT')
        <div class="modal-body">
          @include('partials.modal-errors', ['modal' => 'modal-preferences'])
          <div class="table-wrap" style="max-height: 420px; overflow-y: auto;">
            <table class="table">
              <thead>
                <tr style="position: sticky; top: 0; background: #f8fafc; z-index: 2;">
                  <th>Event Description &amp; Module</th>
                  <th class="perm-check" style="text-align: center; width: 90px;">In-App</th>
                  <th class="perm-check" style="text-align: center; width: 90px;">Email</th>
                  <th class="perm-check" style="text-align: center; width: 90px;">Telegram</th>
                  <th class="perm-check" style="text-align: center; width: 80px;">SMS</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($events as $event)
                  @php($channels = $channelsFor($event))
                  <tr>
                    <td>
                      <div class="font-bold">{{ $event->name }}</div>
                      <div class="cell-sub"><span class="badge plain">{{ $event->module }}</span> <code class="text-small">{{ $event->code }}</code></div>
                    </td>
                    <td class="perm-check" style="text-align: center;">
                      <input type="checkbox" name="preferences[{{ $event->code }}][in_app]" value="1"
                             @checked(in_array('in_app', $channels, true)) />
                    </td>
                    <td class="perm-check" style="text-align: center;">
                      <input type="checkbox" name="preferences[{{ $event->code }}][email]" value="1"
                             @checked(in_array('email', $channels, true)) />
                    </td>
                    <td class="perm-check" style="text-align: center;">
                      <input type="checkbox" name="preferences[{{ $event->code }}][telegram]" value="1"
                             @checked(in_array('telegram', $channels, true)) />
                    </td>
                    <td class="perm-check" style="text-align: center;">
                      <input type="checkbox" name="preferences[{{ $event->code }}][sms]" value="1"
                             @checked(in_array('sms', $channels, true)) />
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary font-bold">Save Preferences</button>
        </div>
      </form>
    </div>
  </div>

  {{-- Manual Chat ID Connect Modal --}}
  <div id="modal-connect-telegram" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog narrow">
      <div class="modal-head">
        <div>
          <h3>Connect Telegram Account</h3>
          <p>Enter your Telegram Chat ID manually</p>
        </div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('notifications.telegram.connect') }}">
        @csrf
        <div class="modal-body">
          <div class="field mb-16">
            <label for="tg_chat_id">Telegram Chat ID <span class="req">*</span></label>
            <input type="text" id="tg_chat_id" name="telegram_chat_id" value="{{ auth()->user()->telegram_chat_id }}" required placeholder="e.g. 123456789" />
            <div class="hint">Send <code>/start</code> to <code>@userinfobot</code> or <code>@raw_data_bot</code> on Telegram to find your numerical Chat ID.</div>
          </div>
          <div class="field">
            <label for="tg_username">Telegram Username (Optional)</label>
            <input type="text" id="tg_username" name="telegram_username" value="{{ auth()->user()->telegram_username }}" placeholder="e.g. johndoe (without @)" />
          </div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary font-bold">Save Telegram Connection</button>
        </div>
      </form>
    </div>
  </div>

  {{-- Telegram Onboarding Guide Modal --}}
  <div id="modal-telegram-guide" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog" style="max-width: 620px;">
      <div class="modal-head">
        <div>
          <h3>🤖 Telegram Onboarding &amp; Notification Guide</h3>
          <p>Get instant operational alerts delivered directly to your Telegram</p>
        </div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <div class="modal-body" style="padding: 20px;">
        <div style="display: flex; flex-direction: column; gap: 16px;">
          
          <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 16px;">
            <div style="font-weight: 700; color: #15803d; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
              <span>⚡</span> Option 1: One-Click Instant Connection (Recommended)
            </div>
            <ol style="margin: 6px 0 12px 20px; padding: 0; line-height: 1.6; color: #166534;">
              <li>Click the blue <strong>"Connect with @<span>{{ $botUsername ?: 'Bot' }}</span>"</strong> button (or tap below).</li>
              <li>Telegram will open with a secure unique activation link.</li>
              <li>Tap <strong>START</strong> in Telegram.</li>
              <li>The bot confirms <em>"Account Linked Successfully!"</em> and begins sending your alerts.</li>
            </ol>
            @if (!empty($botUsername))
              <a href="{{ $telegramOnboardingUrl }}" target="_blank" class="btn btn-sm btn-primary" style="background: #0088cc; border-color: #0088cc; font-weight: 600;">
                📱 Open Telegram Bot Now &rarr;
              </a>
            @endif
          </div>

          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
              <span>🔢</span> Option 2: Manual Chat ID Connection
            </div>
            <ol style="margin: 6px 0 0 20px; padding: 0; line-height: 1.6; color: #334155;">
              <li>Open Telegram and search for <code>@userinfobot</code> or message <code>@<span>{{ $botUsername ?: 'your bot' }}</span></code>.</li>
              <li>Copy your numerical <strong>Id</strong> (e.g. <code>123456789</code>).</li>
              <li>Click <strong>Manual Chat ID</strong> on this page, paste your Chat ID, and save.</li>
            </ol>
          </div>

          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
              <span>⚙️</span> Customize What You Receive
            </div>
            <p style="margin: 4px 0 0 0; color: #475569; line-height: 1.5;">
              Click <strong>Notification Preferences</strong> at the top of the page to choose which operational events (milk arrivals, payment runs, requisitions, leave requests) notify you on In-App, Email, or Telegram.
            </p>
          </div>

          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
              <span>💬</span> Bot Commands in Telegram
            </div>
            <ul style="margin: 0 0 0 20px; padding: 0; color: #475569; line-height: 1.6;">
              <li><code>/start</code> &mdash; Activate bot or link your account</li>
              <li><code>/status</code> &mdash; Check your connection and profile status</li>
              <li><code>/help</code> &mdash; Display onboarding tips and help</li>
            </ul>
          </div>

        </div>
      </div>
      <div class="modal-foot">
        <a href="#" class="btn btn-primary">Got it, close</a>
      </div>
    </div>
  </div>

  {{-- QR Code Onboarding Modal --}}
  <div id="modal-qr-connect" class="modal">
    <a href="#" class="modal-overlay" onclick="stopTelegramStatusPolling();"></a>
    <div class="modal-dialog narrow" style="max-width: 440px; text-align: center;">
      <div class="modal-head" style="text-align: left;">
        <div>
          <h3>📷 Scan QR Code with Phone</h3>
          <p>Link your Telegram mobile app in seconds</p>
        </div>
        <a href="#" class="modal-close" onclick="stopTelegramStatusPolling();">&times;</a>
      </div>
      <div class="modal-body" style="padding: 24px 20px;">

        {{-- Status notification box --}}
        <div id="qr-status-box" style="margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; transition: all 0.3s ease;">
          <div id="qr-status-text" style="font-size: 0.88rem; font-weight: 600; color: #475569; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <span id="qr-spinner" class="qr-spin" style="display: inline-block; width: 14px; height: 14px; border: 2px solid #cbd5e1; border-top-color: #0284c7; border-radius: 50%;"></span>
            <span id="qr-status-label">Waiting for phone scan &amp; tap START...</span>
          </div>
        </div>

        {{-- QR Code Image --}}
        <div id="qr-image-container" style="display: inline-block; padding: 12px; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 14px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; margin-bottom: 16px;">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data={{ urlencode($telegramOnboardingUrl) }}"
               alt="Scan with Telegram Phone Camera"
               width="220" height="220"
               style="display: block; border-radius: 6px;" />
        </div>

        {{-- Simple 3-step guide --}}
        <div style="text-align: left; background: #f1f5f9; border-radius: 8px; padding: 12px 16px; font-size: 0.85rem; color: #334155; line-height: 1.5;">
          <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">Quick 3 Steps:</div>
          <ol style="margin: 0 0 0 16px; padding: 0;">
            <li>Open your phone's <strong>Camera</strong> or <strong>Telegram App</strong>.</li>
            <li>Point your camera at this QR code.</li>
            <li>Tap the link and hit <strong>START</strong> in Telegram.</li>
          </ol>
        </div>

        <div class="text-muted text-small mt-16" style="font-size: 0.82rem;">
          Have Telegram on this computer instead?<br />
          <a href="{{ $telegramOnboardingUrl }}" target="_blank" style="color: #0284c7; font-weight: 600; text-decoration: underline;">
            Open directly in Telegram Desktop &rarr;
          </a>
        </div>
      </div>
      <div class="modal-foot" style="justify-content: center;">
        <a href="#" class="btn btn-ghost" onclick="stopTelegramStatusPolling();">Close</a>
      </div>
    </div>
  </div>

  <style>
    @keyframes spinPulse {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .qr-spin {
      animation: spinPulse 1s linear infinite;
    }
  </style>

  <script>
    let telegramStatusInterval = null;

    function startTelegramStatusPolling() {
      stopTelegramStatusPolling();

      const statusBox = document.getElementById('qr-status-box');
      const statusLabel = document.getElementById('qr-status-label');
      const spinner = document.getElementById('qr-spinner');

      telegramStatusInterval = setInterval(function () {
        fetch('{{ route("notifications.telegram.status") }}', {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(response => response.json())
        .then(data => {
          if (data && data.connected) {
            stopTelegramStatusPolling();

            if (statusBox) {
              statusBox.style.background = '#dcfce7';
              statusBox.style.borderColor = '#86efac';
            }
            if (statusLabel) {
              statusLabel.style.color = '#15803d';
              statusLabel.innerHTML = '🎉 Account linked successfully! Refreshing...';
            }
            if (spinner) {
              spinner.style.display = 'none';
            }

            // Auto-reload after a brief visual confirmation
            setTimeout(function () {
              window.location.href = '{{ route("notifications.index") }}';
            }, 1200);
          }
        })
        .catch(err => console.debug('Status check waiting...', err));
      }, 2500);
    }

    function stopTelegramStatusPolling() {
      if (telegramStatusInterval) {
        clearInterval(telegramStatusInterval);
        telegramStatusInterval = null;
      }
    }

    // Auto-detect opening/closing via URL hash
    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#modal-qr-connect') {
        startTelegramStatusPolling();
      } else {
        stopTelegramStatusPolling();
      }
    });

    // Check on initial page load if hash is present
    if (window.location.hash === '#modal-qr-connect') {
      startTelegramStatusPolling();
    }
  </script>
@endsection
