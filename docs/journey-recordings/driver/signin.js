/*
 * Signing in, including the two-factor step.
 *
 * Two-factor is ON for every account (a `migrate:fresh --seed` turns it back on,
 * which is what happened here). The code is read out of the delivered message
 * rather than guessed — see latestCode(). A test that guessed the code would be
 * testing nothing.
 */
const fs = require('fs');
const path = require('path');

const REPO = '/Users/mohammed/work/Trust Data/new gondal backend/Backend code';

/**
 * The most recent login code sent to this address.
 *
 * `login_codes.code_hash` is a hash — correctly, the plaintext is never stored —
 * so the database cannot answer this. Locally MAIL_MAILER=log, so the delivered
 * message is in laravel.log, and that is the only honest source. The lookup is
 * anchored to the recipient so a code issued to somebody else cannot be picked
 * up by accident, and it reads the LAST such message so a re-issued code wins.
 */
function latestCode(email) {
  const log = path.join(REPO, 'storage/logs/laravel.log');
  if (!fs.existsSync(log)) return null;

  // The tail is enough; the file is tens of megabytes.
  const size = fs.statSync(log).size;
  const span = Math.min(size, 3_000_000);
  const fd = fs.openSync(log, 'r');
  const buf = Buffer.alloc(span);
  fs.readSync(fd, buf, 0, span, size - span);
  fs.closeSync(fd);
  const text = buf.toString('utf8');

  const marker = 'To: ' + email;
  const at = text.lastIndexOf(marker);
  if (at === -1) return null;

  // The code appears within this message, as **123456**.
  const message = text.slice(at, at + 20000);
  const hits = [...message.matchAll(/\*\*(\d{6})\*\*/g)];
  return hits.length ? hits[hits.length - 1][1] : null;
}

async function signIn(j, email, password) {
  await j.go('/login', `sign-in page for ${email}`);

  await j.fill('input[name=email]', email);
  await j.fill('input[name=password]', password);
  await j.frame('credentials entered');

  const step = await j.submit('form', 'after submitting credentials');

  // Two-factor, if the account asks for it.
  const body = await j.text();
  if (/code|verification|two-factor/i.test(body) && (await j.page.$('input[name=code]'))) {
    const code = latestCode(email);
    if (!code) return { ok: false, why: 'no login code was issued', frame: step.frame };
    await j.fill('input[name=code]', code);
    await j.frame('two-factor code entered (read from the database)');
    await j.submit('form', 'after submitting the two-factor code');
  }

  // A forced password change would block everything behind it.
  if (/change your password|password has expired/i.test(await j.text())) {
    return { ok: false, why: 'forced password change blocks the journey', frame: j.n };
  }

  const url = j.page.url();
  const ok = !url.includes('/login');
  return { ok, why: ok ? null : 'still on the sign-in page', frame: j.n, url };
}

module.exports = { signIn, latestCode };
