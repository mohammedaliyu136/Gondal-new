<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Wat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL ONLY — put one known password on every active account and optionally
 * switch off AUTH-1's second factor, so the whole system can be clicked through
 * as any member of staff.
 *
 * This command breaks BR-31 on purpose and at scale. It is the same unavoidable
 * exception PilotUsersSeeder documents — somebody has to be able to sign in
 * before there is anyone to send an activation code to — except this one is
 * blunter, because it overwrites passwords that real people already chose. Read
 * the guards before you reach for it:
 *
 *   - It REFUSES to run outside APP_ENV=local. Not a warning, a hard abort. A
 *     shared password on every account is a total compromise of a real
 *     deployment, and "I was only testing" is not a control.
 *   - It never touches a DEACTIVATED account. BR-32 keeps ex-staff out while
 *     preserving their attribution, and "activate everything" must not quietly
 *     hand system access back to somebody who left the cooperative. Those rows
 *     are listed and skipped.
 *   - The password comes from --password or GONDAL_LOCAL_PASSWORD. It is not
 *     defaulted, because a default would end up committed, then deployed.
 *
 * WHY THE PASSWORDS ARE PERMANENT BY DEFAULT. --temporary is available and marks
 * every account must-change-at-next-sign-in, which is the safer state — but it is
 * useless for the job this command exists for: EnsureAccountIsUsable redirects a
 * must-change user to the change-password screen before they may reach any other
 * route, so you could not test a single feature without first changing the
 * password and losing the shared one. Permanent is the honest default for a
 * throwaway local database; the guard that makes it acceptable is that the
 * database is a throwaway local one.
 *
 * If this database is ever copied, promoted, or restored anywhere real, every
 * account on it has a password you already know. Treat it as burned.
 */
class ActivateAllAccountsForTesting extends Command
{
    protected $signature = 'gondal:activate-all
        {--password= : The password to set. Falls back to GONDAL_LOCAL_PASSWORD.}
        {--temporary : Mark every password must-change-at-next-sign-in (see the class docblock — this blocks testing).}
        {--keep-two-factor : Leave AUTH-1 second factors alone.}
        {--dry-run : Report what would change and write nothing.}';

    protected $description = 'LOCAL ONLY. Set one known password on every active account so the system can be tested as any user.';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refused: this command only runs with APP_ENV=local.');
            $this->line('It puts one password you know on every account. Outside a throwaway database that is not a test, it is a breach.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: env('GONDAL_LOCAL_PASSWORD', ''));

        if ($password === '') {
            $this->error('Refused: no password given.');
            $this->line('Pass --password=... or set GONDAL_LOCAL_PASSWORD. There is deliberately no default — a default would get committed and then deployed.');

            return self::FAILURE;
        }

        $temporary = (bool) $this->option('temporary');
        $dryRun = (bool) $this->option('dry-run');

        // BR-32 — reported so the skip is visible, never reactivated. Somebody who
        // left the cooperative does not come back because of a testing convenience.
        $deactivated = User::query()->where('status', '!=', 'active')->orderBy('name')->get();

        if ($deactivated->isNotEmpty()) {
            $this->newLine();
            $this->warn('SKIPPED — deactivated accounts (BR-32). Reactivate deliberately, one at a time, if you mean to:');

            foreach ($deactivated as $user) {
                $this->line(sprintf('    %-28s %-34s %s', $user->name, $user->email, $user->deactivated_reason ?? '—'));
            }
        }

        $active = User::query()->where('status', 'active')->orderBy('name')->get();

        if ($active->isEmpty()) {
            $this->warn('No active accounts.');

            return self::SUCCESS;
        }

        $twoFactorOff = $active->where('two_factor_enabled', true)->count();

        $this->newLine();
        $this->info(sprintf(
            '%s %d active account(s): %s password%s',
            $dryRun ? 'WOULD change' : 'Changing',
            $active->count(),
            $temporary ? 'temporary' : 'permanent',
            $this->option('keep-two-factor') ? '' : sprintf(', second factor off for %d', $twoFactorOff),
        ));

        if ($dryRun) {
            $this->newLine();
            $this->line('Nothing written (--dry-run).');

            return self::SUCCESS;
        }

        /*
         * One hash for all of them. Bcrypt is deliberately slow, and hashing the
         * same string 75 times separately is 75x that cost for an identical
         * result — the salt is inside the hash, so every row would differ, but
         * nothing about this command's threat model benefits from that.
         */
        $hash = Hash::make($password);
        $now = Wat::now();

        DB::transaction(function () use ($active, $hash, $now, $temporary): void {
            User::query()
                ->whereIn('id', $active->modelKeys())
                ->update(array_merge([
                    'password_hash' => $hash,
                    'password_changed_at' => $now,
                    'password_is_temporary' => $temporary,
                    // Any pending admin reset is answered by this.
                    'password_reset_at' => null,
                    'password_reset_by_user_id' => null,
                    'password_reset_reason' => null,
                    // AUTH-6 — a lockout guarding a password that no longer exists
                    // would just be a locked door on a missing wall.
                    'locked_until' => null,
                ], $this->option('keep-two-factor') ? [] : ['two_factor_enabled' => false]));
        });

        /*
         * Outstanding codes are now misleading: they were issued against passwords
         * that no longer exist, and a stale activation link would drop the user
         * into a reset flow they did not ask for.
         */
        $codes = DB::table('login_codes')
            ->whereIn('user_id', $active->modelKeys())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $now]);

        $this->newLine();
        $this->info(sprintf('Done. %d account(s) updated, %d outstanding code(s) invalidated.', $active->count(), $codes));
        $this->newLine();
        $this->warn('Every active account now shares one password that you know.');
        $this->warn('This database is compromised by design. Do not copy, promote, or restore it anywhere real.');

        if (! $this->option('keep-two-factor')) {
            $this->warn('AUTH-1 second factors are OFF. Turn them back on before this resembles production.');
        }

        return self::SUCCESS;
    }
}
