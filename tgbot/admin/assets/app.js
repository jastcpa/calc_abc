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
      bubbleImg.innerHTML = u ? '<img src="' + esc(u) + '" alt="" onerror="this.replaceWith(Object.assign(document.createElement(\'div\'),{className:\'muted pad\',textContent:\'не удалось загрузить картинку\'}))">' : '';
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

  // подтверждения
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-confirm]');
    if (a && !confirm(a.dataset.confirm)) e.preventDefault();
  });
  // выбрать все
  document.querySelector('#checkall')?.addEventListener('change', (e) => {
    document.querySelectorAll('input[name="ids[]"]').forEach((c) => (c.checked = e.target.checked));
  });
  // копирование
  document.querySelectorAll('[data-copy]').forEach((el) => el.addEventListener('click', () => {
    navigator.clipboard?.writeText(el.dataset.copy || el.textContent.trim());
    el.classList.add('copied'); setTimeout(() => el.classList.remove('copied'), 800);
  }));
})();
