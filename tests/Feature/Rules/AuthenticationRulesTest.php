<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\FailedSignin;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Notifications\NewDeviceSigninNotification;
use App\Notifications\SigninCodeNotification;
use App\Services\Admin\UserAdminService;
use App\Services\Auth\DeviceTrustService;
use App\Support\Wat;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\GondalTestCase;

/** §10 — authentication. */
class AuthenticationRulesTest extends GondalTestCase
{
    private const PASSWORD = 'Correct-Horse-9';

    /**
     * AUTH-1 — "Sign-in is email plus password, then a 6-digit code emailed to the
     * user. Both steps are required unless the device carries a valid trust token."
     */
    public function test_auth1_password_alone_does_not_sign_you_in(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $response = $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        // Step one succeeded, but you are not in yet.
        $response->assertRedirect(route('login.verify'));
        $this->assertGuest();

        Notification::assertSentTo($user, SigninCodeNotification::class);

        $this->assertDatabaseHas('login_codes', [
            'user_id' => $user->id,
            'purpose' => LoginCode::PURPOSE_SIGNIN,
            'consumed_at' => null,
        ]);

        // Step two completes it.
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /** AUTH-3 — the code is stored HASHED, never in plaintext. */
    public function test_auth3_codes_are_stored_hashed(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $code = $this->latestCodeFor($user);
        $record = LoginCode::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(hash('sha256', $code), $record->code_hash);
        // And it is hidden from serialisation, so it cannot leak through an API.
        $this->assertArrayNotHasKey('code_hash', $record->toArray());
    }

    /** AUTH-3 — a code is single-use. */
    public function test_auth3_a_code_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $code = $this->latestCodeFor($user);

        $this->post(route('login.verify.store'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Sign out and try the same code again.
        $this->post(route('auth.signout'));
        $this->assertGuest();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /** AUTH-3 — a code expires after the configured window. */
    public function test_auth3_an_expired_code_is_refused(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $code = $this->latestCodeFor($user);

        LoginCode::query()->where('user_id', $user->id)->update([
            'expires_at' => Wat::now()->subMinute(),
        ]);

        $this->post(route('login.verify.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        $ttl = (int) config('gondal.auth.signin_code_ttl_minutes');
        $this->assertSame(10, $ttl, 'AUTH-3 — a sign-in code lives ten minutes.');
    }

    /** AUTH-3 — five wrong attempts invalidate the code outright. */
    public function test_auth3_five_wrong_attempts_invalidate_the_code(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $code = $this->latestCodeFor($user);

        $limit = (int) config('gondal.auth.code_max_attempts');
        $this->assertSame(5, $limit);

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            $this->post(route('login.verify.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->assertNotNull(
            LoginCode::query()->where('user_id', $user->id)->latest('id')->value('invalidated_at'),
            'The code is invalidated, not merely rejected.',
        );

        // Even the CORRECT code no longer works.
        $this->post(route('login.verify.store'), ['code' => $code])->assertSessionHas('errors');
        $this->assertGuest();
    }

    /** AUTH-3 — issuing a new code invalidates the outstanding one. */
    public function test_auth3_a_new_code_invalidates_the_previous_one(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $first = $this->latestCodeFor($user);

        RateLimiter::clear('resend-code:'.$user->id);
        $this->post(route('login.verify.resend'));

        $second = $this->latestCodeFor($user);

        $this->assertNotSame($first, $second);

        $this->post(route('login.verify.store'), ['code' => $first])->assertSessionHasErrors('code');
        $this->post(route('login.verify.store'), ['code' => $second])->assertRedirect(route('dashboard'));
    }

    /**
     * AUTH-2 — "Trust this device for 30 days issues a device token that skips the
     * code step. Trust is revocable by the user and by an administrator."
     */
    public function test_auth2_a_trusted_device_skips_the_code_step(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        // First sign-in, asking to be remembered.
        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'remember_device' => '1',
        ]);

        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)])
            ->assertRedirect(route('dashboard'));

        $device = Device::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue($device->isTrusted());
        $this->assertSame(
            30,
            (int) Wat::now()->startOfDay()->diffInDays($device->trusted_until->startOfDay()),
            'AUTH-2 — thirty days.',
        );

        // NFR-9 — only the hash is stored.
        $this->assertSame(64, strlen($device->token_hash));
        $this->assertArrayNotHasKey('token_hash', $device->toArray());

        // Sign out, then sign in again carrying the trust cookie: no code needed.
        $token = $this->trustTokenFor($device);
        $this->post(route('auth.signout'));

        $codesBefore = LoginCode::query()->count();

        $this->withCookie(app(DeviceTrustService::class)->cookieName(), $token)
            ->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame($codesBefore, LoginCode::query()->count(), 'No second-factor code was issued.');
    }

    /** AUTH-2 — revoking trust brings the code step back. */
    public function test_auth2_revoked_trust_restores_the_code_step(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $device = Device::query()->create([
            'user_id' => $user->id,
            'label' => 'Revoked device',
            'token_hash' => hash('sha256', 'trusted-token'),
            'trusted_until' => Wat::now()->addDays(30),
        ]);

        $device->revoke();

        $this->withCookie(app(DeviceTrustService::class)->cookieName(), 'trusted-token')
            ->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('login.verify'));

        $this->assertGuest();
        Notification::assertSentTo($user, SigninCodeNotification::class);
    }

    /**
     * AUTH-6 — "Failed sign-ins are logged and throttled. 5 failures in 15 minutes
     * locks the account for 30 minutes and notifies the user."
     */
    public function test_auth6_five_failures_lock_the_account_and_notify(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.attempt'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $user->refresh();

        $this->assertTrue($user->isLocked());
        // Exact, not approximate. The `+ 1` this assertion used to carry was
        // compensating for the milliseconds that elapsed between stamping
        // locked_until and reading it back, which truncated the diff to 29 — a
        // fudge that quietly also accepted 31. The suite's clock is frozen, so
        // the figure is now the figure.
        $this->assertSame(
            30,
            (int) Wat::now()->diffInMinutes($user->locked_until, false),
            'AUTH-6 — locked for thirty minutes.',
        );

        Notification::assertSentTo($user, AccountLockedNotification::class);

        // Every failure is on the record, and in the audit log.
        $this->assertSame(5, FailedSignin::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            5,
            AuditEntry::query()->where('event_type', AuditEntry::EVENT_FAILED_SIGNIN)->count(),
        );

        // Even the CORRECT password is refused while locked.
        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * AUTH-6 — a SUCCESSFUL sign-in ends the run, so failures either side of it do
     * not add up to a lockout.
     *
     * Reported from the pilot database on 7 Aug 2026. Musa Ibrahim failed four
     * times while his account genuinely had no password, signed in correctly once
     * the password was set, mistyped it a second later — and was locked out for
     * thirty minutes on a "fifth failure" that spanned a success. Five failures in
     * fifteen minutes is meant to describe somebody guessing; it stopped meaning
     * that the moment the count could see through a correct password.
     *
     * SigninThrottle::clear() looked like it covered this and did not: it cleared
     * `locked_until`, which is the lock, not the count. It now also marks the
     * failures behind it `superseded_at`, so they stay in the log — AUTH-6 requires
     * that — while dropping out of the arithmetic of the next lock.
     *
     * Kept to six posts on `login.attempt`, because the route carries
     * throttle:10,1 and the suite's clock is frozen, so the eleventh request in a
     * test is a 429 with no session errors rather than a refused sign-in. The
     * converse case — five genuinely consecutive failures DO lock — is
     * test_auth6_five_failures_lock_the_account_and_notify, and is not repeated
     * here.
     */
    public function test_auth6_a_successful_signin_stops_earlier_failures_counting_towards_a_lock(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        // Four failures — one short of the limit.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post(route('login.attempt'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->assertFalse($user->fresh()->isLocked());

        /*
         * The real password, and then AUTH-1's code — the sign-in has to actually
         * COMPLETE for this test to mean anything. Stopping at the password would
         * leave `last_signed_in_at` unstamped, which is the very boundary under
         * test, and the assertion below would pass for the wrong reason.
         */
        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('login.verify'));

        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotNull($user->fresh()->last_signed_in_at);

        $this->post(route('auth.signout'));

        // One more typo. Under the old arithmetic this was failure number five and
        // cost the user half an hour.
        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(
            $user->fresh()->isLocked(),
            'A single failure after a successful sign-in must not lock the account.',
        );

        Notification::assertNotSentTo($user, AccountLockedNotification::class);

        // AUTH-6 — but every attempt is still logged. The fix narrows what COUNTS,
        // not what is RECORDED: an administrator reviewing a lockout afterwards
        // still sees all five, with their addresses, IPs and reasons.
        $this->assertSame(5, FailedSignin::query()->where('user_id', $user->id)->count());

        // Four settled by the successful sign-in, one still live.
        $this->assertSame(
            4,
            FailedSignin::query()->where('user_id', $user->id)->whereNotNull('superseded_at')->count(),
        );
        $this->assertSame(
            1,
            FailedSignin::query()->stillCounting()->where('user_id', $user->id)->count(),
        );
    }

    /** AUTH-6 — an unknown address and a wrong password look identical. */
    public function test_auth6_an_unknown_address_is_indistinguishable_from_a_wrong_password(): void
    {
        $user = $this->signInReadyUser();

        /*
         * Both attempts must produce the SAME message, or the form becomes a staff
         * directory: an attacker could tell a real address from a fictional one.
         */
        $expected = 'Those details do not match an active account.';

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'nope'])
            ->assertSessionHasErrors(['email' => $expected]);

        $this->post(route('login.attempt'), ['email' => 'nobody@gondalfulbe.ng', 'password' => 'nope'])
            ->assertSessionHasErrors(['email' => $expected]);

        // Both are logged, with the reason distinguishing them for an administrator.
        $this->assertSame(1, FailedSignin::query()->where('reason', 'bad_password')->count());
        $this->assertSame(1, FailedSignin::query()->where('reason', 'unknown_email')->count());
    }

    /** AUTH-7 — "Sign-in from a new device notifies the user." */
    public function test_auth7_a_new_device_notifies_the_user(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)]);

        $this->assertAuthenticatedAs($user->fresh());
        Notification::assertSentTo($user, NewDeviceSigninNotification::class);
    }

    /**
     * AUTH-4 — "Password reset: email → 6-digit code (15-minute expiry) → new
     * password. Reset revokes all sessions."
     */
    public function test_auth4_reset_flow_and_session_revocation(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        // Two live sessions before the reset.
        $sessions = collect(range(1, 2))->map(fn () => AuthSession::query()->create([
            'user_id' => $user->id,
            'http_session_id' => 'live-'.uniqid(),
            'started_at' => Wat::now()->subHour(),
            'last_seen_at' => Wat::now(),
        ]));

        $this->post(route('password.forgot.store'), ['email' => $user->email])
            ->assertRedirect(route('password.verify'));

        Notification::assertSentTo($user, SigninCodeNotification::class);

        $record = LoginCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', LoginCode::PURPOSE_RESET)
            ->latest('id')
            ->firstOrFail();

        // AUTH-4 — fifteen minutes for a reset, not ten.
        $this->assertSame(15, (int) config('gondal.auth.reset_code_ttl_minutes'));
        $this->assertSame(
            15,
            (int) Wat::now()->diffInMinutes($record->expires_at, false),
        );

        $this->post(route('password.verify.store'), ['code' => $this->latestCodeFor($user, LoginCode::PURPOSE_RESET)])
            ->assertRedirect(route('password.reset.form'));

        $this->post(route('password.reset.store'), [
            'password' => 'Brand-New-Pass-7',
            'password_confirmation' => 'Brand-New-Pass-7',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('Brand-New-Pass-7', $user->refresh()->password_hash));

        foreach ($sessions as $session) {
            $this->assertNotNull($session->refresh()->ended_at, 'A reset revokes ALL sessions.');
            $this->assertSame('password_reset', $session->ended_reason);
        }
    }

    /** AUTH-4 — the forgot form never reveals whether an address exists. */
    public function test_auth4_the_reset_form_does_not_enumerate_accounts(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $known = $this->post(route('password.forgot.store'), ['email' => $user->email]);
        $unknown = $this->post(route('password.forgot.store'), ['email' => 'ghost@gondalfulbe.ng']);

        $known->assertRedirect(route('password.verify'));
        $unknown->assertRedirect(route('password.verify'));

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'The same answer either way.',
        );

        Notification::assertSentTimes(SigninCodeNotification::class, 1);
    }

    /** AUTH-4 — the new password cannot be set without a verified code. */
    public function test_auth4_reset_requires_a_verified_code(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();
        $this->post(route('password.forgot.store'), ['email' => $user->email]);

        // Skipping the verify step.
        $this->post(route('password.reset.store'), [
            'password' => 'Skipping-The-Code-1',
            'password_confirmation' => 'Skipping-The-Code-1',
        ])->assertRedirect(route('password.forgot'));

        $this->assertFalse(Hash::check('Skipping-The-Code-1', $user->refresh()->password_hash));
    }

    /** AUTH-8 — "There is no self-registration." */
    public function test_auth8_there_is_no_registration_route(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all();

        foreach (['register', 'signup', 'sign-up'] as $forbidden) {
            $this->assertNotContains($forbidden, $routes);
        }

        $this->post('/register', ['email' => 'intruder@gondalfulbe.ng'])->assertNotFound();

        // Nor a named route by that name.
        $this->assertFalse(app('router')->has('register'));
    }

    /** AUTH-8 / BR-31 — creating an account needs admin.users.create. */
    public function test_auth8_only_an_administrator_creates_accounts(): void
    {
        $agent = $this->makeUser('Ordinary Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Network);
        $this->actingAs($agent);

        $this->post(route('admin.users.store'), [
            'name' => 'Self Made',
            'email' => 'self.made@gondalfulbe.ng',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'self.made@gondalfulbe.ng']);
    }

    /** §6.1 — signing in and out is on the auditable session register. */
    public function test_the_session_register_records_sign_in_and_sign_out(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)]);

        $session = AuthSession::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertNull($session->ended_at);
        $this->assertNotNull($session->started_at);

        $this->post(route('auth.signout'));

        $this->assertNotNull($session->refresh()->ended_at);
        $this->assertSame('signout', $session->ended_reason);

        $this->assertDatabaseHas('audit_entries', [
            'event_type' => AuditEntry::EVENT_SIGNIN,
            'actor_user_id' => $user->id,
        ]);
    }

    /**
     * BR-32 — an administrator's "sign out everywhere" must actually sign them
     * out.
     *
     * endAllFor() writes `ended_at` and nothing else, and until
     * EnsureAccountIsUsable read that column back, nothing on any request path
     * ever asked. The button reported "1 session(s) ended", the audit entry said
     * the same, and the session it named carried on working — on the web AND on
     * /api, which is the surface that records milk. A revocation control that
     * reports success and revokes nothing is worse than no control: the
     * administrator stops looking.
     */
    public function test_br32_an_administrators_sign_out_everywhere_really_ends_the_session(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)]);

        // The session works on both surfaces before the revocation.
        $this->get(route('profile'))->assertOk();
        $this->getJson('/api/deliveries')->assertOk();

        $admin = $this->makeUser('Revoking Admin');
        $this->assignRole($admin, 'System Administrator');

        $ended = app(UserAdminService::class)->signOutEverywhere($user, $admin);
        $this->assertSame(1, $ended['sessions']);

        // And it works on neither afterwards.
        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->getJson('/api/deliveries')->assertStatus(401);

        $this->assertGuest();
        $this->assertSame(
            'admin_revoked',
            AuthSession::query()->where('user_id', $user->id)->latest('id')->value('ended_reason'),
        );
    }

    /**
     * BR-33 — "revokes all OTHER sessions", and only the others.
     *
     * The mirror of the test above. Erring towards revoking one too many is the
     * cheaper mistake (SessionRegistry says so), but signing the caller out of
     * their own browser every time they press "sign out other sessions" would
     * make the button unusable and teach people to avoid it.
     */
    public function test_br33_signing_out_other_sessions_spares_the_one_doing_it(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)]);

        $mine = AuthSession::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $elsewhere = AuthSession::query()->create([
            'user_id' => $user->id,
            'http_session_id' => 'a-browser-somewhere-else',
            'started_at' => Wat::now()->subHours(3),
            'last_seen_at' => Wat::now()->subMinutes(2),
        ]);

        $this->post(route('profile.sessions.revoke'))->assertRedirect();

        $this->assertNotNull($elsewhere->refresh()->ended_at, 'The other session is ended.');
        $this->assertNull($mine->refresh()->ended_at, 'This one is not.');

        // Still signed in, still working.
        $this->get(route('profile'))->assertOk();
    }

    /**
     * BR-32 — a deactivated user's BROWSER session is torn down whatever headers
     * the request happens to carry.
     *
     * The middleware used to decide "this is a phone" from the caller's own
     * Authorization header. Any browser request that carried one took the API
     * branch: raw JSON instead of BR-32's sentence on the sign-in screen, and the
     * three lines that dismantle the session skipped entirely, leaving a live
     * http_sessions row for a deactivated account. The surface is now read from
     * the guard that authenticated the request, which the client cannot choose.
     */
    public function test_br32_a_deactivated_users_session_is_torn_down_even_with_a_bearer_header(): void
    {
        Notification::fake();

        $user = $this->signInReadyUser();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('login.verify.store'), ['code' => $this->latestCodeFor($user)]);
        $this->get(route('profile'))->assertOk();

        $user->forceFill(['status' => 'deactivated', 'deactivated_at' => Wat::now()])->save();

        // The test client keeps one application alive across requests, so the
        // guard is still holding the user it resolved a moment ago. Production
        // gets a fresh process; this is the equivalent.
        $this->app['auth']->forgetGuards();

        $this->withToken('1|whatever-a-client-chose-to-send')
            ->get(route('profile'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */

    private function signInReadyUser(): User
    {
        $user = $this->makeUser('Sign In User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);

        return $user->fresh();
    }

    /**
     * The plaintext code is only ever in the notification, so the test reads it
     * the same way the user would — by brute-forcing against the stored hash,
     * which is exactly what NFR-9 makes impossible for an attacker at scale but
     * trivial for a test that owns the machine.
     */
    private function latestCodeFor(User $user, string $purpose = LoginCode::PURPOSE_SIGNIN): string
    {
        $hash = LoginCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->value('code_hash');

        $length = (int) config('gondal.auth.code_length', 6);

        for ($candidate = 0; $candidate < 10 ** $length; $candidate++) {
            $code = str_pad((string) $candidate, $length, '0', STR_PAD_LEFT);

            if (hash('sha256', $code) === $hash) {
                return $code;
            }
        }

        $this->fail('No code matched the stored hash.');
    }

    /** Reads the trust token out of the queued cookie. */
    private function trustTokenFor(Device $device): string
    {
        foreach (Cookie::getQueuedCookies() as $cookie) {
            if ($cookie->getName() === app(DeviceTrustService::class)->cookieName()
                && hash('sha256', $cookie->getValue()) === $device->token_hash) {
                return $cookie->getValue();
            }
        }

        $this->fail('The device trust cookie was not queued.');
    }

    /**
     * NFR-8 — a throttled sign-in says what happened and when to try again.
     *
     * The limit itself was right; the page was not. Laravel's fallback is a bare
     * white screen reading "429 Too Many Requests" — no branding, no reason, no
     * indication of whether the wait is a minute or permanent. Somebody who
     * mistypes a password a few times has no way to tell the difference between
     * being paused and being locked out.
     */
    public function test_nfr8_a_throttled_sign_in_explains_itself(): void
    {
        $user = $this->makeUser('Throttled Person', ['email' => 'throttled@gondalfulbe.ng']);

        $response = null;

        // The sign-in limiter is 5 attempts; go past it.
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $response = $this->post(route('login.attempt'), [
                'email' => $user->email,
                'password' => 'definitely-not-the-password',
            ]);

            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(429, $response->getStatusCode(), 'The limiter must fire (NFR-8).');

        $body = $response->getContent();

        $this->assertStringContainsString('Too many attempts', $body,
            'The page says what happened in plain language.');
        $this->assertStringContainsString('Try again in', $body,
            'It says WHEN they may try again — the difference between paused and locked out.');
        $this->assertStringContainsString('Nothing is wrong with your account', $body,
            'It reassures, because the fear is that the account is gone.');
        $this->assertStringNotContainsString('429 Too Many Requests', $body,
            'The bare framework page must not be what an operator sees.');
    }
}
