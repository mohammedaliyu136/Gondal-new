/*
 * Journey harness for the Gondal ERP end-to-end walk.
 *
 * Drives the real application in real Chrome, captures a numbered frame after
 * every interaction, and writes a caption list beside them. The frames are the
 * recording; a separate step stitches them into an animated GIF.
 *
 * Every helper returns what actually happened rather than asserting, so the
 * caller can record "broken" and carry on instead of dying on the first defect.
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://127.0.0.1:8008';
const REPO = '/Users/mohammed/work/Trust Data/new gondal backend/Backend code';
const OUT = path.join(REPO, 'docs/journey-recordings');

class Journey {
  constructor(role) {
    this.role = role;
    this.slug = role.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    this.dir = path.join(OUT, 'frames', this.slug);
    fs.mkdirSync(this.dir, { recursive: true });
    // A re-run must not interleave with the previous one's frames.
    for (const f of fs.readdirSync(this.dir)) fs.unlinkSync(path.join(this.dir, f));
    this.n = 0;
    this.captions = [];
    this.findings = [];
  }

  async open() {
    this.browser = await puppeteer.launch({
      executablePath: CHROME,
      headless: 'new',
      args: ['--window-size=1280,800', '--no-sandbox'],
    });
    this.page = await this.browser.newPage();
    await this.page.setViewport({ width: 1280, height: 800 });
    this.console = [];
    this.page.on('console', (m) => { if (m.type() === 'error') this.console.push(m.text()); });
    this.page.on('pageerror', (e) => this.console.push('pageerror: ' + e.message));
    return this;
  }

  /** One frame + one caption line. The caption is what makes the GIF readable. */
  async frame(caption) {
    this.n += 1;
    const slug = caption.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
    const name = String(this.n).padStart(3, '0') + '-' + slug + '.png';
    await this.page.screenshot({ path: path.join(this.dir, name) });
    this.captions.push({ n: this.n, file: name, caption, url: this.page.url() });
    return this.n;
  }

  async go(url, caption) {
    const target = url.startsWith('http') ? url : BASE + url;

    /*
     * A fragment-only move (`/x` → `/x#modal-y`) is a same-document navigation,
     * and puppeteer returns null rather than a response for it. That is not a
     * failure — it is how opening a :target modal works in this application, so
     * it must not be reported as status 0.
     */
    let res = null;
    let err = null;
    try {
      res = await this.page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30000 });
    } catch (e) {
      err = e.message;
    }
    await new Promise((r) => setTimeout(r, 200));
    const n = await this.frame(caption || ('open ' + url));
    return {
      status: res ? res.status() : (err ? 0 : 200),
      sameDocument: !res && !err,
      error: err,
      frame: n,
      url: this.page.url(),
    };
  }

  /** Visible page text, for asserting what the operator can actually read. */
  async text() {
    return this.page.evaluate(() => document.body.innerText);
  }

  async html() {
    return this.page.content();
  }

  /**
   * Does the page OFFER a way to do this — visibly?
   *
   * Visibility is the whole point. Every :target modal is present in the DOM at
   * all times, including its own submit button, so a naive text search finds
   * "Dispatch batch" on a page whose header offers no such action. That reports
   * a missing affordance as present, which is precisely the class of defect this
   * walk exists to find.
   */
  async canSee(label) {
    return this.page.evaluate((needle) => {
      const visible = (el) => {
        if (!el.offsetParent && getComputedStyle(el).position !== 'fixed') return false;
        for (let n = el; n; n = n.parentElement) {
          const cs = getComputedStyle(n);
          if (cs.display === 'none' || cs.visibility === 'hidden') return false;
        }
        return true;
      };
      return [...document.querySelectorAll('a,button,summary')].some(
        (e) => (e.innerText || '').trim().toLowerCase().includes(needle.toLowerCase()) && visible(e)
      );
    }, label);
  }

  async clickText(label) {
    const ok = await this.page.evaluate((needle) => {
      const els = [...document.querySelectorAll('a,button,summary')];
      const hit = els.find((e) => (e.innerText || '').trim().toLowerCase().includes(needle.toLowerCase()));
      if (!hit) return false;
      hit.click();
      return true;
    }, label);
    if (ok) await new Promise((r) => setTimeout(r, 350));
    return ok;
  }

  async fill(selector, value) {
    return this.page.evaluate((sel, val) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      el.focus();
      if (el.tagName === 'SELECT') {
        el.value = val;
      } else {
        el.value = val;
      }
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }, selector, value);
  }

  /** First option value of a select that is not the placeholder. */
  async firstOption(selector, skip = []) {
    return this.page.evaluate((sel, skipList) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const opt = [...el.options].find(
        (o) => o.value && o.value !== '' && !skipList.includes(o.value)
      );
      return opt ? opt.value : null;
    }, selector, skip);
  }

  /**
   * Submit a form and report where it landed and what it said.
   *
   * Clicks the LAST submit button unless one is named. That matters: a modal
   * with a secondary action puts it first — "Save & add another" sits before
   * "Record delivery" — and clicking the first one exercises a different
   * journey. Doing that once made a correctly-reopened form look like a modal
   * that refused to close.
   */
  async submit(formSelector, caption, buttonName = null) {
    const before = this.page.url();
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null),
      this.page.evaluate((sel, name) => {
        const form = document.querySelector(sel);
        if (!form) return false;
        const buttons = [...form.querySelectorAll('[type=submit]')];
        const btn = name
          ? buttons.find((b) => b.getAttribute('name') === name)
          : buttons[buttons.length - 1];
        if (btn) btn.click(); else form.submit();
        return true;
      }, formSelector, buttonName),
    ]);
    await new Promise((r) => setTimeout(r, 250));
    const n = await this.frame(caption);
    const body = await this.text();

    /*
     * Outcomes come from the alert boxes, not from a regex over the whole page.
     * Scanning the page matched a modal's own SUBTITLE — "A sale that would take
     * stock below zero is refused" — and reported a successful sale as a refusal.
     * The application says what happened in a .alert; ask that.
     */
    const alerts = await this.page.evaluate(() => {
      const read = (sel) => [...document.querySelectorAll(sel)]
        .map((e) => e.innerText.trim().replace(/\s+/g, ' ')).filter(Boolean);
      return { good: read('.alert.success, .alert.info'), bad: read('.alert.danger, .alert.warn') };
    });

    // Anything the browser itself refused to submit is not an application result.
    const blockedByBrowser = await this.page.evaluate(() =>
      [...document.querySelectorAll('input,select,textarea')].some((el) => el.willValidate && !el.checkValidity()));

    return {
      frame: n,
      from: before,
      to: this.page.url(),
      success: alerts.good[0] || null,
      error: alerts.bad[0] || null,
      blockedByBrowser,
      modalOpen: !!(await this.visibleModal()),
      status500: body.includes('Server Error') || body.includes('Whoops'),
    };
  }

  /**
   * Is a modal actually on screen?
   *
   * These modals open two ways: CSS `:target` when the URL carries the fragment,
   * and an `open` class when the server reopens one to show a validation error.
   * Checking only for `.open` reports every ordinary modal as closed, so ask the
   * browser what is visible instead of guessing from markup.
   */
  async visibleModal() {
    return this.page.evaluate(() => {
      const m = [...document.querySelectorAll('.modal')].find(
        (el) => getComputedStyle(el).display !== 'none'
      );
      return m ? m.id : null;
    });
  }

  extract(body, re) {
    const m = body.match(re);
    return m ? m[1].trim().slice(0, 200) : null;
  }

  /** Record a verdict against the log. */
  note(step, story, did, happened, verdict, frame) {
    this.findings.push({ step, story, did, happened, verdict, frame, url: this.page.url() });
    const mark = { works: '✅', broken: '❌', missing: '⚠️', refuses: '🛡️' }[verdict] || '•';
    console.log(`${mark} ${step} ${story} :: ${happened}`);
  }

  async close() {
    fs.writeFileSync(
      path.join(OUT, this.slug + '.captions.json'),
      JSON.stringify({ role: this.role, frames: this.captions, findings: this.findings, console: this.console }, null, 2)
    );
    if (this.browser) await this.browser.close();
  }
}

module.exports = { Journey, BASE, REPO, OUT };
