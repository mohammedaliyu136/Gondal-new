<?php

namespace App\Services\Auth;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * AUTH-5 — "minimum 10 characters, at least one uppercase, one lowercase, one
 * number or symbol, and not among the user's last 3 passwords. Maximum age 90
 * days."
 *
 * BR-31 — "Administrators never see or set a user's password." Nothing in this
 * class accepts a password chosen by anyone other than the account holder.
 * invalidate() is the one thing an administrator can reach, and it takes a
 * password away rather than supplying one.
 */
class PasswordPolicy
{
    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min((int) config('gondal.auth.password_min_length', 10))
                ->mixedCase()
                ->numbers()
                ->uncompromised(),
        ];
    }

    /** A sentence for the form hint, generated from the policy itself. */
    public function describe(): string
    {
        return sprintf(
            'At least %d characters with an uppercase letter, a lowercase letter and a number or symbol. It cannot repeat your last %d passwords.',
            (int) config('gondal.auth.password_min_length', 10),
            (int) config('gondal.auth.password_history', 3),
        );
    }

    /** AUTH-5 — "not among the user's last 3 passwords". */
    public function isReused(User $user, string $plain): bool
    {
        $keep = (int) config('gondal.auth.password_history', 3);

        $recent = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest('created_at')
            ->limit($keep)
            ->pluck('password_hash');

        /*
         * The hash in place right now counts as one of the last N — unless an
         * administrator has cleared it, in which case it is a random value nobody
         * has ever typed. Counting that would push a real password out of the
         * window and quietly turn AUTH-5's "last 3" into "last 2" for exactly the
         * user whose credential was in doubt.
         *
         * A TEMPORARY password does count. It is a real password, an administrator
         * knows it, and refusing it here is what stops the user answering "choose
         * a new password" with the one they were just given over the phone.
         */
        if ($user->password_hash !== null && $user->password_hash !== '' && ! $user->passwordIsUnknowable()) {
            $recent = $recent->prepend($user->password_hash)->take($keep);
        }

        foreach ($recent as $hash) {
            if (Hash::check($plain, (string) $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies a new password. The caller is responsible for having established
     * that the holder chose it (a verified code, or their current password).
     */
    public function apply(User $user, string $plain): void
    {
        $this->fileCurrentHash($user);

        $user->forceFill([
            'password_hash' => Hash::make($plain),
            'password_changed_at' => Wat::now(),
            /*
             * BR-31 — whatever an administrator forced is now answered. The
             * account is an ordinary one again, the screen stops saying it is
             * waiting, and — the load-bearing one — `password_is_temporary` goes
             * false, which is what lets the user past EnsureAccountIsUsable's
             * redirect to this very screen.
             */
            'password_reset_at' => null,
            'password_reset_by_user_id' => null,
            'password_reset_reason' => null,
            'password_is_temporary' => false,
        ])->save();

        $this->pruneHistory($user);
    }

    /**
     * BR-31, qualified — install a password an ADMINISTRATOR chose, marked as
     * temporary so the user must replace it before they can do anything else.
     *
     * The one place in this class where the password did not come from the account
     * holder. It is a narrowing of BR-31 the owner asked for, and the honest
     * summary of the trade is: for the window between this call and the user's
     * first sign-in, somebody else knows a working credential for this account and
     * could use it. What keeps that window shut afterwards is the temporary flag —
     * User::passwordHasExpired() reads it, EnsureAccountIsUsable redirects on it,
     * and apply() above clears it. Nothing else in the system may set it back.
     *
     * Deliberately does NOT run isReused(). Telling an administrator "that is one
     * of their last three passwords" would leak the user's password history to
     * somebody who has no business knowing it, and the value is about to be
     * replaced anyway. The user's own choice, a minute later, is checked normally
     * — and by then the temporary password is the current hash, so isReused()
     * refuses it.
     */
    public function applyTemporary(User $user, string $plain): void
    {
        $this->fileCurrentHash($user);

        $user->forceFill([
            'password_hash' => Hash::make($plain),
            // Accurate: the password did change, just now. AUTH-5's 90-day clock
            // is irrelevant while the temporary flag forces a change on sight,
            // and leaving this null would tell guardCredentialIsNotATakeover that
            // the account has never been used.
            'password_changed_at' => Wat::now(),
            'password_is_temporary' => true,
        ])->save();

        $this->pruneHistory($user);
    }

    /**
     * Move the hash currently in force into AUTH-5's history, so it cannot be
     * chosen again.
     *
     * Skipped for a placeholder written by invalidate(): filing it would spend one
     * of AUTH-5's three slots on a value nobody can retype, and the real hash it
     * displaced is already there.
     */
    private function fileCurrentHash(User $user): void
    {
        if ($user->password_hash === null || $user->password_hash === '' || $user->passwordIsUnknowable()) {
            return;
        }

        PasswordHistory::query()->create([
            'user_id' => $user->getKey(),
            'password_hash' => $user->password_hash,
            'created_at' => Wat::now(),
        ]);
    }

    /**
     * BR-31 — take a password out of use WITHOUT anybody choosing a new one.
     *
     * This is the half of an administrator's reset that BR-31 allows: the current
     * hash is replaced with a random value nobody has ever seen — not the
     * administrator, not the user — so the old password stops opening the account
     * immediately, and the emailed code is the only way back in. The same shape
     * UserAdminService::create() uses for a brand-new account, applied to an
     * established one.
     *
     * The displaced hash is filed in history first, so AUTH-5 still refuses it
     * when the user chooses their replacement: a reset is not a way to launder a
     * password back into the allowed set.
     */
    public function invalidate(User $user): void
    {
        $this->fileCurrentHash($user);

        // Never persisted anywhere, never emailed, never logged. It exists only
        // so that the column is a valid bcrypt hash that no input matches.
        $user->forceFill([
            'password_hash' => Hash::make(Str::random(64)),
            // Not temporary — nobody knows this one, so there is nothing for the
            // user to sign in with and be forced to change. Clearing it matters
            // when an administrator changes their mind: set a temporary password,
            // then decide to send a code instead.
            'password_is_temporary' => false,
        ])->save();

        $this->pruneHistory($user);
    }

    /** Keep only what AUTH-5 needs, so an old hash is not retained forever. */
    private function pruneHistory(User $user): void
    {
        $keep = (int) config('gondal.auth.password_history', 3);

        $stale = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest('created_at')
            ->skip($keep)
            ->take(100)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            PasswordHistory::query()->whereIn('id', $stale)->delete();
        }
    }
}
