// Браузерные проверки интерактивных элементов (нужен Playwright + Chromium). Запуск: bash tests/e2e.sh
const { chromium } = require('playwright');
const base = process.env.E2E_BASE, user = process.env.E2E_USER || 'admin', pass = process.env.E2E_PASS || 'demo-pass-123';
let pass_n = 0, fail_n = 0;
const ok = (c, m) => { c ? pass_n++ : fail_n++; console.log((c ? '  ✔ ' : '  ✘ ') + m); };

(async () => {
  const b = await chromium.launch({ executablePath: process.env.E2E_CHROMIUM || undefined }).catch(() => chromium.launch());
  const ctx = await b.newContext({ viewport: { width: 1360, height: 900 } });
  const pg = await ctx.newPage();
  const errors = []; pg.on('pageerror', (e) => errors.push(pg.url() + ' ' + e.message));
  await pg.goto(base + '/admin/index.php');
  await pg.fill('input[name=login]', user); await pg.fill('input[name=password]', pass); await pg.click('button');
  const dialogOpen = () => pg.evaluate(() => document.getElementById('confirm').open);

  console.log('\n== Подтверждения');
  await pg.goto(base + '/admin/index.php?p=broadcast&id=2');
  await pg.click('input[name=mode][value=now]');
  ok((await pg.textContent('[data-mode-label]')).includes('Отправить сейчас'), 'подпись главной кнопки следует за режимом');
  await pg.click('[data-mode-label]');
  ok(await dialogOpen(), 'отправка «сейчас» требует подтверждения с числом получателей');
  ok((await pg.textContent('#cf-text')).match(/\d+ подписчикам/) !== null, 'в диалоге указано число получателей');
  await pg.click('#confirm button[value=cancel]');
  await pg.waitForTimeout(300);
  ok(pg.url().includes('p=broadcast&id=2') && !(await pg.content()).includes('Рассылка запущена'), 'отмена в диалоге не отправляет рассылку');
  await pg.goto(base + '/admin/index.php?p=flows');
  await pg.click('button[data-confirm]');
  ok(await dialogOpen(), 'удаление требует подтверждения (диалог, а не alert)');
  await pg.keyboard.press('Escape');
  ok(!(await dialogOpen()), 'Esc закрывает диалог');

  console.log('\n== Палитра команд');
  await pg.goto(base + '/admin/index.php?p=dashboard');
  await pg.keyboard.press('Control+k');
  ok(await pg.evaluate(() => document.getElementById('palette').open), 'Ctrl+K открывает палитру');
  await pg.keyboard.type('рассыл');
  const first = await pg.textContent('#pal-list li[aria-selected=true]');
  ok(first.includes('рассыл') || first.includes('Рассыл'), 'поиск фильтрует команды');
  await Promise.all([pg.waitForURL(/p=broadcasts/), pg.keyboard.press('Enter')]).catch(() => {});
  ok(pg.url().includes('p=broadcasts'), 'Enter переходит в раздел');
  await pg.keyboard.press('/');
  ok(await pg.evaluate(() => document.getElementById('palette').open), '«/» тоже открывает палитру');
  await pg.keyboard.type('Ann'); await pg.waitForTimeout(150);
  ok((await pg.textContent('#pal-list')).includes('Найти подписчика'), 'ввод имени предлагает поиск подписчика');
  await pg.keyboard.press('Escape');

  console.log('\n== Таблицы и панели');
  await pg.goto(base + '/admin/index.php?p=users');
  ok(!(await pg.isVisible('#bulkbar')), 'панель массовых действий скрыта без выбора');
  await pg.check('input[name="ids[]"] >> nth=0'); await pg.check('input[name="ids[]"] >> nth=1');
  ok(await pg.isVisible('#bulkbar') && (await pg.textContent('#bulkn')) === '2', 'панель появляется и считает выбранных');
  await pg.check('#checkall');
  ok(Number(await pg.textContent('#bulkn')) > 2, '«выбрать все» отмечает строки страницы');

  console.log('\n== Цепочка и редактор');
  await pg.goto(base + '/admin/index.php?p=flow&id=1#s2');
  await pg.waitForTimeout(300);
  ok(await pg.evaluate(() => document.getElementById('s2').open), 'якорь #sN раскрывает нужный шаг');
  await pg.fill('#s2 textarea[name=body]', '**жирно** и _курсив_'); await pg.waitForTimeout(600);
  ok((await pg.innerHTML('#s2 .bubble-text')).includes('<b>жирно</b>'), 'живой предпросмотр markdown');
  const saveBtn = await pg.$('#s2 [data-save]');
  await pg.focus('#s2 textarea[name=body]'); await pg.keyboard.press('Control+s'); await pg.waitForLoadState(); await pg.waitForTimeout(400);
  ok((await pg.content()).includes('Шаг 2 сохранён'), 'Ctrl+S сохраняет форму шага');

  console.log('\n== Адаптивность');
  const m = await b.newContext({ viewport: { width: 390, height: 800 } });
  const mp = await m.newPage();
  await mp.goto(base + '/admin/index.php'); await mp.fill('input[name=login]', user); await mp.fill('input[name=password]', pass); await mp.click('button');
  await mp.goto(base + '/admin/index.php?p=dashboard');
  ok(await mp.isVisible('#navtoggle') && !(await mp.evaluate(() => document.getElementById('side').classList.contains('open'))), 'на телефоне меню скрыто, есть бургер');
  await mp.click('#navtoggle');
  ok(await mp.evaluate(() => document.getElementById('side').classList.contains('open')), 'бургер открывает меню');
  await mp.click('#scrim', { position: { x: 360, y: 400 } });
  ok(!(await mp.evaluate(() => document.getElementById('side').classList.contains('open'))), 'клик по затемнению закрывает меню');
  ok(await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'нет горизонтальной прокрутки страницы на 390px');
  for (const u of ['users', 'flows', 'broadcasts', 'team', 'settings']) {
    await mp.goto(base + '/admin/index.php?p=' + u);
    ok(await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `p=${u}: нет горизонтальной прокрутки на 390px`);
  }

  ok(errors.length === 0, 'нет JS-ошибок на всех страницах' + (errors.length ? ': ' + errors.join(' ; ') : ''));
  console.log(`\nПройдено: ${pass_n}, провалено: ${fail_n}`);
  await b.close();
  process.exit(fail_n ? 1 : 0);
})();
