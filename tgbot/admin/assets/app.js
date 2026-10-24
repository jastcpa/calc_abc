// Редактор сообщений: тулбар markdown, живой предпросмотр, конструктор кнопок.
(function () {
  const csrf = document.querySelector('meta[name=csrf]')?.content || '';
  const groups = JSON.parse(document.querySelector('meta[name=groups]')?.content || '[]');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  document.querySelectorAll('.editor').forEach(initEditor);

  function initEditor(root) {
    const ta = root.querySelector('textarea[name=body]');
    const img = root.querySelector('input[name=image]');
    const prev = root.querySelector('.bubble-text');
    const bubbleImg = root.querySelector('.bubble-img');
    const bubbleBtns = root.querySelector('.bubble-btns');
    const counter = root.querySelector('.counter');
    const hidden = root.querySelector('input[name=buttons]');
    const wrap = root.querySelector('.btn-rows');
    let rows = [];
    try { rows = JSON.parse(hidden.value || '[]'); } catch (e) { rows = []; }
    if (!Array.isArray(rows)) rows = [];

    // --- тулбар ---
    root.querySelectorAll('[data-wrap]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      const [l, r] = b.dataset.wrap.split('|');
      const s = ta.selectionStart, en = ta.selectionEnd;
      const sel = ta.value.slice(s, en) || b.dataset.ph || 'текст';
      ta.setRangeText(l + sel + (r ?? l), s, en, 'select');
      ta.focus(); refresh();
    }));
    root.querySelector('[data-link]')?.addEventListener('click', (e) => {
      e.preventDefault();
      const url = prompt('Ссылка (https://...)', 'https://');
      if (!url) return;
      const s = ta.selectionStart, en = ta.selectionEnd;
      const sel = ta.value.slice(s, en) || 'ссылка';
      ta.setRangeText('[' + sel + '](' + url + ')', s, en, 'end');
      ta.focus(); refresh();
    });
    root.querySelectorAll('[data-var]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      ta.setRangeText(b.dataset.var, ta.selectionStart, ta.selectionEnd, 'end');
      ta.focus(); refresh();
    }));

    // --- предпросмотр ---
    let timer = null, seq = 0;
    function preview() {
      const my = ++seq;
      const fd = new FormData();
      fd.append('csrf', csrf); fd.append('body', ta.value);
      fetch('index.php?p=preview', { method: 'POST', body: fd }).then((r) => r.json()).then((j) => {
        if (my !== seq) return;
        prev.innerHTML = j.html || '<span class="muted">пусто</span>';
        counter.textContent = j.len + ' / ' + (img.value.trim() ? 1024 : 4096);
        counter.classList.toggle('over', j.len > (img.value.trim() ? 1024 : 4096));
      }).catch(() => {});
    }
    function drawBubble() {
      const u = img.value.trim();
      bubbleImg.textContent = '';
      if (u) {
        const im = document.createElement('img');
        im.alt = '';
        im.addEventListener('error', () => { im.replaceWith(Object.assign(document.createElement('div'), { className: 'muted pad', textContent: 'не удалось загрузить картинку' })); });
        im.src = u;
        bubbleImg.appendChild(im);
      }
      bubbleBtns.innerHTML = rows.map((r) => '<div class="brow">' + r.map((b) => '<span class="chip">' + esc(b.text || '…') + (b.type === 'url' ? ' ↗' : '') + '</span>').join('') + '</div>').join('');
    }
    function refresh() { clearTimeout(timer); timer = setTimeout(preview, 250); drawBubble(); }
    ta.addEventListener('input', refresh);
    img.addEventListener('input', refresh);

    // --- кнопки ---
    function sync() { hidden.value = JSON.stringify(rows.filter((r) => r.length)); drawBubble(); }
    function gsel(v) {
      return '<select data-k="group"><option value="0">— без группы —</option>' +
        groups.map((g) => '<option value="' + g.id + '"' + (+v === +g.id ? ' selected' : '') + '>' + esc(g.name) + '</option>').join('') + '</select>';
    }
    function render() {
      wrap.innerHTML = '';
      rows.forEach((r, ri) => {
        const rowEl = document.createElement('div');
        rowEl.className = 'btn-row';
        rowEl.innerHTML = '<div class="btn-row-head">Ряд ' + (ri + 1) + ' <a href="#" data-add>+ кнопка в ряд</a> <a href="#" data-delrow class="danger">удалить ряд</a></div>';
        r.forEach((b, bi) => {
          const c = document.createElement('div');
          c.className = 'btn-card';
          const isUrl = (b.type || 'url') === 'url';
          c.innerHTML =
            '<div class="grid2"><input data-k="text" placeholder="Текст кнопки" value="' + esc(b.text) + '">' +
            '<select data-k="type"><option value="url"' + (isUrl ? ' selected' : '') + '>Ссылка</option><option value="cb"' + (!isUrl ? ' selected' : '') + '>Действие (callback)</option></select></div>' +
            (isUrl
              ? '<input data-k="url" placeholder="https://..." value="' + esc(b.url) + '"><label class="chk"><input type="checkbox" data-k="track"' + (b.track === false ? '' : ' checked') + '> считать клики (через редирект)</label>'
              : '<textarea data-k="reply" rows="2" placeholder="Ответ бота после нажатия (markdown, необязательно)">' + esc(b.reply) + '</textarea>') +
            '<div class="grid2"><label class="muted">После клика добавить в группу:</label>' + gsel(b.group || 0) + '</div>' +
            '<a href="#" data-del class="danger">удалить кнопку</a>';
          c.querySelectorAll('[data-k]').forEach((el) => {
            const ev = el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(ev, () => {
              const k = el.dataset.k;
              b[k] = el.type === 'checkbox' ? el.checked : (k === 'group' ? +el.value : el.value);
              if (k === 'type') render();
              sync();
            });
          });
          c.querySelector('[data-del]').addEventListener('click', (e) => { e.preventDefault(); r.splice(bi, 1); render(); sync(); });
          rowEl.appendChild(c);
        });
        rowEl.querySelector('[data-add]').addEventListener('click', (e) => { e.preventDefault(); r.push({ text: '', type: 'url', url: '', track: true, group: 0 }); render(); sync(); });
        rowEl.querySelector('[data-delrow]').addEventListener('click', (e) => { e.preventDefault(); rows.splice(ri, 1); render(); sync(); });
        wrap.appendChild(rowEl);
      });
    }
    root.querySelector('[data-addrow]').addEventListener('click', (e) => {
      e.preventDefault();
      rows.push([{ text: '', type: 'url', url: '', track: true, group: 0 }]);
      render(); sync();
    });
    root.closest('form')?.addEventListener('submit', () => { hidden.value = JSON.stringify(rows.map((r) => r.filter((b) => (b.text || '').trim())).filter((r) => r.length)); });
    render(); drawBubble(); preview();
  }
})();

// ---------- глобальное поведение интерфейса ----------
(function () {
  const $ = (q, r = document) => r.querySelector(q);
  const $$ = (q, r = document) => [...r.querySelectorAll(q)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // режим просмотра: блокируем формы изменения (сервер всё равно проверяет права)
  if ($('meta[name=ro]')?.content === '1') {
    $$('main form[method=post]').forEach((f) => $$('input:not([type=hidden]),select,textarea,button', f).forEach((el) => { el.disabled = true; }));
  }

  // мобильное меню
  const side = $('#side'), scrim = $('#scrim');
  const drawer = (on) => { side?.classList.toggle('open', on); scrim?.classList.toggle('on', on); };
  $('#navtoggle')?.addEventListener('click', () => drawer(!side.classList.contains('open')));
  scrim?.addEventListener('click', () => drawer(false));

  // свёрнутые/развёрнутые группы меню запоминаются
  $$('.nav details[data-grp]').forEach((d) => {
    const k = 'nav:' + d.dataset.grp;
    try { if (!d.querySelector('[aria-current=page]')) { const v = localStorage.getItem(k); if (v !== null) d.open = v === '1'; } } catch (e) {}
    d.addEventListener('toggle', () => { try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (e) {} });
  });

  // уведомления
  $$('.toast').forEach((t) => {
    $('button', t)?.addEventListener('click', () => t.remove());
    if (!t.classList.contains('err')) setTimeout(() => t.remove(), 5000);
  });

  // селекторы, применяемые сразу
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // диалог подтверждения
  const dlg = $('#confirm');
  function ask(title, text, ok = 'Подтвердить', danger = false) {
    return new Promise((res) => {
      if (!dlg?.showModal) return res(window.confirm(text || title));
      $('#cf-title').textContent = title; $('#cf-text').textContent = text || '';
      const b = $('#cf-ok'); b.textContent = ok; b.classList.toggle('red', danger);
      dlg.returnValue = ''; dlg.addEventListener('close', () => res(dlg.returnValue === 'ok'), { once: true });
      dlg.showModal();
    });
  }
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('[data-confirm]');
    if (!a || a.dataset.ok) return;
    e.preventDefault();
    const danger = a.classList.contains('red');
    if (await ask(danger ? 'Подтвердите удаление' : 'Подтвердите действие', a.dataset.confirm, danger ? 'Удалить' : 'Подтвердить', danger)) {
      a.dataset.ok = '1'; a.click(); delete a.dataset.ok;
    }
  });
  // рассылка «сейчас» — отдельное подтверждение с числом получателей
  $$('form[data-send-confirm]').forEach((f) => f.addEventListener('submit', async (e) => {
    if (f.dataset.ok) { delete f.dataset.ok; return; }
    const sub = e.submitter;
    if ($('input[name=mode]:checked', f)?.value === 'now' && sub?.value === 'save') {
      e.preventDefault();
      if (await ask('Отправить рассылку сейчас?', f.dataset.sendConfirm, 'Отправить')) { f.dataset.ok = '1'; f.requestSubmit(sub); }
    }
  }));

  // защита от двойной отправки и несохранённых изменений
  let submitting = false;
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (e.defaultPrevented || f.method !== 'post') return;
    if (f.dataset.sub) { e.preventDefault(); return; }
    f.dataset.sub = '1'; submitting = true;
    e.submitter?.classList.add('busy');
    setTimeout(() => { delete f.dataset.sub; submitting = false; e.submitter?.classList.remove('busy'); }, 8000);
  });
  window.addEventListener('pageshow', () => { submitting = false; $$('form[data-sub]').forEach((f) => delete f.dataset.sub); $$('.busy').forEach((b) => b.classList.remove('busy')); });
  const guards = $$('form.guard');
  setTimeout(() => guards.forEach((f) => { f.dataset.init = new URLSearchParams(new FormData(f)).toString(); }), 600);
  window.addEventListener('beforeunload', (e) => {
    if (submitting) return;
    if (guards.some((f) => f.dataset.init !== undefined && new URLSearchParams(new FormData(f)).toString() !== f.dataset.init)) { e.preventDefault(); e.returnValue = ''; }
  });

  // Ctrl/Cmd+S — сохранить форму, в которой находится курсор
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      const f = document.activeElement?.closest?.('form[method=post]');
      const btn = f && $('[data-save]', f);
      if (btn && !btn.disabled) { e.preventDefault(); btn.click(); }
    }
  });

  // выбор строк → плавающая панель массовых действий
  const bar = $('#bulkbar');
  if (bar) {
    const upd = () => { const n = $$('input[name="ids[]"]:checked').length; $('#bulkn').textContent = n; bar.classList.toggle('on', n > 0 || $('input[name=all_filtered]')?.checked); };
    document.addEventListener('change', (e) => {
      if (e.target.id === 'checkall') $$('input[name="ids[]"]').forEach((c) => (c.checked = e.target.checked));
      if (e.target.matches('input[name="ids[]"],#checkall,input[name=all_filtered]')) upd();
    });
  }

  // подпись главной кнопки зависит от выбранного режима отправки
  $$('[data-modes]').forEach((bar) => {
    const f = bar.closest('form'), map = JSON.parse(bar.dataset.modes), btn = $('[data-mode-label]', bar);
    const upd = () => { const m = $('input[name=mode]:checked', f)?.value || 'draft'; btn.textContent = map[m] || 'Применить'; btn.classList.toggle('red', m === 'now'); };
    $$('input[name=mode]', f).forEach((r) => r.addEventListener('change', upd));
    $('input[name=send_at]', f)?.addEventListener('input', () => { const r = $('input[name=mode][value=schedule]', f); if (r && $('input[name=send_at]', f).value) { r.checked = true; upd(); } });
    upd();
  });

  // копирование по клику
  $$('[data-copy]').forEach((el) => el.addEventListener('click', () => {
    navigator.clipboard?.writeText(el.dataset.copy || el.textContent.trim());
    el.classList.add('copied'); setTimeout(() => el.classList.remove('copied'), 800);
  }));
  $('#allbots')?.addEventListener('change', (e) => $('#botlist')?.classList.toggle('dim', e.target.checked));

  // якоря: раскрыть шаг цепочки, фокус на форме создания
  const openHash = () => {
    const h = location.hash.slice(1); if (!h) return;
    const el = document.getElementById(h);
    if (el?.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ block: 'start' }); }
    if (h === 'new') $('#new input[name=name]')?.focus();
  };
  openHash(); window.addEventListener('hashchange', openHash);

  // палитра команд (Ctrl/Cmd+K или «/»)
  const pal = $('#palette'), pq = $('#pal-q'), pl = $('#pal-list');
  let cmds = []; try { cmds = JSON.parse($('meta[name=cmds]')?.content || '[]'); } catch (e) {}
  const canUsers = $('meta[name=usersearch]')?.content === '1';
  let items = [], sel = 0;
  function draw() {
    const q = pq.value.trim().toLowerCase();
    items = cmds.filter((c) => !q || (c.t + ' ' + c.g).toLowerCase().includes(q));
    if (canUsers && q.length >= 2) items.push({ t: 'Найти подписчика «' + pq.value.trim() + '»', g: 'Поиск', u: '?p=users&q=' + encodeURIComponent(pq.value.trim()) });
    sel = Math.min(sel, Math.max(0, items.length - 1));
    pl.innerHTML = items.map((c, i) => '<li role="option" data-i="' + i + '" aria-selected="' + (i === sel) + '">' + esc(c.t) + '<small>' + esc(c.g) + '</small></li>').join('') || '<li class="muted">Ничего не найдено</li>';
  }
  const go = () => { if (items[sel]) location.href = items[sel].u; };
  const openPal = () => { if (!pal?.showModal) return; pq.value = ''; sel = 0; draw(); pal.showModal(); pq.focus(); };
  $('#palbtn')?.addEventListener('click', openPal);
  document.addEventListener('keydown', (e) => {
    const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
    if (((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) { e.preventDefault(); openPal(); }
  });
  pq?.addEventListener('input', () => { sel = 0; draw(); });
  pq?.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); draw(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); draw(); }
    else if (e.key === 'Enter') { e.preventDefault(); go(); }
  });
  pl?.addEventListener('click', (e) => { const li = e.target.closest('li[data-i]'); if (li) { sel = +li.dataset.i; go(); } });
  pal?.addEventListener('click', (e) => { if (e.target === pal) pal.close(); });
})();
