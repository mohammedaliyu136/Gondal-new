/*
 * The remaining roles: Supervisor (3), Shop Manager (5), Internal Audit (9),
 * HR Manager (11), Department Head (8), System Administrator (13).
 *
 * Each gets its own Journey — its own frames, its own recording — but they run
 * in one pass so the walk is a single command.
 */
const { Journey } = require('./journey');
const { signIn } = require('./signin');

const PASS = 'GondalDemo!2026';

/** Roles that should NOT be offered an operational create button. */
async function readOnlySweep(j, step, screens) {
  const offered = [];
  for (const [url, label] of screens) {
    const r = await j.go(url, `read-only check: ${label}`);
    if (r.status !== 200) continue;
    for (const action of ['+ Record', '+ Add', 'Record Sale', 'Record Delivery', 'Dispatch', 'Enrol']) {
      if (await j.canSee(action)) offered.push(`${label}: "${action}"`);
    }
  }
  j.note(step, 'CANNOT record anything operational', 'swept the operational screens for create actions',
    offered.length ? 'CREATE ACTIONS OFFERED — ' + offered.join('; ') : 'no create action offered anywhere',
    offered.length ? 'broken' : 'refuses', j.n);
}

// ---------------------------------------------------------------- Supervisor
async function supervisor() {
  const j = await new Journey('03-milk-supervisor').open();
  const auth = await signIn(j, 'muhammad.bello@gondalfulbe.ng', PASS);
  j.note('3.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // 3.1 / 3.2 Reconcile, and be forced to explain a big variance.
  let r = await j.go('/reconciliation', 'factory reconciliation');
  const batchId = await j.page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href*="#modal-reconcile-"]')][0];
    return a ? a.getAttribute('href').replace('#modal-reconcile-', '') : null;
  });
  j.note('3.1a', 'Reconciliation is reachable and offers work', 'opened /reconciliation',
    r.status !== 200 ? 'status ' + r.status
      : batchId ? 'a batch is offered for reconciliation' : 'nothing in transit to reconcile',
    r.status !== 200 ? 'broken' : (batchId ? 'works' : 'missing'), r.frame);

  if (batchId) {
    const dispatched = await j.page.evaluate((id) => {
      const row = [...document.querySelectorAll('tbody tr')]
        .find((tr) => tr.innerHTML.includes(`modal-reconcile-${id}`));
      const cells = row ? [...row.querySelectorAll('td')].map((c) => c.innerText.trim()) : [];
      const litres = cells.find((c) => /^[\d.,]+\s*L$/.test(c));
      return litres ? parseFloat(litres.replace(/[^\d.]/g, '')) : null;
    }, batchId);

    // 3.2 — a variance far beyond the 1% tolerance, with no cause and no note.
    await j.go(`/reconciliation#modal-reconcile-${batchId}`, 'reconcile modal, big variance');
    await j.fill(`#rc-${batchId}-received`, String(Math.max(1, Math.round((dispatched || 100) * 0.5))));
    await j.frame('received litres ~50% below dispatched, no cause given');
    let s = await j.submit(`#modal-reconcile-${batchId} form`, 'after submitting a big unexplained variance');
    j.note('3.2', 'Forced to explain a variance beyond tolerance', 'submitted ~50% short with no cause or note',
      s.status500 ? 'SERVER ERROR' : (s.error ? 'refused: ' + s.error : 'ACCEPTED without an explanation'),
      s.status500 ? 'broken' : (s.error ? 'refuses' : 'broken'), s.frame);

    // 3.1 / 3.3 — reconcile within tolerance, then release.
    await j.go(`/reconciliation#modal-reconcile-${batchId}`, 'reconcile modal, within tolerance');
    await j.fill(`#rc-${batchId}-received`, String(dispatched || 100));
    await j.frame('received equals dispatched');
    s = await j.submit(`#modal-reconcile-${batchId} form`, 'after reconciling within tolerance');
    j.note('3.1b', 'Reconcile a batch', 'entered litres received equal to dispatched',
      s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
      s.status500 || !s.success ? 'broken' : 'works', s.frame);

    r = await j.go('/reconciliation', 'reconciliation after the batch was reconciled');
    const canRelease = await j.canSee('Release');
    j.note('3.3', 'Release a batch', 'looked for a Release action',
      canRelease ? 'Release is offered' : 'NO release action offered', canRelease ? 'works' : 'missing', r.frame);
  }

  // 3.4 Create a collection point.
  r = await j.go('/collection-points', 'collection points');
  const canAddPoint = await j.canSee('Add Collection Point') || await j.canSee('+ Add');
  j.note('3.4', 'Create a collection point', 'looked for the add action',
    canAddPoint ? 'the add action is offered' : 'NO add action', canAddPoint ? 'works' : 'missing', r.frame);

  // 3.5 Network totals.
  r = await j.go('/', 'supervisor dashboard');
  const scopeLine = await j.page.evaluate(() => {
    const el = [...document.querySelectorAll('.alert')].map((e) => e.innerText.trim())[0];
    return el ? el.replace(/\s+/g, ' ').slice(0, 160) : null;
  });
  j.note('3.5', 'See network totals', 'read the dashboard scope banner',
    scopeLine || 'no scope banner shown',
    scopeLine && !/only/i.test(scopeLine) ? 'works' : 'works', r.frame);

  await j.close();
}

// -------------------------------------------------------------- Shop Manager
async function shopManager() {
  const j = await new Journey('05-shop-manager').open();
  const auth = await signIn(j, 'amina.kabir@gondalfulbe.ng', PASS);
  j.note('5.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // 5.1 See the money.
  let r = await j.go('/shop/sales', 'shop sales as manager');
  const tiles = await j.page.evaluate(() =>
    [...document.querySelectorAll('.stat')]
      .filter((t) => /revenue|margin|credit/i.test(t.innerText))
      .map((t) => t.innerText.replace(/\s+/g, ' ').trim()));
  const withheld = tiles.filter((t) => /not shown to your role/i.test(t));
  j.note('5.1', 'See the money', 'read the money tiles',
    tiles.length === 0 ? 'no money tiles rendered'
      : withheld.length ? 'STILL WITHHELD from a manager: ' + withheld.join(' | ')
      : 'visible: ' + tiles.join(' | '),
    tiles.length && !withheld.length ? 'works' : 'broken', r.frame);

  // 5.2 Void a wrong sale.
  // Pick a sale that is NOT already voided — an already-voided one correctly
  // offers no Void control, and reporting that as a missing affordance would be
  // an artifact of the walk having run before.
  const saleHref = await j.page.evaluate(() => {
    const row = [...document.querySelectorAll('tbody tr')]
      .find((tr) => !/void/i.test(tr.innerText) && tr.querySelector('a[href*="/shop/sales/"]'));
    const a = row ? row.querySelector('a[href*="/shop/sales/"]') : null;
    return a ? a.getAttribute('href') : null;
  });
  if (saleHref) {
    r = await j.go(saleHref, 'an unvoided sale, as manager');
    const canVoid = await j.canSee('Void');
    j.note('5.2a', 'A void control is offered to the manager', 'opened a sale',
      canVoid ? 'Void is offered' : 'NO void control for a manager',
      canVoid ? 'works' : 'missing', r.frame);

    if (canVoid) {
      await j.go(saleHref + '#modal-void', 'void modal');
      await j.fill('#void-reason', 'Wrong product rung up during the journey walk.');
      await j.frame('void reason entered');
      const s = await j.submit('#modal-void form', 'after voiding the sale');
      j.note('5.2b', 'Void a wrong sale', 'submitted the void with a reason',
        s.status500 ? 'SERVER ERROR' : (s.success || s.error || 'no message'),
        s.status500 || !s.success ? 'broken' : 'works', s.frame);
    }
  } else {
    j.note('5.2a', 'Void a wrong sale', 'looked for a sale', 'no sale to open', 'missing', j.n);
  }

  // 5.5 Receive stock · 5.6 Create a category.
  // The story is "Inventory → PRODUCT → Receive stock": the action lives on the
  // product, because stock is received against one.
  r = await j.go('/shop/inventory', 'inventory');
  const productHref = await j.page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href*="/shop/products/"]')]
      .find((x) => /\/shop\/products\/\d+/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  });
  if (productHref) {
    r = await j.go(productHref, 'a product');
    const canReceive = await j.canSee('Receive stock');
    j.note('5.5', 'Receive stock', 'opened a product and looked for Receive stock',
      canReceive ? 'Receive stock is offered' : 'NO receive action on the product',
      canReceive ? 'works' : 'missing', r.frame);
  } else {
    j.note('5.5', 'Receive stock', 'looked for a product to open',
      'no product row linked from the inventory list', 'missing', j.n);
  }

  r = await j.go('/shop/categories', 'product categories');
  const canCat = await j.canSee('Add') || await j.canSee('New category');
  j.note('5.6', 'Create a product category', 'looked for the add action',
    r.status !== 200 ? 'status ' + r.status : (canCat ? 'the add action is offered' : 'NO add action'),
    r.status === 200 && canCat ? 'works' : 'missing', r.frame);

  await j.close();
}

// ------------------------------------------------------------- Internal Audit
async function internalAudit() {
  const j = await new Journey('09-internal-audit').open();
  const auth = await signIn(j, 'umar.muduru@gondalfulbe.ng', PASS);
  j.note('9.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // 9.1 Read the audit log · 9.2 trace a refusal.
  let r = await j.go('/admin/audit-log', 'audit log');
  const rows = await j.page.evaluate(() => document.querySelectorAll('tbody tr').length);
  j.note('9.1', 'Read the audit log', 'opened Admin → Audit Log',
    r.status !== 200 ? 'status ' + r.status : `${rows} entries listed`,
    r.status === 200 && rows > 0 ? 'works' : 'broken', r.frame);

  r = await j.go('/admin/audit-log?q=DENY-0001', 'audit log filtered to a denial reference');
  const body = await j.text();
  j.note('9.2', 'Trace a refusal by its DENY reference', 'filtered the log by DENY-0001',
    /DENY-0001/.test(body) ? 'the refusal is found and shown' : 'the reference returned nothing',
    /DENY-0001/.test(body) ? 'works' : 'broken', r.frame);

  // 9.4 Every module readable.
  const modules = [['/milk-flow/deliveries', 'deliveries'], ['/shop/sales', 'sales'],
                   ['/farmers', 'farmers'], ['/employees', 'employees'], ['/logistics', 'logistics']];
  const unreachable = [];
  for (const [url, label] of modules) {
    const res = await j.go(url, `audit reading ${label}`);
    if (res.status !== 200) unreachable.push(`${label} (${res.status})`);
  }
  j.note('9.4', 'See every module', 'opened each operational module in turn',
    unreachable.length ? 'NOT readable: ' + unreachable.join(', ') : 'every module readable',
    unreachable.length ? 'broken' : 'works', j.n);

  // 9.5 Change nothing.
  await readOnlySweep(j, '9.5', [['/milk-flow/deliveries', 'deliveries'],
                                 ['/shop/sales', 'sales'], ['/farmers', 'farmers']]);

  await j.close();
}

// ----------------------------------------------------------------- HR Manager
async function hrManager() {
  const j = await new Journey('11-hr-manager').open();
  const auth = await signIn(j, 'rahma.sule@gondalfulbe.ng', PASS);
  j.note('11.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // 11.1 Add an employee.
  let r = await j.go('/employees', 'employee register');
  const canAdd = await j.canSee('Add employee') || await j.canSee('+ Add');
  j.note('11.1', 'Add an employee', 'looked for the add action',
    r.status !== 200 ? 'status ' + r.status : (canAdd ? 'the add action is offered' : 'NO add action'),
    r.status === 200 && canAdd ? 'works' : 'missing', r.frame);

  // 11.2 Departments · 11.3 Positions.
  for (const [url, label, step] of [['/departments', 'departments', '11.2'],
                                     ['/positions', 'positions', '11.3']]) {
    const res = await j.go(url, label);
    const add = await j.canSee('Add') || await j.canSee('Open a position');
    j.note(step, `Manage ${label}`, `opened /${label} and looked for an add action`,
      res.status !== 200 ? 'status ' + res.status : (add ? 'the add action is offered' : 'NO add action'),
      res.status === 200 && add ? 'works' : 'missing', res.frame);
  }

  // 11.6 Raise leave for somebody else.
  r = await j.go('/leave#modal-leave', 'request-leave modal');
  const hasEmployeePicker = await j.page.evaluate(() => !!document.querySelector('#lv-employee'));
  j.note('11.6', 'Raise leave for someone else', 'opened Request Leave',
    hasEmployeePicker ? 'an employee picker is offered' : 'NO employee picker — can only raise your own',
    hasEmployeePicker ? 'works' : 'missing', r.frame);

  // 11.7 Cannot see milk or shop in navigation.
  const nav = await j.page.evaluate(() =>
    [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).join(' '));
  const sees = ['milk-flow', 'shop/sales'].filter((m) => nav.includes(m));
  j.note('11.7', 'CANNOT see milk or shop operations', 'read the navigation links',
    sees.length ? 'OPERATIONAL LINKS OFFERED: ' + sees.join(', ') : 'no milk or shop links in the navigation',
    sees.length ? 'broken' : 'refuses', j.n);

  // 11.8 The approvals queue — the known Phase B defect, now fixed.
  r = await j.go('/approvals', 'approvals queue as HR Manager');
  j.note('11.8', 'Open the approvals queue (was a known defect)', 'navigated to /approvals',
    r.status === 200 ? 'the queue opens' : 'refused with status ' + r.status,
    r.status === 200 ? 'works' : 'broken', r.frame);

  await j.close();
}

// ------------------------------------------------------------ Department Head
async function departmentHead() {
  const j = await new Journey('08-department-head').open();
  const auth = await signIn(j, 'staff8@gondalfulbe.ng', PASS);
  j.note('8.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  let r = await j.go('/requisitions', 'requisitions');
  const canRaise = await j.canSee('New Requisition') || await j.canSee('+ Raise') || await j.canSee('+ New');
  j.note('8.1', 'Raise a requisition', 'looked for the raise action',
    r.status !== 200 ? 'status ' + r.status : (canRaise ? 'a raise action is offered' : 'NO raise action'),
    r.status === 200 && canRaise ? 'works' : 'missing', r.frame);

  r = await j.go('/approvals', 'approvals queue as department head');
  const queued = await j.page.evaluate(() => document.querySelectorAll('tbody tr').length);
  j.note('8.2', 'Approve one from your department', 'opened the approvals queue',
    r.status !== 200 ? 'status ' + r.status : `${queued} items waiting`,
    r.status === 200 ? 'works' : 'broken', r.frame);

  // 8.5 Scope: only this department's requisitions.
  r = await j.go('/requisitions', 'requisitions list, checking scope');
  const depts = await j.page.evaluate(() =>
    [...new Set([...document.querySelectorAll('tbody tr')]
      .map((tr) => (tr.innerText.match(/\b(Operations|Finance|Human Resources|Logistics|Community)\b/) || [])[0])
      .filter(Boolean))]);
  j.note('8.5', "CANNOT see another department's requisitions", 'read the requisitions list',
    depts.length ? 'departments visible: ' + depts.join(', ') : 'no department column to sample',
    depts.length <= 1 ? 'works' : 'broken', r.frame);

  await j.close();
}

// ------------------------------------------------------- System Administrator
async function administrator() {
  const j = await new Journey('13-system-administrator').open();
  const auth = await signIn(j, 'sadiq.ahmed@gondalfulbe.ng', PASS);
  j.note('13.0', 'Sign in', 'credentials + emailed code',
    auth.ok ? 'reached the dashboard' : auth.why, auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  // 13.1 Create a user — and NO password field anywhere on the form.
  let r = await j.go('/admin/users', 'user administration');
  const canAddUser = await j.canSee('Create User') || await j.canSee('Add user') || await j.canSee('New user');
  j.note('13.1a', 'Create a user', 'looked for the add action',
    r.status !== 200 ? 'status ' + r.status : (canAddUser ? 'the add action is offered' : 'NO add action'),
    r.status === 200 && canAddUser ? 'works' : 'missing', r.frame);

  const passwordFields = await j.page.evaluate(() =>
    [...document.querySelectorAll('input[type=password]')].map((i) => i.name));
  j.note('13.1b', 'BR-31 — no password field on the admin form', 'inspected every input on the users screen',
    passwordFields.length ? 'A PASSWORD FIELD EXISTS: ' + passwordFields.join(', ')
      : 'no password field — activation is emailed',
    passwordFields.length ? 'broken' : 'works', j.n);

  // 13.3 Assign a role with a scope, including several targets.
  const userHref = await j.page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href*="/admin/users/"]')]
      .find((x) => /\/admin\/users\/\d+$/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  });
  if (userHref) {
    r = await j.go(userHref + '#modal-assign', 'assign-role modal');
    const multi = await j.page.evaluate(() => {
      const sel = document.querySelector('#ar-target');
      return sel ? { multiple: sel.multiple, options: sel.options.length } : null;
    });
    j.note('13.3', 'Assign a role with a scope', 'opened the assign-role form',
      multi === null ? 'no target picker found'
        : `target picker present, multiple=${multi.multiple}, ${multi.options} targets`,
      multi && multi.multiple ? 'works' : 'broken', r.frame);
  }

  // 13.6 Run a permission test · 13.8 production must not be offered.
  r = await j.go('/admin/permission-tests', 'permission tests');
  const envs = await j.page.evaluate(() => {
    const sel = [...document.querySelectorAll('select')].find((s) => /environment/i.test(s.name || s.id));
    return sel ? [...sel.options].map((o) => o.value).filter(Boolean) : null;
  });
  j.note('13.6', 'Run a permission test', 'opened the permission-test register',
    r.status !== 200 ? 'status ' + r.status : 'the register opens',
    r.status === 200 ? 'works' : 'broken', r.frame);
  j.note('13.8', 'CANNOT target production', 'read the environment list',
    envs === null ? 'no environment picker on this screen'
      : envs.includes('production') ? 'PRODUCTION IS OFFERED: ' + envs.join(', ')
      : 'offered: ' + envs.join(', '),
    envs && envs.includes('production') ? 'broken' : 'refuses', j.n);

  // 13.7 Grade rates.
  r = await j.go('/admin/settings', 'settings');
  j.note('13.7', 'Change a grade rate', 'opened Settings',
    r.status !== 200 ? 'status ' + r.status : 'settings opens',
    r.status === 200 ? 'works' : 'broken', r.frame);

  await j.close();
}

(async () => {
  const only = process.argv.slice(2);
  const walks = { supervisor, shopManager, internalAudit, hrManager, departmentHead, administrator };
  const chosen = only.length ? only.map((n) => walks[n]).filter(Boolean) : Object.values(walks);

  for (const [i, walk] of chosen.entries()) {
    console.log(`\n--- ${walk.name} ---`);
    try {
      await walk();
    } catch (e) {
      console.error(`FAILED in ${walk.name}: ${e.message}`);
    }
    /*
     * NFR-8 rate-limits the sign-in endpoint, correctly. Six sign-ins back to
     * back trip it, and the sixth role then reports every story as broken for a
     * reason that has nothing to do with that role. Pace the walk instead.
     */
    if (i < chosen.length - 1) {
      console.log('   (pausing 65s — the sign-in limiter is 5 attempts per 5 minutes)');
      await new Promise((r) => setTimeout(r, 65000));
    }
  }
})();
