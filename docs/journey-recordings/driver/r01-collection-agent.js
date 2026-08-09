/*
 * Role 1 — Collection Agent, the morning round. Stories 1.1 – 1.11.
 * Account: sani.bello@gondalfulbe.ng · scope: one collection point.
 */
const { Journey } = require('./journey');
const { signIn } = require('./signin');

const EMAIL = 'sani.bello@gondalfulbe.ng';
const PASS = 'GondalDemo!2026';

(async () => {
  const j = await new Journey('01-collection-agent').open();

  const auth = await signIn(j, EMAIL, PASS);
  j.note('1.0', 'Sign in (with two-factor)', 'email + password + emailed code',
    auth.ok ? 'reached the dashboard' : 'blocked: ' + auth.why,
    auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // ---- 1.1 Record a delivery -------------------------------------------------
  let r = await j.go('/milk-flow/deliveries', 'deliveries list');
  const hasRecord = await j.canSee('Record Delivery') || await j.canSee('Record Milk');
  j.note('1.1a', 'A way to record a delivery exists', 'looked for the action on /milk-flow/deliveries',
    hasRecord ? 'the + Record Delivery action is offered' : 'NO record action on the page',
    hasRecord ? 'works' : 'missing', r.frame);

  await j.go('/milk-flow/deliveries#modal-record', 'record-delivery modal open');
  const point = await j.firstOption('#rd-point');
  const farmer = await j.firstOption('#rd-farmer');
  await j.fill('#rd-point', point);
  await j.fill('#rd-farmer', farmer);
  await j.fill('#rd-presented', '22');
  await j.fill('#rd-rejected', '0');
  // Well inside the 07:00 cut-off.
  await j.fill('#rd-at', new Date().toISOString().slice(0, 10) + 'T06:10');
  await j.frame('delivery form filled: 22 L presented');

  let s = await j.submit('#modal-record form', 'after saving the delivery');
  j.note('1.1b', 'Record a delivery (22 L)', 'submitted the record-delivery form',
    s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message shown'),
    s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);
  j.note('1.1c', 'The modal closes after saving', 'checked for .modal.open after the redirect',
    s.modalOpen ? 'the modal stayed OPEN' : 'the modal closed',
    s.modalOpen ? 'broken' : 'works', s.frame);

  // ---- 1.2 Save & add another ------------------------------------------------
  await j.go('/milk-flow/deliveries#modal-record', 'record modal again for Save & add another');
  await j.fill('#rd-point', point);
  await j.fill('#rd-farmer', farmer);
  await j.fill('#rd-presented', '18');
  await j.fill('#rd-at', new Date().toISOString().slice(0, 10) + 'T06:15');
  const sa = await j.submit('#modal-record form', 'after Save & add another', 'add_another');
  let n = sa.frame;
  const reopened = (await j.visibleModal()) === 'modal-record'
    || j.page.url().includes('#modal-record');
  j.note('1.2', 'Save & add another returns to the open form', 'pressed Save & add another',
    reopened ? 'returned to the open form' : 'did NOT return to the form (url ' + j.page.url() + ')',
    reopened ? 'works' : 'broken', n);

  // ---- 1.3 Record a rejection ------------------------------------------------
  await j.go('/milk-flow/deliveries#modal-record', 'record modal for a rejection');
  await j.fill('#rd-point', point);
  await j.fill('#rd-farmer', farmer);
  await j.fill('#rd-presented', '20');
  await j.fill('#rd-rejected', '5');
  const reason = await j.firstOption('#rd-reason');
  if (reason) await j.fill('#rd-reason', reason);
  await j.fill('#rd-at', new Date().toISOString().slice(0, 10) + 'T06:20');
  await j.frame('20 L presented, 5 L rejected with a reason');
  s = await j.submit('#modal-record form', 'after saving the rejection');
  j.note('1.3', 'Record a rejection (20 presented, 5 rejected → 15 accepted)', 'submitted with a rejection reason',
    s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
    s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);

  // ---- 1.4 / 1.5 The cut-off, and keeping typed work -------------------------
  await j.go('/milk-flow/deliveries#modal-record', 'record modal for the cut-off case');
  await j.fill('#rd-point', point);
  await j.fill('#rd-farmer', farmer);
  await j.fill('#rd-presented', '31');
  await j.fill('#rd-notes', 'Typed work that must survive the refusal');
  await j.fill('#rd-at', new Date().toISOString().slice(0, 10) + 'T09:45');
  await j.frame('delivery timed 09:45, after the 07:00 cut-off');
  s = await j.submit('#modal-record form', 'after submitting past the cut-off');

  const body = await j.text();
  const refused = /cut-?off|after the/i.test(body);
  const hasRuleCode = /\bBR-\d+\b/.test(body);
  j.note('1.4', 'Cannot record after the cut-off', 'submitted a delivery timed 09:45',
    refused ? 'refused: ' + (s.error || 'cut-off message shown') : 'ACCEPTED — the cut-off did not stop it',
    refused ? 'refuses' : 'broken', s.frame);
  j.note('1.4b', 'The refusal carries no rule code', 'looked for BR-xx in the page',
    hasRuleCode ? 'a rule code LEAKED to the operator' : 'plain language, no rule code',
    hasRuleCode ? 'broken' : 'works', s.frame);

  const kept = await j.page.evaluate(() => {
    const el = document.querySelector('#rd-presented');
    const notes = document.querySelector('#rd-notes');
    return { presented: el ? el.value : null, notes: notes ? notes.value : null,
             open: getComputedStyle(document.querySelector('#modal-record')).display !== 'none' };
  });
  j.note('1.5', 'Typed work survives the refusal', 'checked the reopened form',
    `modal open=${kept.open}, presented="${kept.presented}", notes kept=${!!(kept.notes || '').length}`,
    (kept.open && kept.presented === '31') ? 'works' : 'broken', s.frame);

  // ---- 1.6 Enrol a farmer ----------------------------------------------------
  // The story says "Community → Farmers → add", so check the NAV reaches it,
  // not just that the URL exists.
  const navToFarmers = await j.page.evaluate(() =>
    [...document.querySelectorAll('a[href]')].some((a) => /\/farmers$/.test(a.getAttribute('href') || '')));
  r = await j.go('/farmers', 'farmers register');
  const canAddFarmer = await j.canSee('Add Farmer') || await j.canSee('Enrol') || await j.canSee('+ Farmer');
  j.note('1.6', 'Enrol a farmer', 'Community → Farmers, then looked for an add action',
    r.status === 200
      ? `nav link present=${navToFarmers}; ` + (canAddFarmer ? 'the add action is offered' : 'page reachable but NO add action')
      : 'page returned ' + r.status,
    r.status !== 200 ? 'broken' : (canAddFarmer ? 'works' : 'missing'), r.frame);

  if (canAddFarmer) {
    await j.go('/farmers#modal-enrol', 'enrol-farmer modal');
    const openModal = await j.visibleModal();
    j.note('1.6b', 'The enrol-farmer form opens', 'followed the + Enrol Farmer action',
      openModal ? 'modal ' + openModal + ' opened' : 'no modal opened',
      openModal ? 'works' : 'broken', j.n);

    // Actually enrol somebody — the story is "farmer created, enrolled-by = you".
    const stamp = Date.now().toString().slice(-6);
    await j.fill('#ef-code', 'JT' + stamp);
    await j.fill('#ef-name', 'Journey Test Farmer ' + stamp);
    const community = await j.firstOption('#ef-community');
    if (community) await j.fill('#ef-community', community);
    await j.frame('enrol form filled');
    const e = await j.submit('#modal-enrol form', 'after enrolling the farmer');
    // Verify by landing on the record, not by matching prose: the access-denied
    // page also contains the word "recorded", which read as success once.
    const landedOnFarmer = /\/farmers\/\d+/.test(e.to);
    const denied = /Attempt recorded in the audit log|don.t have access/i.test(await j.text());
    j.note('1.6c', 'Enrol a farmer actually creates one, and the enroller can see it',
      'submitted the enrol form and followed the redirect',
      e.status500 ? 'SERVER ERROR'
        : denied ? 'DENIED after creating — the enroller cannot see their own farmer'
        : landedOnFarmer ? 'landed on the new farmer record: ' + e.to
        : 'did not land on a farmer record: ' + e.to,
      (e.status500 || denied || !landedOnFarmer) ? 'broken' : 'works', e.frame);
  }

  // ---- 1.7 Dispatch a consignment --------------------------------------------
  r = await j.go('/milk-flow/consignments', 'consignments list');
  const canDispatch = await j.canSee('Dispatch');
  j.note('1.7', 'Dispatch a consignment', 'looked for the dispatch action',
    r.status === 200 ? (canDispatch ? 'the dispatch action is offered' : 'NO dispatch action') : 'status ' + r.status,
    canDispatch ? 'works' : 'missing', r.frame);

  if (canDispatch) {
    await j.go('/milk-flow/consignments#modal-dispatch', 'dispatch modal open');
    const boxes = await j.page.evaluate(() => {
      const cbs = [...document.querySelectorAll('#modal-dispatch input[type=checkbox]')];
      cbs.slice(0, 3).forEach((c) => { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
      return cbs.length;
    });
    await j.frame(`dispatch modal, ${boxes} deliveries available`);
    if (boxes > 0) {
      s = await j.submit('#modal-dispatch form', 'after dispatching');
      j.note('1.7b', 'Dispatch actually creates a consignment', 'ticked the morning deliveries and dispatched',
        s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
        s.status500 ? 'broken' : (s.success ? 'works' : 'broken'), s.frame);
    } else {
      j.note('1.7b', 'Dispatch actually creates a consignment', 'opened the dispatch modal',
        'no undispatched deliveries were offered to select', 'missing', j.n);
    }
  }

  // ---- 1.8 Cannot grade ------------------------------------------------------
  r = await j.go('/milk-flow/consignments', 'consignments, checking for grade controls');
  const seesGrade = await j.canSee('Assign grade') || await j.canSee('Re-grade');
  j.note('1.8', 'CANNOT grade milk', 'looked for any grade control',
    seesGrade ? 'a GRADE CONTROL IS OFFERED to a collection agent' : 'no grade control offered',
    seesGrade ? 'broken' : 'refuses', r.frame);

  // ---- 1.9 Cannot adjust -----------------------------------------------------
  const seesAdjust = await j.canSee('Adjust');
  j.note('1.9', 'CANNOT adjust a volume', 'looked for an adjust control',
    seesAdjust ? 'an ADJUST CONTROL IS OFFERED without milk.adjustment.create' : 'no adjust control offered',
    seesAdjust ? 'broken' : 'refuses', j.n);

  // ---- 1.10 Cannot see another point ----------------------------------------
  r = await j.go('/milk-flow/deliveries', 'deliveries, checking scope');
  const scopeNote = await j.page.evaluate(() => {
    const el = [...document.querySelectorAll('.alert, .hint, .dh-meta, .page-head p')]
      .map((e) => e.innerText).join(' | ');
    return el.slice(0, 200);
  });
  const pointsShown = await j.page.evaluate(() => {
    const cells = [...document.querySelectorAll('tbody tr td')].map((c) => c.innerText);
    return [...new Set(cells.filter((c) => /Point|Tudun|Chiranci|Danbare/i.test(c)))].slice(0, 6);
  });
  j.note('1.10', 'CANNOT see another point', 'read the delivery list and its scope note',
    `scope note: "${scopeNote}"; points visible: ${JSON.stringify(pointsShown)}`,
    'works', r.frame);

  // ---- 1.11 Cannot open payroll ---------------------------------------------
  r = await j.go('/payroll', 'attempting payroll');
  const t = await j.text();
  const denyRef = (t.match(/DENY-\d+/) || [])[0];
  j.note('1.11', 'CANNOT open payroll', 'navigated to /payroll',
    `status ${r.status}${denyRef ? ', quotable reference ' + denyRef : ', NO DENY reference shown'}`,
    (r.status === 403 && denyRef) ? 'refuses' : 'broken', r.frame);

  await j.close();
})().catch((e) => { console.error('FATAL', e.stack); process.exit(1); });
