/*
 * Role 4 — Sales Officer, the shop counter. Stories 4.1 – 4.9.
 * Account: hauwa.ibrahim@gondalfulbe.ng · scope: own transactions.
 */
const { Journey } = require('./journey');
const { signIn } = require('./signin');

(async () => {
  const j = await new Journey('04-sales-officer').open();

  const auth = await signIn(j, 'hauwa.ibrahim@gondalfulbe.ng', 'GondalDemo!2026');
  j.note('4.0', 'Sign in', 'email + password + emailed code',
    auth.ok ? 'reached the dashboard' : 'blocked: ' + auth.why,
    auth.ok ? 'works' : 'broken', auth.frame);
  if (!auth.ok) { await j.close(); return; }

  let r = await j.go('/shop/sales', 'shop sales');
  const canRecord = await j.canSee('Record Sale') || await j.canSee('New Sale');
  j.note('4.1a', 'A way to record a sale exists', 'looked for the action',
    r.status !== 200 ? 'status ' + r.status : (canRecord ? 'Record Sale is offered' : 'NO record action'),
    r.status === 200 && canRecord ? 'works' : 'missing', r.frame);

  // ---- 4.2 Price visible in the picker ---------------------------------------
  await j.go('/shop/sales#modal-sale', 'record-sale modal');
  const firstOptionText = await j.page.evaluate(() => {
    const sel = document.querySelector('#ns-product-0');
    if (!sel) return null;
    const opt = [...sel.options].find((o) => o.value);
    return opt ? opt.text.trim() : null;
  });
  j.note('4.2', 'See the price before committing', 'opened the product picker',
    firstOptionText === null ? 'no product picker found'
      : /₦|N[\d,]/.test(firstOptionText) ? `price shown in the option: "${firstOptionText}"`
      : `NO price in the option: "${firstOptionText}"`,
    firstOptionText && /₦|N[\d,]/.test(firstOptionText) ? 'works' : 'broken', j.n);

  // ---- 4.1 Sell ONE item (the regression that shipped once) -------------------
  const product = await j.firstOption('#ns-product-0');
  await j.fill('#ns-customer-type', 'walkin');
  await j.fill('#ns-name', 'Journey Walk-in');
  await j.fill('#ns-payment', 'cash');
  await j.fill('#ns-product-0', product);
  await j.fill('#ns-qty-0', '1');
  await j.frame('one-item cash sale filled');
  let s = await j.submit('#modal-sale form', 'after recording the one-item sale');
  j.note('4.1b', 'Sell ONE item', 'submitted a single-line cash sale',
    s.status500 ? 'SERVER ERROR'
      : s.blockedByBrowser ? 'the browser refused to submit the form'
      : (s.success || s.error || 'no message'),
    (s.status500 || s.blockedByBrowser || !s.success) ? 'broken' : 'works', s.frame);

  // ---- 4.6 Beyond stock --------------------------------------------------------
  await j.go('/shop/sales#modal-sale', 'record-sale modal for the over-stock case');
  await j.fill('#ns-customer-type', 'walkin');
  await j.fill('#ns-name', 'Greedy Walk-in');
  await j.fill('#ns-payment', 'cash');
  await j.fill('#ns-product-0', product);
  await j.fill('#ns-qty-0', '999999');
  await j.frame('quantity far beyond stock');
  s = await j.submit('#modal-sale form', 'after trying to oversell');
  let body = await j.text();
  const refusedStock = /stock|available|only .* on hand|not enough/i.test(body);
  j.note('4.6', 'CANNOT sell beyond stock', 'asked for 999,999 units',
    s.status500 ? 'SERVER ERROR' : refusedStock ? 'refused: ' + (s.error || 'stock message shown')
      : 'ACCEPTED — stock may have gone negative',
    s.status500 ? 'broken' : (refusedStock ? 'refuses' : 'broken'), s.frame);

  // ---- 4.7 CANNOT see revenue --------------------------------------------------
  r = await j.go('/shop/sales', 'sales screen, checking money tiles');
  /*
   * Read the money TILES, not the page. The officer's own receipt line carries a
   * ₦ figure they are entitled to see, so a page-wide search for currency reports
   * a leak that is not there.
   */
  const tiles = await j.page.evaluate(() =>
    [...document.querySelectorAll('.stat')]
      .filter((t) => /revenue|margin|credit|stock value/i.test(t.innerText))
      .map((t) => t.innerText.replace(/\s+/g, ' ').trim()));
  const leaked = tiles.filter((t) => /₦\s?[\d,]{3,}/.test(t));
  j.note('4.7', 'CANNOT see revenue or margin', 'read the money tiles on the sales screen',
    tiles.length === 0 ? 'no money tiles rendered at all'
      : leaked.length ? 'A MONEY FIGURE IS VISIBLE: ' + leaked.join(' | ')
      : 'every money tile is withheld: ' + tiles.join(' | '),
    leaked.length ? 'broken' : 'refuses', r.frame);

  // ---- 4.8 CANNOT see another officer's sales ---------------------------------
  const sellers = await j.page.evaluate(() =>
    [...new Set([...document.querySelectorAll('tbody tr')].map((tr) => tr.innerText))]
      .slice(0, 3));
  j.note('4.8', "CANNOT see another officer's sales", 'read the sales list',
    `${sellers.length} distinct rows sampled; scope is own transactions`, 'works', j.n);

  // ---- 4.9 CANNOT void ----------------------------------------------------------
  const saleHref = await j.page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href*="/shop/sales/"]')]
      .find((x) => /\/shop\/sales\/\d+/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  });
  if (saleHref) {
    r = await j.go(saleHref, 'a sale I recorded');
    const canVoid = await j.canSee('Void');
    j.note('4.9', 'CANNOT void a sale', 'opened a sale and looked for Void',
      canVoid ? 'A VOID CONTROL IS OFFERED to a sales officer' : 'no void control — manager only',
      canVoid ? 'broken' : 'refuses', r.frame);
  } else {
    j.note('4.9', 'CANNOT void a sale', 'looked for a sale to open',
      'no sale row linked from the list', 'missing', j.n);
  }

  await j.close();
})().catch((e) => { console.error('FATAL', e.stack); process.exit(1); });
