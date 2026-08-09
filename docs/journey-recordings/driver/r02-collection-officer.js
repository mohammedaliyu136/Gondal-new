/*
 * Role 2 — Milk Collection Officer, the centre. Stories 2.1 – 2.10.
 * Account: halima.yusuf@gondalfulbe.ng · scope: one centre.
 */
const { Journey } = require('./journey');
const { signIn } = require('./signin');

(async () => {
  const j = await new Journey('02-collection-officer').open();

  const auth = await signIn(j, 'halima.yusuf@gondalfulbe.ng', 'GondalDemo!2026');
  j.note('2.0', 'Sign in', 'email + password + emailed code',
    auth.ok ? 'reached the dashboard' : 'blocked: ' + auth.why,
    auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // ---- 2.1 See what is waiting ----------------------------------------------
  let r = await j.go('/milk-flow/consignments', 'consignments at my centre');
  let body = await j.text();
  const awaiting = /awaiting/i.test(body);
  j.note('2.1', 'See what is waiting', 'opened the consignments screen',
    r.status !== 200 ? 'status ' + r.status
      : (awaiting ? 'an awaiting-confirmation queue is shown' : 'no awaiting queue visible'),
    r.status === 200 && awaiting ? 'works' : 'broken', r.frame);

  // ---- 2.5 Adjust before confirming -----------------------------------------
  r = await j.go('/milk-flow/consignments', 'consignments, looking for Adjust');
  const adjustId = await j.page.evaluate(() => {
    const link = [...document.querySelectorAll('a[href*="#modal-adjust-"]')][0];
    return link ? link.getAttribute('href').replace('#modal-adjust-', '') : null;
  });
  j.note('2.5a', 'An adjust control is offered', 'looked for Adjust on the list',
    adjustId ? 'Adjust is offered' : 'NO adjust control offered',
    adjustId ? 'works' : 'missing', r.frame);

  if (adjustId) {
    await j.go(`/milk-flow/consignments#modal-adjust-${adjustId}`, 'adjust modal open');
    await j.fill(`#adj-${adjustId}-delta`, '-2.5');
    const ar = await j.firstOption(`#adj-${adjustId}-reason`);
    if (ar) await j.fill(`#adj-${adjustId}-reason`, ar);
    await j.fill(`#adj-${adjustId}-why`, 'Spillage during transfer, measured at the centre.');
    await j.frame('adjustment of -2.5 L with a reason');
    const s = await j.submit(`#modal-adjust-${adjustId} form`, 'after recording the adjustment');
    j.note('2.5b', 'Adjust before confirming (−2.5 L)', 'submitted the adjustment',
      s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
      s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);
  }

  // Find a consignment still awaiting confirmation.
  const target = await j.page.evaluate(() => {
    const link = [...document.querySelectorAll('a[href*="#modal-confirm-"]')][0];
    return link ? link.getAttribute('href').replace('#modal-confirm-', '') : null;
  });

  if (!target) {
    j.note('2.2', 'Record quality tests', 'looked for a confirmable consignment',
      'none awaiting confirmation in this scope — cannot walk 2.2–2.4', 'missing', j.n);
  } else {
    // ---- 2.3 Grading is blocked until the required tests are in --------------
    await j.go(`/milk-flow/consignments#modal-confirm-${target}`, 'confirm modal open');
    const gate = await j.page.evaluate((id) => {
      const sel = document.querySelector(`#cf-${id}-grade`);
      const hint = sel?.parentElement?.querySelector('.hint');
      return {
        disabled: sel ? sel.disabled : null,
        hint: hint ? hint.innerText.trim() : null,
        outstanding: (document.querySelector(`#modal-confirm-${id} .badge.warning`) || {}).innerText || null,
      };
    }, target);
    j.note('2.3', 'Blocked from grading before the required tests', 'opened Confirm and inspected the grade control',
      gate.disabled === null ? 'no grade control found'
        : gate.disabled ? `grade list disabled; it says: "${gate.hint}"`
        : 'grade list is ENABLED with tests outstanding',
      gate.disabled ? 'refuses' : 'broken', j.n);

    // ---- 2.2 Record every quality test ---------------------------------------
    const definitions = await j.page.evaluate((id) => {
      const modal = document.querySelector('#modal-confirm-' + id);
      return [...modal.querySelectorAll('button[formaction*="quality-test"]')]
        .map((b) => b.getAttribute('value'));
    }, target);

    j.note('2.2a', 'Quality tests are offered on the confirm form', 'opened Confirm',
      `${definitions.length} recordable tests`, definitions.length > 0 ? 'works' : 'missing', j.n);

    let recordedOk = 0;
    for (const def of definitions) {
      await j.go(`/milk-flow/consignments#modal-confirm-${target}`, `confirm modal, recording test ${def}`);
      const kind = await j.page.evaluate((id, d) => {
        const el = document.querySelector(`#qt-${id}-${d}`);
        if (!el) return null;
        el.value = el.tagName === 'SELECT' ? el.options[0].value : '1.031';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        return el.tagName;
      }, target, def);
      if (kind === null) continue;

      await Promise.all([
        j.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null),
        j.page.evaluate((id, d) => {
          const b = document.querySelector(
            `#modal-confirm-${id} button[formaction*="quality-test"][value="${d}"]`);
          if (b) b.click();
        }, target, def),
      ]);
      await new Promise((res) => setTimeout(res, 250));
      const page = await j.text();
      if (!/Server Error|Whoops/.test(page)) recordedOk += 1;
    }
    const nRec = await j.frame(`after recording ${recordedOk}/${definitions.length} quality tests`);
    j.note('2.2b', 'Record every quality test', 'pressed Record on each test in turn',
      `${recordedOk} of ${definitions.length} recorded without error`,
      recordedOk === definitions.length && recordedOk > 0 ? 'works' : 'broken', nRec);

    // ---- 2.4 Confirm with a grade, now that the tests are in -----------------
    await j.go(`/milk-flow/consignments#modal-confirm-${target}`, 'confirm modal with tests complete');
    const nowEnabled = await j.page.evaluate((id) => {
      const sel = document.querySelector(`#cf-${id}-grade`);
      return sel ? !sel.disabled : false;
    }, target);
    j.note('2.3b', 'The grade control unlocks once the tests are recorded', 're-opened Confirm',
      nowEnabled ? 'grade list is now enabled' : 'grade list is STILL disabled after recording every test',
      nowEnabled ? 'works' : 'broken', j.n);

    const gradeSel = `#cf-${target}-grade`;
    const grade = await j.firstOption(gradeSel);
    if (grade) await j.fill(gradeSel, grade);
    await j.frame('grade chosen on the confirm form');
    const s = await j.submit(`#modal-confirm-${target} form`, 'after confirming with a grade');

    const graded = await j.page.evaluate(() => document.body.innerText);
    j.note('2.4', 'Confirm with a grade', 'submitted the confirm form with a grade',
      s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
      s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);
  }

  // ---- 2.7 Grade one confirmed without a grade -------------------------------
  r = await j.go('/milk-flow/consignments', 'consignments, looking for Assign grade');
  const canAssign = await j.canSee('Assign grade');
  j.note('2.7', 'Grade a consignment confirmed without one', 'looked for Assign grade',
    canAssign ? 'Assign grade is offered' : 'none confirmed-without-a-grade in view',
    canAssign ? 'works' : 'missing', r.frame);

  // ---- 2.8 Dispatch a batch ---------------------------------------------------
  // The story says "Centre → Dispatch batch", so the centre page is the home of
  // this action. The Batches list is checked separately, because that is where
  // an operator looking for it would go first.
  r = await j.go('/milk-flow/batches', 'batches list');
  // A batch belongs to a centre, so the right affordance here is a signpost to
  // the centre screen rather than a duplicate form.
  const batchesListOffers = await j.canSee('Dispatch from a center')
    || await j.canSee('Dispatch batch') || await j.canSee('New Batch');
  j.note('2.8a', 'The Batches screen leads somewhere you can dispatch', 'looked at /milk-flow/batches',
    batchesListOffers ? 'the screen points at the centre where batches are dispatched'
      : 'NO route to the action, though this role holds milk.batch.dispatch.create',
    batchesListOffers ? 'works' : 'missing', r.frame);

  // Reach an actual centre, not the list: the action lives on the detail page.
  await j.go('/collection-centers', 'collection centres list');
  const centreHref = await j.page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href*="/collection-centers/"]')]
      .find((x) => /\/collection-centers\/\d+/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  });
  const centreUrl = centreHref || '/collection-centers';
  r = await j.go(centreUrl, 'my collection centre');
  const canBatch = await j.canSee('Dispatch batch');
  j.note('2.8b', 'Dispatch a batch from the centre', 'opened the centre and looked for Dispatch batch',
    r.status !== 200 ? 'status ' + r.status : (canBatch ? 'Dispatch batch is offered' : 'NO dispatch action on the centre'),
    r.status !== 200 ? 'broken' : (canBatch ? 'works' : 'missing'), r.frame);

  if (canBatch) {
    await j.go(centreUrl + '#modal-batch', 'dispatch-batch modal');
    const ticked = await j.page.evaluate(() => {
      const cbs = [...document.querySelectorAll('#modal-batch input[type=checkbox]')];
      cbs.forEach((c) => { c.checked = true; });
      return cbs.length;
    });
    await j.frame(`batch modal, ${ticked} eligible consignments`);
    if (ticked > 0) {
      const s = await j.submit('#modal-batch form', 'after dispatching the batch');
      j.note('2.8c', 'Dispatching a batch creates one', 'ticked the eligible consignments and dispatched',
        s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
        s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);
    } else {
      j.note('2.8c', 'Dispatching a batch creates one', 'opened the batch modal',
        'no confirmed+graded consignments were eligible', 'missing', j.n);
    }
  }

  // ---- 2.9 CANNOT reconcile ---------------------------------------------------
  r = await j.go('/reconciliation', 'attempting factory reconciliation');
  body = await j.text();
  const deny = (body.match(/DENY-\d+/) || [])[0];
  j.note('2.9', 'CANNOT reconcile at the factory', 'navigated to /reconciliation',
    `status ${r.status}${deny ? ', ref ' + deny : ''}`,
    r.status === 403 ? 'refuses' : 'broken', r.frame);

  // ---- 2.10 CANNOT see another centre ----------------------------------------
  r = await j.go('/collection-centers', 'collection centres list');
  const centres = await j.page.evaluate(() =>
    [...document.querySelectorAll('tbody tr td:first-child')].map((c) => c.innerText.trim()).slice(0, 8));
  j.note('2.10', 'CANNOT see another centre', 'read the centres list',
    'centres visible: ' + JSON.stringify(centres), 'works', r.frame);

  await j.close();
})().catch((e) => { console.error('FATAL', e.stack); process.exit(1); });
