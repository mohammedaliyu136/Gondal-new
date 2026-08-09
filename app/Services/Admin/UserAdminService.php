<?php

namespace App\Services\Admin;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Device;
use App\Models\LoginCode;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\EmailAddressChangedNotification;
use App\Notifications\PasswordResetByAdminNotification;
use App\Notifications\TemporaryPasswordSetNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\SessionRegistry;
use App\Services\Notifications\NotificationService;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * §6.1 / §7.6 — user administration.
 *
 * BR-31 — "Administrators never see or set a user's password. Creation and reset
 *   both send a code; the user chooses their own password." Creation writes an
 *   UNUSABLE random hash and sends an activation code, and resetPassword() does
 *   the same to an account that already has one — for ANY user, not only a new
 *   hire who has never signed in, which is all the screen could previously offer.
 *   setPassword() is the owner-approved exception: an administrator may type a
 *   TEMPORARY password the user must replace at their next sign-in. Read its
 *   docblock before touching it; it is the only place in the system where somebody
 *   other than the account holder knows a working credential, and it says plainly
 *   what that costs.
 * BR-32 — deactivation blocks sign-in and revokes sessions but preserves every
 *   attribution. The row is never deleted.
 * ROLE-3 — every new user is given the automatic role immediately, so the user
 *   screen shows it rather than it being invisible magic.
 * SCOPE-1 — an assignment carries its scope, and `communities` carries a list.
 */
class UserAdminService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $apiTokens,
        private readonly NotificationService $notifications,
        private readonly PasswordPolicy $passwords,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'position' => $data['position'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'status' => 'active',
                // TEST-1 — a test account is flagged at creation and excluded from
                // every report, aggregate and payroll thereafter.
                'is_test' => (bool) ($data['is_test'] ?? false),
                /*
                 * AUTH-1's second factor is OFF unless asked for — see
                 * 2026_01_03_001400_stop_requiring_a_second_factor_by_default.
                 * An emailed code is not a second factor for somebody in
                 * Gengle, it is a locked door. Still per-user, still settable
                 * here, still enforced when it is on.
                 */
                'two_factor_enabled' => (bool) ($data['two_factor_enabled'] ?? false),
                'created_by_user_id' => $actor->getKey(),
            ]);

            /*
             * BR-31 — "Administrators never see or set a user's password."
             *
             * The account starts with a random hash NOBODY knows — not the
             * administrator, not the new user — and password_changed_at null,
             * which AUTH-5 treats as expired. The only way in is the activation
             * code sent below, which the user redeems to choose their own.
             */
            $user->password_hash = Hash::make(Str::random(64));
            $user->password_changed_at = null;
            $user->save();

            // ROLE-3
            $automatic = Role::query()->where('is_automatic', true)->where('status', Role::STATUS_ACTIVE)->first();

            if ($automatic !== null) {
                $this->assignRole($user, $automatic, ScopeType::Own, null, [], $actor);
            }

            $this->audit->created(
                $user,
                sprintf('User account created for %s (%s)%s', $user->name, $user->email, $user->is_test ? ' — TEST ACCOUNT' : ''),
                'Administration',
                ['rule' => 'BR-31', 'is_test' => $user->is_test, 'activation' => 'code sent, password never set by admin'],
                $actor,
            );

            $this->sendActivation($user, $actor);

            return $user;
        });
    }

    /**
     * AUTH-2 — "device trust is revocable by the user AND by an administrator".
     *
     * Only the user's half existed. When a collection agent reports a stolen
     * phone that is trusted for 30 days and therefore skips the sign-in code, the
     * administrator could see the device listed and do nothing about it — the
     * only lever that cleared trust was full deactivation, which also stops the
     * person working and, on reactivation, emails them a welcome message.
     */
    public function revokeDevice(Device $device, User $actor): void
    {
        $device->revoke($actor);

        $this->audit->edited(
            $device->user,
            sprintf(
                'Trusted device "%s" revoked by an administrator',
                $device->label ?? 'Unnamed device',
            ),
            'Administration',
            ['device_trusted' => true],
            ['device_trusted' => false, 'device_id' => $device->getKey(), 'rule' => 'AUTH-2'],
            $actor,
        );
    }

    /**
     * Ends every open session for a user without touching their account status.
     *
     * "Everywhere" has to mean everywhere. The button existed to answer "this
     * person's laptop was stolen" and it ended browser sessions only, leaving any
     * mobile bearer token this user held alive for the rest of its 30 days — on
     * the one surface (POST /sync/batch) that can record deliveries, sales and
     * farmer registrations. An administrator clicking this has decided the
     * account's credentials are not trustworthy; that judgement cannot stop at
     * the browser.
     *
     * @return array{sessions: int, tokens: int}
     */
    public function signOutEverywhere(User $user, User $actor): array
    {
        $ended = $this->sessions->endAllFor($user, 'admin_revoked');
        $tokens = $this->apiTokens->revokeAllFor($user, 'admin_revoked');

        $this->audit->edited(
            $user,
            sprintf('%d open session(s) and %d mobile token(s) ended by an administrator', $ended, $tokens),
            'Administration',
            [],
            ['sessions_ended' => $ended, 'tokens_revoked' => $tokens, 'rule' => 'BR-32'],
            $actor,
        );

        return ['sessions' => $ended, 'tokens' => $tokens];
    }

    /**
     * AUTH-6's counterpart: clear a lockout before it expires.
     *
     * The lock email tells the user to contact IT; this is what IT does when
     * contacted. Audited, because an early unlock is a human overriding a
     * security control and the log should say who and when.
     */
    public function unlock(User $user, User $actor): User
    {
        $user->forceFill(['locked_until' => null])->save();

        $this->audit->edited(
            $user,
            $user->name.' unlocked by an administrator before the lockout expired',
            'Administration',
            ['locked' => true],
            ['locked' => false, 'rule' => 'AUTH-6'],
            $actor,
        );

        return $user;
    }

    /** BR-31 — the same path serves "create" and "resend activation". */
    public function sendActivation(User $user, User $actor): void
    {
        $this->guardCredentialIsNotATakeover($user, $actor, 'send the activation code', 'send the code');

        $minutes = $this->activationWindowMinutes();
        [$code, $activationUrl] = $this->issueRedeemableCode($user, $minutes);

        $user->notify(new AccountCreatedNotification($code, $minutes, $actor->name, $activationUrl));
    }

    /**
     * BR-31 / AUTH-4 — an administrator resets ANY user's password.
     *
     * The screen offered this only to accounts that had never been signed into
     * ("resend activation", rendered while `password_changed_at` is null). For
     * everybody else — which is every real member of staff after their first day
     * — there was no lever. A collection agent who has forgotten their password
     * at 05:30, or an account whose credential is suspected to be in somebody
     * else's hands, left two options and both were wrong: deactivate-then-
     * reactivate stops them working and revokes every trusted device, and
     * "sign out everywhere" ends the sessions while leaving the password itself
     * working, so whoever knows it signs straight back in.
     *
     * BR-31 is not relaxed to close that gap. The administrator sets nothing and
     * sees nothing: the current hash is replaced with a random value nobody knows
     * (PasswordPolicy::invalidate) and the user is emailed a code they redeem to
     * choose their own password through AUTH-4's ordinary flow. What the
     * administrator gains is the ability to take a password OUT of use, which is
     * the half of "reset" that was missing.
     *
     * AUTH-4 — "reset revokes all sessions", so this revokes them here rather
     * than waiting for the user to finish: the point of resetting a compromised
     * credential is that the holder stops being able to act now, not whenever
     * they get round to reading their email. Mobile tokens go with them; a bearer
     * token reaching POST /sync/batch is a session by every meaning that matters.
     *
     * Trusted devices are deliberately LEFT ALONE. Device trust only decides
     * whether AUTH-1's emailed code is asked for at sign-in; it is not a
     * credential and cannot be used without one. An administrator who also wants
     * it gone has revokeDevice(), and folding it in here would make a forgotten
     * password cost a field agent their trusted phone as well.
     *
     * @return array{sessions: int, tokens: int}
     */
    public function resetPassword(User $user, string $reason, User $actor): array
    {
        /*
         * Not your own. An administrator who knows their password changes it on
         * the profile screen, and one who does not uses "Forgot password?" like
         * everybody else. Allowing it here would let the only account holding '*'
         * clear its own credential and then depend on the mail queue to get back
         * in — a self-inflicted lockout with no second administrator to undo it.
         */
        if ((int) $user->getKey() === (int) $actor->getKey()) {
            throw RuleViolationException::make(
                'BR-31',
                'You cannot reset your own password here. Change it on your profile screen, or use '
                .'“Forgot password?” on the sign-in page.',
                [],
            );
        }

        /*
         * A deactivated account cannot sign in at all (BR-32), so the code would
         * be undeliverable in practice — PasswordResetController::activate turns
         * the emailed link away — and the reset would look done while achieving
         * nothing. Reactivation already sends a code, which is the operation the
         * administrator actually wants.
         */
        if (! $user->isActive()) {
            throw RuleViolationException::make(
                'BR-32',
                sprintf(
                    '%s is deactivated, so a reset code would be refused at the sign-in screen. Reactivate '
                    .'the account instead — that sends them a code to choose a password with.',
                    $user->name,
                ),
                ['status' => $user->status],
            );
        }

        // AUTH-8 — the same takeover this refuses for an activation code. Without
        // it, "change their e-mail, then reset their password" would be the new
        // spelling of the chain the guard exists to break, and it would work on
        // ESTABLISHED accounts, which is exactly the case that matters.
        $this->guardCredentialIsNotATakeover($user, $actor, 'reset their password', 'do it');

        $minutes = $this->activationWindowMinutes();

        return DB::transaction(function () use ($user, $reason, $actor, $minutes): array {
            // Before the marker is written, so the password being displaced is
            // filed in AUTH-5's history and cannot be chosen again.
            $this->passwords->invalidate($user);

            $user->forceFill([
                'password_reset_at' => Wat::now(),
                'password_reset_by_user_id' => $actor->getKey(),
                'password_reset_reason' => $reason,
                /*
                 * AUTH-6 — a lockout guards a password that no longer exists.
                 * Leaving it would let the user redeem the code, choose a new
                 * password, be told "sign in with it" and bounce off the lock,
                 * which is the same trap PasswordResetService avoids at the end
                 * of a self-service reset.
                 */
                'locked_until' => null,
            ])->save();

            $ended = $this->sessions->endAllFor($user, 'admin_password_reset');
            $tokens = $this->apiTokens->revokeAllFor($user, 'admin_password_reset');

            [$code, $resetUrl] = $this->issueRedeemableCode($user, $minutes);

            $this->audit->edited(
                $user,
                sprintf('SECURITY — password reset for %s by %s — %s', $user->name, $actor->name, $reason),
                'Administration',
                ['password_set_by_holder' => true],
                [
                    'password_set_by_holder' => false,
                    'reason' => $reason,
                    'sessions_ended' => $ended,
                    'tokens_revoked' => $tokens,
                    'rule' => 'BR-31',
                    // NFR-9 — the code is hashed in login_codes and appears in no
                    // log. What is recorded is that one was issued.
                    'code' => 'issued to the account holder; never seen by the administrator',
                ],
                $actor,
            );

            $user->notify(new PasswordResetByAdminNotification(
                $code, $minutes, $actor->name, $reason, $resetUrl,
            ));

            $this->tellTheWatchers(
                $user,
                $actor,
                'An administrator reset a colleague\'s password',
                sprintf(
                    '%s reset the password on %s\'s account — %s. Their old password no longer works and every '
                    .'session and mobile token was ended; a code has gone to %s so they can choose a new one. '
                    .'Nobody set a password for them. Nothing is wrong by definition — it is here so it is seen '
                    .'rather than found later.',
                    $actor->name, $user->name, $reason, $user->email,
                ),
            );

            return ['sessions' => $ended, 'tokens' => $tokens];
        });
    }

    /**
     * BR-31, qualified — the administrator TYPES the new password.
     *
     * The owner-approved exception to "administrators never see or set a user's
     * password", and the reason it was asked for is a real one that the emailed
     * code cannot answer: a collection agent standing at a centre at 05:30 with
     * milk in the churn, who has forgotten their password and cannot reach their
     * mailbox from where they are. A code they will read tonight is not help. A
     * password said down the phone is.
     *
     * The exception is bounded to a single sign-in rather than granted outright.
     * PasswordPolicy::applyTemporary() marks the password temporary;
     * User::passwordHasExpired() reads that flag; EnsureAccountIsUsable turns it
     * into a redirect to the change-password screen before the user may reach any
     * other route on either surface; PasswordPolicy::apply() clears it when they
     * choose their own. So what an administrator ends up knowing is a password
     * that stops working the first time it is used properly.
     *
     * BE CLEAR ABOUT WHAT THIS COSTS, because no code in this method fixes it: an
     * administrator holding admin.users.edit can set the Executive Director's
     * password and sign in as them before the director hears a thing. AUTH-8's
     * guard cannot help — it protects the mailbox a code is delivered to, and no
     * code is delivered here, so the e-mail address is irrelevant and refusing on
     * it would be theatre. What is left is detection, and all three parts of it
     * are here on purpose: the user is emailed immediately (never the password),
     * Internal Audit and the General Manager are notified, and the audit entry
     * leads with SECURITY and names the administrator. Anyone weighing whether to
     * keep this feature should weigh that, not the convenience.
     *
     * @return array{sessions: int, tokens: int}
     */
    public function setPassword(User $user, string $plain, string $reason, User $actor): array
    {
        /*
         * Not your own — the profile screen's change-password flow is for that,
         * and it asks for the current password. Setting your own here would skip
         * that check, which is the one thing standing between a borrowed unlocked
         * laptop and a permanently stolen account.
         */
        if ((int) $user->getKey() === (int) $actor->getKey()) {
            throw RuleViolationException::make(
                'BR-31',
                'You cannot set your own password here. Change it on your profile screen, which asks for '
                .'your current password first.',
                [],
            );
        }

        // BR-32 — sign-in is blocked outright, so a password of any kind would be
        // refused at the door. Reactivate first.
        if (! $user->isActive()) {
            throw RuleViolationException::make(
                'BR-32',
                sprintf(
                    '%s is deactivated, so no password will get them in. Reactivate the account first.',
                    $user->name,
                ),
                ['status' => $user->status],
            );
        }

        return DB::transaction(function () use ($user, $plain, $reason, $actor): array {
            $this->passwords->applyTemporary($user, $plain);

            $user->forceFill([
                'password_reset_at' => Wat::now(),
                'password_reset_by_user_id' => $actor->getKey(),
                'password_reset_reason' => $reason,
                // AUTH-6 — the lock guarded the password that has just been
                // replaced. Leaving it would hand the user a working password and
                // then refuse it, which is the trap PasswordResetService avoids at
                // the end of a self-service reset.
                'locked_until' => null,
            ])->save();

            /*
             * AUTH-4 — the credential changed, so every session built on the old
             * one ends, phones included. This also closes the loophole that would
             * otherwise make the temporary flag pointless: a user already signed in
             * elsewhere would never pass through the change-password redirect,
             * leaving the administrator's password valid indefinitely.
             */
            $ended = $this->sessions->endAllFor($user, 'admin_set_password');
            $tokens = $this->apiTokens->revokeAllFor($user, 'admin_set_password');

            /*
             * Any outstanding reset code is now misleading — it would let the user
             * bypass the change screen and, worse, a code issued before this call
             * is one the administrator may have triggered themselves.
             */
            LoginCode::query()
                ->where('user_id', $user->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)
                ->usable()
                ->update(['invalidated_at' => Wat::now()]);

            $this->audit->edited(
                $user,
                sprintf(
                    'SECURITY — temporary password SET BY %s on %s\'s account — %s',
                    $actor->name, $user->name, $reason,
                ),
                'Administration',
                ['password_set_by_holder' => true],
                [
                    'password_set_by_holder' => false,
                    'password_set_by' => $actor->name,
                    'temporary' => true,
                    'must_change_at_next_signin' => true,
                    'reason' => $reason,
                    'sessions_ended' => $ended,
                    'tokens_revoked' => $tokens,
                    'rule' => 'BR-31 (qualified — administrator-chosen password)',
                    // NFR-9 — the password itself is hashed in the row and appears
                    // in no log, no notification and no audit detail.
                    'password' => 'never recorded; communicated out of band by the administrator',
                ],
                $actor,
            );

            // Never the password itself — see the notification's own docblock.
            $user->notify(new TemporaryPasswordSetNotification($actor->name, $reason));

            $this->tellTheWatchers(
                $user,
                $actor,
                'An administrator set a colleague\'s password',
                sprintf(
                    '%s set a temporary password on %s\'s account — %s. Unlike a reset code, this means %s '
                    .'knew a working password for that account, and could have signed in as %s until it was '
                    .'changed. %s must replace it at their next sign-in and has been emailed about it. This '
                    .'is the one administrative action the system cannot prevent, only report.',
                    $actor->name, $user->name, $reason, $actor->name, $user->name, $user->name,
                ),
            );

            return ['sessions' => $ended, 'tokens' => $tokens];
        });
    }

    /**
     * AUTH-4 — how long an out-of-band code stays good.
     *
     * The activation window rather than the 15-minute reset one, for both
     * creation and an administrator's reset. Fifteen minutes answers "I forgot, I
     * am at the keyboard now"; a code somebody else triggered sits in an inbox
     * until its owner reaches a machine, which in rural Adamawa can be days. A
     * code that expires before its recipient has read the email is not a reset,
     * it is a support call.
     */
    private function activationWindowMinutes(): int
    {
        return (int) config('gondal.auth.activation_code_ttl_minutes', 4320);
    }

    /**
     * A reset code plus the signed link that makes it redeemable.
     *
     * The email's button is a SIGNED link that seeds the reset session for this
     * user before landing them on the code screen. Without it, the emailed code
     * was unredeemable: the verify screen only trusted a session begun at the
     * forgot-password form, and starting there invalidated the emailed code and
     * issued a different one — every new hire's first contact with the system was
     * a dead code and an error page.
     *
     * The signature carries the same expiry as the code, so the link cannot
     * outlive what it unlocks.
     *
     * @return array{0: string, 1: string} the plaintext code, and the signed URL
     */
    private function issueRedeemableCode(User $user, int $minutes): array
    {
        $length = (int) config('gondal.auth.code_length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        // Asking twice must not leave two live codes; the newest wins.
        LoginCode::query()
            ->where('user_id', $user->getKey())
            ->forPurpose(LoginCode::PURPOSE_RESET)
            ->usable()
            ->update(['invalidated_at' => Wat::now()]);

        // NFR-9 — stored hashed. The plaintext exists only in the email.
        LoginCode::query()->create([
            'user_id' => $user->getKey(),
            'purpose' => LoginCode::PURPOSE_RESET,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Wat::now()->addMinutes($minutes),
        ]);

        $url = URL::temporarySignedRoute(
            'activate',
            Wat::now()->addMinutes($minutes),
            ['user' => $user->getKey()],
        );

        return [$code, $url];
    }

    /**
     * AUTH-8 — an emailed code is a credential handover, so it must not be
     * deliverable to an address the person asking for it chose, on an account
     * somebody is already using.
     *
     * The takeover was two ordinary edits, both gated on admin.users.edit alone:
     * change a user's e-mail (PUT /admin/users/{user}), then press "resend
     * activation" (POST .../send-activation). The code arrived in the
     * administrator's own mailbox, they redeemed it through BR-31's flow, chose a
     * password, and were that person — the Executive Director, the General
     * Manager, or Internal Audit, whose job is to review what administrators do.
     *
     * Note what the escalation is NOT. System Administrator holds '*', so the
     * attacker gains no permission they lacked; what they gain is somebody else's
     * NAME on every subsequent approval, and an audit trail that says the
     * director did it. Comparing grant sets would therefore have refused nothing.
     *
     * Neither edit is refused on its own — correcting a typo is real work, and so
     * is re-sending an invitation. What is refused is the pair, on an account
     * that has been activated: `password_changed_at` is null exactly while an
     * account has never been signed into, and an account nobody has used yet is
     * not one you can impersonate. An established colleague who genuinely cannot
     * get in resets their own password from the sign-in screen, which is what
     * BR-31 prefers anyway.
     *
     * Both doors are guarded, because there are now two. "Resend activation" and
     * resetPassword() end in the same place — a code, at whatever address the row
     * currently holds — so guarding only the first would have left the identical
     * chain spelled a different way, and the reset route reaches ESTABLISHED
     * accounts, which is precisely the case this refuses.
     *
     * `password_changed_at`, not `password_reset_at`, decides "never used". A
     * forced reset deliberately leaves the former alone (see the migration) so
     * that an administrator cannot clear the flag that protects the account and
     * then walk through the door it was holding shut.
     *
     * @param  string  $attempted  what the actor is trying to do, in the second person
     * @param  string  $delegated  the same thing, asked of another administrator
     */
    private function guardCredentialIsNotATakeover(User $user, User $actor, string $attempted, string $delegated): void
    {
        if ((int) $user->getKey() === (int) $actor->getKey()) {
            return;
        }

        $setByThisActor = $user->email_changed_by_user_id !== null
            && (int) $user->email_changed_by_user_id === (int) $actor->getKey();

        if (! $setByThisActor || $user->password_changed_at === null) {
            return;
        }

        throw RuleViolationException::make(
            'AUTH-8',
            sprintf(
                'You changed the e-mail address on %s\'s account, so you cannot also %s — the code would '
                .'arrive at an address you chose, on an account somebody is already using. Ask %s to reset '
                .'their own password from the sign-in screen, or ask another administrator to %s.',
                $user->name, $attempted, $user->name, $delegated,
            ),
            ['target' => $user->email, 'address_set_by' => $actor->name],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $before = $user->only(['name', 'email', 'phone', 'department_id', 'position', 'two_factor_enabled', 'is_test']);

        $newEmail = (string) ($data['email'] ?? $user->email);
        $emailMoved = $newEmail !== (string) $user->email;
        $twoFactorRemoved = (bool) $user->two_factor_enabled && ! (bool) ($data['two_factor_enabled'] ?? $user->two_factor_enabled);

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'email' => $newEmail,
            'phone' => $data['phone'] ?? $user->phone,
            'department_id' => $data['department_id'] ?? $user->department_id,
            'position' => $data['position'] ?? $user->position,
            'employee_id' => $data['employee_id'] ?? $user->employee_id,
            'two_factor_enabled' => (bool) ($data['two_factor_enabled'] ?? $user->two_factor_enabled),
            'is_test' => (bool) ($data['is_test'] ?? $user->is_test),
        ]);

        if ($emailMoved) {
            // Who moved the identity, and when. guardActivationIsNotATakeover()
            // reads both back.
            $user->forceFill([
                'email_changed_at' => Wat::now(),
                'email_changed_by_user_id' => $actor->getKey(),
            ]);
        }

        $user->save();

        $this->audit->edited(
            $user,
            $user->name.' account details updated',
            'Administration',
            $before,
            $user->only(array_keys($before)),
            $actor,
        );

        if ($emailMoved) {
            $this->announceEmailChange($user, (string) $before['email'], $actor);
        }

        if ($twoFactorRemoved) {
            $this->announceTwoFactorRemoval($user, $actor);
        }

        return $user;
    }

    /**
     * An e-mail change is a security event, not a profile edit.
     *
     * Buried in a routine data_edit alongside a name and a phone number, the one
     * change that redirects every future credential to a different mailbox was
     * the quietest row in the log. It gets its own greppable summary, the address
     * that is losing the account is told, and — when the target holds authority
     * the actor does not — the same watchers BR-34 already notifies about a
     * self-granted role hear about it.
     */
    private function announceEmailChange(User $user, string $previousEmail, User $actor): void
    {
        $this->audit->edited(
            $user,
            sprintf('SECURITY — sign-in address for %s changed from %s to %s by %s',
                $user->name, $previousEmail, $user->email, $actor->name),
            'Administration',
            ['email' => $previousEmail],
            ['email' => $user->email, 'rule' => 'AUTH-8', 'changed_by' => $actor->name],
            $actor,
        );

        // The address that is losing the account is the only one that can tell
        // us this was not asked for.
        Notification::route('mail', $previousEmail)
            ->notify(new EmailAddressChangedNotification(
                (string) $user->name, (string) $user->email, (string) $actor->name,
            ));

        $this->tellTheWatchers(
            $user,
            $actor,
            'An administrator changed a colleague\'s sign-in address',
            sprintf(
                '%s changed the e-mail address on %s\'s account from %s to %s. That address receives every '
                .'activation and password-reset code, so this is the change that decides who can get into '
                .'the account. Nothing is wrong by definition — it is here so it is seen rather than found later.',
                $actor->name, $user->name, $previousEmail, $user->email,
            ),
        );
    }

    /** AUTH-1 — switching off somebody else's second factor is announced too. */
    private function announceTwoFactorRemoval(User $user, User $actor): void
    {
        $this->audit->edited(
            $user,
            sprintf('SECURITY — two-factor sign-in disabled on %s\'s account by %s', $user->name, $actor->name),
            'Administration',
            ['two_factor_enabled' => true],
            ['two_factor_enabled' => false, 'rule' => 'AUTH-1', 'changed_by' => $actor->name],
            $actor,
        );

        $this->tellTheWatchers(
            $user,
            $actor,
            'An administrator switched off a colleague\'s second factor',
            sprintf(
                '%s disabled two-factor sign-in on %s\'s account. A user cannot do this to themselves — '
                .'the profile screen deliberately refuses it (AUTH-1) — so an administrator doing it to '
                .'somebody else is the only way it can happen.',
                $actor->name, $user->name,
            ),
        );
    }

    /**
     * BR-34's watcher list, reused.
     *
     * Silent when somebody is editing their own account, and silent while an
     * account is still being onboarded (`password_changed_at` null) — correcting
     * a new hire's address before they have ever signed in is data entry.
     * Everything else goes to Internal Audit and the General Manager. Comparing
     * grant sets instead would announce nothing at all: the only live role that
     * can reach these routes holds '*', so it out-ranks everybody by definition
     * and would out-rank nobody by that test.
     */
    private function tellTheWatchers(User $user, User $actor, string $title, string $body): void
    {
        if ((int) $user->getKey() === (int) $actor->getKey() || $user->password_changed_at === null) {
            return;
        }

        $watchers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.name', ['Internal Audit', 'General Manager']))
            ->where('users.id', '!=', $actor->getKey())
            ->get();

        if ($watchers->isEmpty()) {
            return;
        }

        $this->notifications->send(
            eventCode: 'role.changed',
            recipients: $watchers->all(),
            title: $title,
            body: $body,
            actionUrl: route('admin.users.show', $user),
            subject: $user,
        );
    }

    /**
     * BR-32 — "Deactivating a user blocks sign-in and revokes sessions but
     * preserves all attribution on their historical records."
     */
    public function deactivate(User $user, string $reason, User $actor): User
    {
        if ($user->getKey() === $actor->getKey()) {
            throw RuleViolationException::make(
                'BR-32',
                'You cannot deactivate your own account.',
                [],
            );
        }

        $user->forceFill([
            'status' => 'deactivated',
            'deactivated_at' => Wat::now(),
            'deactivated_reason' => $reason,
        ])->save();

        // BR-32 — sessions, mobile tokens and trusted devices all go. The tokens
        // were previously killed only when the phone next made a request, which
        // left the row readable as live on the administrator's screen in the
        // meantime.
        $revoked = $this->sessions->endAllFor($user, 'deactivated');
        $tokens = $this->apiTokens->revokeAllFor($user, 'deactivated');
        $user->devices()->whereNull('revoked_at')->update([
            'revoked_at' => Wat::now(),
            'revoked_by_user_id' => $actor->getKey(),
        ]);

        $this->audit->edited(
            $user,
            sprintf('%s deactivated — %s', $user->name, $reason),
            'Administration',
            ['status' => 'active'],
            [
                'status' => 'deactivated',
                'reason' => $reason,
                'sessions_revoked' => $revoked,
                'tokens_revoked' => $tokens,
                'rule' => 'BR-32',
                'attribution' => 'preserved on all historical records',
            ],
            $actor,
        );

        return $user;
    }

    public function reactivate(User $user, User $actor): User
    {
        $user->forceFill([
            'status' => 'active',
            'deactivated_at' => null,
            'deactivated_reason' => null,
            'locked_until' => null,
        ])->save();

        $this->audit->edited(
            $user,
            $user->name.' reactivated',
            'Administration',
            ['status' => 'deactivated'],
            ['status' => 'active'],
            $actor,
        );

        // BR-31 — they still choose their own password.
        $this->sendActivation($user, $actor);

        return $user;
    }

    /**
     * ROLE-2 — pairs of roles one person must not hold at once.
     *
     * These are separation-of-duties boundaries, and each exists because holding
     * both collapses a control that the rest of the system spends real effort
     * maintaining:
     *
     *   Milk Collection Officer + Quality Officer — the clerk assigns a grade,
     *   the quality officer may change one. One person holding both can grade a
     *   consignment and then re-grade it at will, which is exactly the loop the
     *   re-grade break (BR-4) exists to open up to a second pair of eyes.
     *
     *   A recording role + Milk Collection Supervisor — whoever records the milk
     *   must not be the person who reconciles it against what the factory
     *   received and releases the batch. That is self-approval with extra steps.
     *
     * Written as unordered pairs; the check tries both directions.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const INCOMPATIBLE_ROLES = [
        [
            'Milk Collection Officer', 'Quality Officer',
            'one person would be able to assign a grade and then change it — the re-grade check exists to stop exactly that',
        ],
        [
            'Collection Agent', 'Milk Collection Supervisor',
            'whoever records the milk must not also reconcile and release it',
        ],
        [
            'Milk Collection Officer', 'Milk Collection Supervisor',
            'whoever confirms a consignment must not also reconcile and release the batch it joins',
        ],
    ];

    /**
     * ROLE-2 — refuse a combination that defeats a control.
     *
     * Checked against the roles the user ALREADY holds, in the service rather
     * than in the controller, so the API cannot make a pairing the screen refuses.
     */
    private function guardCoHolding(User $user, Role $role): void
    {
        $held = $user->roles()->pluck('name')->all();

        foreach (self::INCOMPATIBLE_ROLES as [$first, $second, $why]) {
            $other = match ($role->name) {
                $first => $second,
                $second => $first,
                default => null,
            };

            if ($other === null || ! in_array($other, $held, true)) {
                continue;
            }

            throw RuleViolationException::make(
                'ROLE-2',
                sprintf(
                    '%s already holds %s, and one person cannot hold both that and %s — %s.',
                    $user->name, $other, $role->name, $why,
                ),
                ['held' => $other, 'requested' => $role->name],
                'role_id',
            );
        }
    }

    /**
     * BR-34 — an administrator granting themselves operational authority.
     *
     * Not forbidden: a small organisation has one administrator and they may
     * genuinely need to cover a role. But it is the single change least likely
     * to be questioned by anyone else, so it is announced rather than left to be
     * discovered in the log. Internal Audit and the General Manager are told.
     */
    private function announceSelfAssignment(User $user, Role $role, RoleAssignment $assignment, User $actor): void
    {
        if ((int) $user->getKey() !== (int) $actor->getKey() || $role->is_automatic) {
            return;
        }

        $watchers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.name', ['Internal Audit', 'General Manager']))
            ->where('users.id', '!=', $actor->getKey())
            ->get();

        $this->audit->roleChanged(
            $role,
            sprintf('%s assigned %s TO THEMSELVES — scope: %s', $actor->name, $role->name, $assignment->describeScope()),
            [
                'self_assigned' => true,
                'role' => $role->name,
                'scope' => $assignment->describeScope(),
                'rule' => 'BR-34',
            ],
            $actor,
        );

        if ($watchers->isEmpty()) {
            return;
        }

        $this->notifications->send(
            eventCode: 'role.changed',
            recipients: $watchers->all(),
            title: 'An administrator granted themselves a role',
            body: sprintf(
                '%s assigned themselves %s (%s). Nothing is wrong by definition — this is here so it is seen rather than found later.',
                $actor->name, $role->name, $assignment->describeScope(),
            ),
            actionUrl: route('admin.users.show', $actor),
            subject: $actor,
        );
    }

    /**
     * The targets an assignment names, however the caller spelled them.
     *
     * @param  array<int, int>  $targetIds
     * @return array<int, int>
     */
    private function namedTargets(?int $scopeTargetId, array $targetIds): array
    {
        $named = array_map('intval', $targetIds);

        if ($scopeTargetId !== null) {
            $named[] = $scopeTargetId;
        }

        $named = array_values(array_unique(array_filter($named)));
        sort($named);

        return $named;
    }

    /**
     * SCOPE-1 — the assignment carries the scope, and a targeted scope carries
     * one or more named targets.
     *
     * A caller may name the target either way round: `$scopeTargetId` for the
     * single-target case the admin screen has always posted, or `$targetIds` for
     * several. They are the same thing to the engine, which reads the union.
     *
     * @param  array<int, int>  $targetIds  additional named targets
     */
    public function assignRole(
        User $user,
        Role $role,
        ScopeType $scopeType,
        ?int $scopeTargetId,
        array $targetIds,
        User $actor,
    ): RoleAssignment {
        if ($role->status === Role::STATUS_RETIRED) {
            throw RuleViolationException::make(
                'ROLE-4',
                "{$role->name} is retired and cannot be assigned. It is kept only for the audit trail.",
                ['role' => $role->name],
            );
        }

        // ROLE-5 — a draft role has no agreed scope, so it must not reach staff.
        if ($role->status === Role::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ROLE-5',
                "{$role->name} is still a draft — its scope has not been agreed (§15.2). Define it before assigning it.",
                ['role' => $role->name],
            );
        }

        $this->guardCoHolding($user, $role);

        /*
         * SCOPE-1 — a targeted scope with no target grants nothing, and must be
         * refused rather than saved as an assignment that silently denies. One
         * check covers both spellings, so naming three centres is as valid as
         * naming one and neither route can produce an empty scope.
         */
        $named = $this->namedTargets($scopeTargetId, $targetIds);

        if ($scopeType->requiresTarget() && $named === []) {
            throw RuleViolationException::make(
                'SCOPE-1',
                sprintf('A %s scope needs at least one target — otherwise the assignment grants nothing.', $scopeType->label()),
                ['scope_type' => $scopeType->value],
                $scopeType === ScopeType::Communities ? 'community_ids' : 'scope_target_id',
            );
        }

        $assignment = DB::transaction(function () use ($user, $role, $scopeType, $named, $actor): RoleAssignment {
            /*
             * One target stays in the column it has always lived in; several move
             * to the child table. Writing it this way means an assignment that was
             * single yesterday and is single today is byte-identical, so nothing
             * has to be migrated and no existing row changes meaning.
             */
            $single = count($named) === 1 ? $named[0] : null;

            /*
             * withTrashed() is load-bearing. removeRole() soft-deletes, so a role
             * that was taken off somebody leaves its row — and its unique key —
             * behind; searching through the SoftDeletes scope misses it and issues
             * an INSERT the unique index refuses, turning revoke-then-regrant into
             * a 500. Reviving the same row also preserves its id, and with it every
             * audit entry and scope target that references the earlier grant.
             *
             * The revival is an explicit restore() rather than a `deleted_at =>
             * null` in the values array: deleted_at is not fillable, so fill()
             * drops it silently and the row stays trashed.
             */
            $assignment = RoleAssignment::withTrashed()
                ->where('role_id', $role->getKey())
                ->where('user_id', $user->getKey())
                ->first()
                ?? new RoleAssignment(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

            $assignment->fill([
                'scope_type' => $scopeType->value,
                'scope_target_id' => $single,
                'assigned_by_user_id' => $actor->getKey(),
                'assigned_at' => Wat::now(),
            ])->save();

            if ($assignment->trashed()) {
                $assignment->restore();
            }

            // Replaced wholesale: dropping a centre must actually drop it.
            RoleUserScopeTarget::query()->where('role_user_id', $assignment->getKey())->delete();

            if ($single === null) {
                foreach ($named as $targetId) {
                    RoleUserScopeTarget::query()->create([
                        'role_user_id' => $assignment->getKey(),
                        'target_id' => $targetId,
                    ]);
                }
            }

            return $assignment;
        });

        $this->audit->roleChanged(
            $role,
            sprintf('%s assigned to %s — scope: %s', $role->name, $user->name, $assignment->describeScope()),
            [
                'user' => $user->name,
                'scope_type' => $scopeType->value,
                'scope' => $assignment->describeScope(),
                'rule' => 'SCOPE-1',
            ],
            $actor,
        );

        // NOTIF-3 — "role or permission changed".
        $this->notifications->send(
            eventCode: 'role.changed',
            recipients: [$user],
            title: 'Your access changed',
            body: sprintf('You were assigned the %s role (%s).', $role->name, $assignment->describeScope()),
            actionUrl: route('profile'),
            subject: $user,
        );

        $this->announceSelfAssignment($user, $role, $assignment, $actor);

        // ROLE-6 — takes effect on their next request; nothing is cached.
        $user->forgetAccessMemo();

        return $assignment;
    }

    public function removeRole(RoleAssignment $assignment, User $actor): void
    {
        $role = $assignment->role;
        $user = $assignment->user;

        if ($role !== null && $role->is_automatic) {
            throw RuleViolationException::make(
                'ROLE-3',
                "Every user holds {$role->name} automatically; it cannot be removed.",
                ['role' => $role->name],
            );
        }

        $assignment->delete();

        if ($role !== null && $user !== null) {
            $this->audit->roleChanged(
                $role,
                sprintf('%s removed from %s', $role->name, $user->name),
                ['user' => $user->name],
                $actor,
            );

            $this->notifications->send(
                eventCode: 'role.changed',
                recipients: [$user],
                title: 'Your access changed',
                body: sprintf('The %s role was removed from your account.', $role->name),
                actionUrl: route('profile'),
                subject: $user,
            );

            $user->forgetAccessMemo();
        }
    }
}
