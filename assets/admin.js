(function () {
  'use strict';
  const C = window.CMSF || {};
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  function api(action, data) {
    const fd = new FormData();
    fd.append('action', 'cmsf_' + action);
    fd.append('nonce', C.nonce);
    Object.keys(data || {}).forEach(k => {
      const v = data[k];
      if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
      else if (v !== undefined && v !== null) fd.append(k, v);
    });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.text())
      .then(t => {
        let j;
        try { j = JSON.parse(t); } catch (e) { throw new Error('Сервер повернув не JSON (можливо, помилка PHP або тайм-аут)'); }
        if (!j.success) throw new Error(typeof j.data === 'string' ? j.data : 'Помилка');
        return j.data;
      });
  }

  const STATE = { ok: 'Знайдений', queued: 'У черзі', rejected: 'Відхилений', failed: 'Недоступний' };

  // ============ ПЕРЕВІРКА ЧЕРГИ (спільна для пошуку та списків) ============
  async function verifyLoop(listId, ctrl, onTick) {
    const first = await api('next_queued', { list_id: listId, n: 1 });
    const res = { total: first.left, done: 0, ok: 0, rejected: 0, failed: 0, errors: 0, lastError: '', aborted: '' };
    if (!first.left) return res;
    let n = Math.max(1, C.concurrency || 3), streak = 0;
    const handle = async id => {
      try {
        const row = await api('inspect', { id });
        if (row.state === 'ok') { res.ok++; streak = 0; }
        else if (row.state === 'rejected') { res.rejected++; streak = 0; }
        else if (row.state === 'failed') { res.failed++; streak = 0; }
        else throw new Error('Результат перевірки не збережено');
      } catch (e) {
        res.errors++; streak++; res.lastError = e.message;
        await api('row_failed', { id }).catch(() => {});
      }
      res.done++; onTick && onTick(res);
    };
    let ids = first.ids;
    while (!ctrl.stop) {
      if (!ids.length) { const b = await api('next_queued', { list_id: listId, n }); ids = b.ids; if (!ids.length) break; }
      await Promise.all(ids.map(handle));
      ids = [];
      if (streak >= 6 && n > 1) { n = 1; res.lastError = (res.lastError || '') + ' (зменшено до 1 паралельної перевірки)'; }
      if (streak >= 15) { res.aborted = 'Сервер постійно повертає помилки: ' + res.lastError; break; }
    }
    return res;
  }

  // ============ КОМПОНЕНТ «СПИСОК САЙТІВ» ============
  function createListView(root, listId) {
    const S = { page: 1, pages: 1, total: 0, sel: new Set(), rows: {}, stats: {}, tpls: [], sending: false, stopSend: false, verifying: false };
    root.innerHTML = `
      <div class="cmsf-lv-stats"></div>
      <div class="cmsf-lv-filters">
        <input type="search" class="f-q" placeholder="Пошук: домен, назва, email, нотатка">
        <select class="f-state"><option value="ok">Знайдені</option><option value="queued">У черзі</option><option value="rejected">Відхилені</option><option value="failed">Недоступні</option><option value="all">Усі</option></select>
        <select class="f-sent"><option value="">Відправка: усі</option><option value="unsent">Ще не надсилали</option><option value="sent">Надіслано успішно</option><option value="failed">Помилка відправки</option></select>
        <select class="f-cms"><option value="">Усі CMS</option></select>
        <label class="cmsf-chk"><input type="checkbox" class="f-email"> Є email</label>
        <label class="cmsf-chk"><input type="checkbox" class="f-outdated"> Застаріла версія</label>
      </div>
      <div class="cmsf-lv-actions">
        <button type="button" class="button b-verify" hidden></button>
        <button type="button" class="button b-requeue" hidden>Повторити недоступні</button>
        <button type="button" class="button b-export">Експорт CSV</button>
        <button type="button" class="button b-del">Видалити вибрані</button>
        <span class="cmsf-msg lv-msg"></span>
      </div>
      <div class="cmsf-selbar">
        <label class="cmsf-chk"><input type="checkbox" class="sel-page"> Вибрати всі на сторінці</label>
        <span class="sel-info"></span>
        <button type="button" class="cmsf-link sel-all" hidden></button>
        <button type="button" class="cmsf-link sel-none" hidden>Зняти вибір</button>
      </div>
      <div class="cmsf-send">
        <div class="cmsf-send-row">
          <label><span>Шаблон листа</span><select class="s-tpl"></select></label>
          <span class="cmsf-tpl-btns"><button type="button" class="button s-edit">Редагувати</button> <button type="button" class="button s-del">Видалити</button> <a href="${esc(C.templatesUrl || '#')}" class="cmsf-link">Усі шаблони</a></span>
          <label class="cmsf-chk"><input type="checkbox" class="s-skip" checked> Пропускати тих, кому цей шаблон уже надіслано</label>
          <button type="button" class="button button-primary s-go">Надіслати вибраним</button>
          <button type="button" class="button s-stop" hidden>Зупинити</button>
        </div>
        <div class="s-new" hidden>
          <div class="cmsf-grid">
            <label><span>Назва шаблону</span><input type="text" class="n-name"></label>
            <label><span>Тема (група)</span><input type="text" class="n-topic" placeholder="Joomla, WordPress, Плагіни…"></label>
            <label class="cmsf-wide"><span>Тема листа</span><input type="text" class="n-subject"></label>
            <label class="cmsf-wide"><span>Текст листа (змінні: {url} {domain} {site_title} {cms} {version} {latest_version})</span><textarea class="n-body" rows="6"></textarea></label>
          </div>
          <button type="button" class="button button-primary n-save">Зберегти шаблон</button> <button type="button" class="button n-saveas" hidden>Зберегти як новий</button> <button type="button" class="button n-cancel">Скасувати</button> <span class="cmsf-msg n-msg"></span>
        </div>
        <div class="s-progress" hidden>
          <div class="cmsf-bar"><div class="s-fill"></div></div>
          <div class="s-count"></div>
        </div>
      </div>
      <div class="cmsf-table-wrap"><table class="cmsf-table"><thead><tr>
        <th class="c-sel"></th><th>Сайт</th><th>CMS</th><th>Країна / мова</th><th>Email</th><th>Телефон</th><th>Нотатка</th><th>Відправлено</th><th></th>
      </tr></thead><tbody></tbody></table></div>
      <div class="cmsf-pager"><button type="button" class="button p-prev">←</button><span class="p-info"></span><button type="button" class="button p-next">→</button></div>`;
    const q = s => $(s, root);
    const tbody = q('tbody');
    const filters = () => ({ state: q('.f-state').value, q: q('.f-q').value.trim(), sent: q('.f-sent').value, cms_filter: q('.f-cms').value, has_email: q('.f-email').checked ? 1 : '', outdated: q('.f-outdated').checked ? 1 : '' });
    const msg = t => { q('.lv-msg').textContent = t || ''; };

    function sentCell(r) {
      if (r.send_ok === 1) return `<span class="cmsf-ok">✓ Надіслано</span><small>${esc(r.sent_label)}${r.send_count > 1 ? ' · ' + r.send_count + ' разів' : ''}</small>`;
      if (r.send_ok === 0) return `<span class="cmsf-bad">✗ Помилка</span><small>${esc(r.sent_label)} — ${esc(r.send_error)}</small>`;
      return r.email ? '<span class="cmsf-muted">—</span>' : '<span class="cmsf-muted">немає email</span>';
    }
    function rowHtml(r) {
      const cms = r.cms ? `<b>${esc(r.cms)}</b>${r.version ? ' <span class="cmsf-ver">' + esc(r.version) + '</span>' : ''}${r.outdated ? ' <span class="cmsf-badge" title="Остання версія: ' + esc(r.latest) + '">застаріла</span>' : ''}` : '—';
      const sub = r.state !== 'ok' ? `<div class="cmsf-sub">${esc(STATE[r.state] || r.state)}${r.reject_reason ? ': ' + esc(r.reject_reason) : ''}</div>` : (r.title ? `<div class="cmsf-sub">${esc(r.title)}</div>` : '');
      return `<tr data-id="${r.id}" class="${S.sel.has(r.id) ? 'is-sel' : ''}">
        <td class="c-sel"><input type="checkbox" class="row-sel" ${S.sel.has(r.id) ? 'checked' : ''}></td>
        <td data-label="Сайт"><a href="${esc(r.url)}" target="_blank" rel="noopener">${esc(r.domain)}</a>${sub}</td>
        <td data-label="CMS">${cms}</td>
        <td data-label="Країна / мова">${esc(r.country || '—')}<div class="cmsf-sub">${esc(r.language || '')}</div></td>
        <td data-label="Email"><input type="text" class="inp-email" value="${esc(r.email)}" placeholder="email"></td>
        <td data-label="Телефон">${esc(r.phone || '—')}</td>
        <td data-label="Нотатка"><input type="text" class="inp-note" value="${esc(r.note || '')}" placeholder="нотатка"></td>
        <td data-label="Відправлено" class="c-sent">${sentCell(r)}</td>
        <td class="c-act"><button type="button" class="button row-del" title="Видалити">✕</button></td></tr>`;
    }
    function updateRow(r) {
      if (!r || !r.id) return;
      S.rows[r.id] = r;
      const tr = $('tr[data-id="' + r.id + '"]', tbody);
      if (tr) { tr.querySelector('.c-sent').innerHTML = sentCell(r); }
    }
    function renderSel() {
      const n = S.sel.size;
      q('.sel-info').textContent = n ? 'Вибрано: ' + n : '';
      q('.sel-none').hidden = !n;
      const pageIds = Object.keys(S.rows).map(Number);
      q('.sel-page').checked = pageIds.length > 0 && pageIds.every(id => S.sel.has(id));
      const all = q('.sel-all');
      all.hidden = !(S.total > pageIds.length && n < S.total);
      all.textContent = 'Вибрати всі ' + S.total + ' у списку';
      $$('tr[data-id]', tbody).forEach(tr => tr.classList.toggle('is-sel', S.sel.has(Number(tr.dataset.id))));
    }
    function renderStats() {
      const s = S.stats;
      q('.cmsf-lv-stats').innerHTML = `<span class="cmsf-chip">Знайдено: <b>${s.ok || 0}</b></span><span class="cmsf-chip">У черзі: <b>${s.queued || 0}</b></span><span class="cmsf-chip">Відхилено: <b>${s.rejected || 0}</b></span><span class="cmsf-chip">Недоступні: <b>${s.failed || 0}</b></span><span class="cmsf-chip cmsf-chip-ok">Надіслано: <b>${s.sent || 0}</b></span><span class="cmsf-chip cmsf-chip-bad">Помилки відправки: <b>${s.sent_fail || 0}</b></span>`;
      const vb = q('.b-verify');
      vb.hidden = !(s.queued > 0) || S.sending;
      vb.textContent = S.verifying ? 'Зупинити перевірку' : 'Продовжити перевірку (' + (s.queued || 0) + ' у черзі)';
      q('.b-requeue').hidden = !(s.failed > 0);
    }

    async function load(page) {
      if (page) S.page = page;
      const d = await api('rows', Object.assign({ list_id: listId, page: S.page }, filters()));
      S.total = d.total; S.pages = d.pages; S.page = d.page; S.stats = d.stats; S.rows = {};
      d.rows.forEach(r => { S.rows[r.id] = r; });
      tbody.innerHTML = d.rows.length ? d.rows.map(rowHtml).join('') : '<tr><td colspan="9" class="cmsf-empty">Тут поки нічого немає</td></tr>';
      const cs = q('.f-cms'), cur = cs.value;
      cs.innerHTML = '<option value="">Усі CMS</option>' + d.cms_list.map(c => `<option${c === cur ? ' selected' : ''}>${esc(c)}</option>`).join('');
      q('.p-info').textContent = 'Сторінка ' + S.page + ' з ' + S.pages + ' · всього ' + S.total;
      q('.p-prev').disabled = S.page <= 1; q('.p-next').disabled = S.page >= S.pages;
      renderStats(); renderSel();
    }

    // --- події ---
    let t;
    q('.f-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => load(1), 350); });
    ['.f-state', '.f-sent', '.f-cms', '.f-email', '.f-outdated'].forEach(s => q(s).addEventListener('change', () => load(1)));
    q('.p-prev').onclick = () => load(S.page - 1);
    q('.p-next').onclick = () => load(S.page + 1);
    tbody.addEventListener('change', e => {
      const tr = e.target.closest('tr'); if (!tr) return;
      const id = Number(tr.dataset.id);
      if (e.target.classList.contains('row-sel')) { e.target.checked ? S.sel.add(id) : S.sel.delete(id); renderSel(); }
      else if (e.target.classList.contains('inp-note') || e.target.classList.contains('inp-email')) {
        const field = e.target.classList.contains('inp-note') ? 'note' : 'email';
        api('row_update', { id, field, value: e.target.value }).then(() => { if (S.rows[id]) S.rows[id][field] = e.target.value; msg('Збережено'); setTimeout(() => msg(''), 1500); }).catch(err => { msg(err.message); });
      }
    });
    tbody.addEventListener('click', e => {
      const b = e.target.closest('.row-del'); if (!b) return;
      const id = Number(b.closest('tr').dataset.id);
      if (confirm('Видалити цей сайт зі списку?')) api('rows_delete', { ids: [id] }).then(() => { S.sel.delete(id); load(); });
    });
    q('.sel-page').onchange = e => { Object.keys(S.rows).map(Number).forEach(id => e.target.checked ? S.sel.add(id) : S.sel.delete(id)); $$('.row-sel', tbody).forEach(c => { c.checked = e.target.checked; }); renderSel(); };
    q('.sel-none').onclick = () => { S.sel.clear(); $$('.row-sel', tbody).forEach(c => { c.checked = false; }); renderSel(); };
    q('.sel-all').onclick = async () => {
      msg('Завантаження…');
      const ids = await api('ids', Object.assign({ list_id: listId }, filters()));
      ids.forEach(id => S.sel.add(id)); $$('.row-sel', tbody).forEach(c => { c.checked = true; }); msg(''); renderSel();
    };
    q('.b-del').onclick = () => {
      if (!S.sel.size) { alert('Спершу позначте сайти галочками.'); return; }
      if (confirm('Видалити вибрані сайти (' + S.sel.size + ') зі списку?')) api('rows_delete', { ids: Array.from(S.sel) }).then(() => { S.sel.clear(); load(); });
    };
    q('.b-requeue').onclick = () => api('requeue', { list_id: listId }).then(() => load());

    // експорт: окремий POST на admin-post.php, щоб відповідь була чистим CSV
    q('.b-export').onclick = () => {
      const f = document.createElement('form');
      f.method = 'POST'; f.action = C.exportUrl; f.style.display = 'none';
      const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
      add('action', 'cmsf_export'); add('_wpnonce', C.exportNonce); add('list_id', listId);
      if (S.sel.size) add('ids', Array.from(S.sel).join(','));
      else { const fl = filters(); Object.keys(fl).forEach(k => add(k, fl[k])); }
      document.body.appendChild(f); f.submit(); setTimeout(() => f.remove(), 2000);
    };

    // продовження перевірки черги
    const vctrl = { stop: false };
    let poll = null;
    q('.b-verify').onclick = async () => {
      if (S.verifying) { vctrl.stop = true; return; }
      S.verifying = true; vctrl.stop = false; renderStats();
      poll = setInterval(() => load().catch(() => {}), 4000);
      try { const vr = await verifyLoop(listId, vctrl, r => msg('Перевірено ' + r.done + ' з ' + r.total + ' · знайдено ' + r.ok + (r.errors ? ' · збоїв: ' + r.errors : ''))); if (vr.aborted) alert(vr.aborted); }
      catch (e) { msg(e.message); }
      clearInterval(poll); S.verifying = false; await load(); msg('Перевірку завершено');
    };

    // --- шаблони та розсилка ---
    async function loadTpls(selectId) {
      S.tpls = await api('tpls');
      const groups = {};
      S.tpls.forEach(t => { (groups[t.topic || 'Без теми'] = groups[t.topic || 'Без теми'] || []).push(t); });
      q('.s-tpl').innerHTML = '<option value="">— оберіть шаблон —</option>' + Object.keys(groups).map(g => `<optgroup label="${esc(g)}">${groups[g].map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</optgroup>`).join('') + '<option value="new">+ Створити новий шаблон…</option>';
      if (selectId) q('.s-tpl').value = String(selectId);
    }
    // редактор шаблону прямо в розсилці: S.editId = 0 — новий шаблон, інакше редагування існуючого
    S.editId = 0;
    const fillEditor = t => {
      q('.n-name').value = t ? t.name : ''; q('.n-topic').value = t ? t.topic : ''; q('.n-subject').value = t ? t.subject : ''; q('.n-body').value = t ? t.body : '';
      S.editId = t ? Number(t.id) : 0; S.editHtml = t ? t.is_html : 0;
      q('.n-save').textContent = t ? 'Зберегти зміни' : 'Зберегти шаблон';
      q('.n-saveas').hidden = !t; q('.n-msg').textContent = ''; q('.s-new').hidden = false;
    };
    q('.s-tpl').onchange = e => { if (e.target.value === 'new') fillEditor(null); else q('.s-new').hidden = true; };
    q('.s-edit').onclick = () => {
      const t = S.tpls.find(x => String(x.id) === q('.s-tpl').value);
      if (!t) { alert('Спершу оберіть шаблон зі списку.'); return; }
      fillEditor(t);
    };
    q('.s-del').onclick = async () => {
      const t = S.tpls.find(x => String(x.id) === q('.s-tpl').value);
      if (!t) { alert('Спершу оберіть шаблон зі списку.'); return; }
      if (!confirm('Видалити шаблон «' + t.name + '»? Уже надіслані листи та відмітки залишаться.')) return;
      await api('tpl_delete', { id: t.id }); q('.s-new').hidden = true; await loadTpls(); msg('Шаблон видалено');
    };
    q('.n-cancel').onclick = () => { q('.s-new').hidden = true; if (q('.s-tpl').value === 'new') q('.s-tpl').value = ''; };
    const saveTpl = async asNew => {
      const m = q('.n-msg');
      try {
        const r = await api('tpl_save', { id: asNew ? 0 : S.editId, name: q('.n-name').value, topic: q('.n-topic').value, subject: q('.n-subject').value, body: q('.n-body').value, is_html: asNew ? '' : (Number(S.editHtml) ? 1 : '') });
        await loadTpls(r.id); q('.s-new').hidden = true; msg('Шаблон збережено і вибрано');
      } catch (e) { m.textContent = e.message; }
    };
    q('.n-save').onclick = () => saveTpl(false);
    q('.n-saveas').onclick = () => saveTpl(true);
    const sctrl = { stop: false };
    q('.s-stop').onclick = () => { sctrl.stop = true; q('.s-stop').disabled = true; };
    q('.s-go').onclick = async () => {
      if (S.sending) return;
      const tid = Number(q('.s-tpl').value);
      if (!tid) { alert('Оберіть шаблон листа (або створіть новий і збережіть його).'); return; }
      const ids = Array.from(S.sel);
      if (!ids.length) { alert('Позначте сайти галочками або натисніть «Вибрати всі».'); return; }
      const name = q('.s-tpl').selectedOptions[0].textContent;
      if (!confirm('Надіслати лист «' + name + '» на ' + ids.length + ' сайтів?')) return;
      S.sending = true; sctrl.stop = false; q('.s-stop').disabled = false; q('.s-stop').hidden = false; q('.s-go').disabled = true; q('.s-progress').hidden = false; renderStats();
      const c = { ok: 0, fail: 0, skip: 0, done: 0 };
      const skip = q('.s-skip').checked ? 1 : '';
      const show = () => {
        q('.s-fill').style.width = Math.round(c.done / ids.length * 100) + '%';
        q('.s-count').innerHTML = `Оброблено ${c.done} з ${ids.length} · <span class="cmsf-ok">успішно: ${c.ok}</span> · <span class="cmsf-bad">помилок: ${c.fail}</span> · пропущено: ${c.skip}`;
      };
      show();
      for (const id of ids) {
        if (sctrl.stop) break;
        let res;
        try { res = await api('send', { id, template_id: tid, skip_sent: skip }); }
        catch (e) { res = { ok: false, error: e.message }; }
        c.done++;
        if (res.skipped) c.skip++; else if (res.ok) c.ok++; else c.fail++;
        if (res.row) updateRow(res.row);
        show();
        if (!res.skipped && C.delay && !sctrl.stop && c.done < ids.length) await sleep(C.delay * 1000);
      }
      S.sending = false; q('.s-go').disabled = false; q('.s-stop').hidden = true;
      q('.s-count').insertAdjacentHTML('beforeend', sctrl.stop ? ' — зупинено' : ' — завершено');
      load();
    };

    loadTpls().catch(() => {});
    load().catch(e => msg(e.message));
    return { reload: load };
  }

  // ============ СТОРІНКИ «ПОШУК» І «СВІЙ СПИСОК» ============
  function initSearch(mode) {
    const isImport = mode === 'import';
    const form = $('#cmsf-search-form');
    const startBtn = $('#cmsf-start'), stopBtn = $('#cmsf-stop');
    const logEl = $('#cmsf-log');
    const ctrl = { stop: false };
    let running = false;
    const log = s => { logEl.textContent += s + '\n'; logEl.scrollTop = logEl.scrollHeight; };
    const stage = s => { $('#cmsf-stage').textContent = s; };
    const bar = p => { $('#cmsf-bar-fill').style.width = Math.max(0, Math.min(100, p)) + '%'; };
    const vals = name => $$('input[name="' + name + '"]:checked', form).map(i => i.value);
    const field = name => form.elements[name] ? form.elements[name].value : '';

    // поточні параметри форми (ті самі зберігаються як «шаблон пошуку»)
    function collect() {
      return {
        cms: vals('cms'), regions: vals('regions'), languages: vals('languages'),
        site_type: field('site_type') || 'any', categories: field('categories'), tags: field('tags'), query: field('query'),
        sources: vals('sources'), limit: field('limit') || 500, require_terms: form.elements.require_terms && form.elements.require_terms.checked ? 1 : '',
      };
    }

    // ---- шаблони пошуку ----
    if (!isImport) {
      const sel = $('#cmsf-preset'), pm = $('#cmsf-preset-msg');
      let presets = [];
      const say = (t, bad) => { pm.textContent = t; pm.className = 'cmsf-msg' + (bad ? ' cmsf-bad' : ' cmsf-ok'); };
      const apply = p => {
        ['cms', 'regions', 'languages', 'sources'].forEach(n => $$('input[name="' + n + '"]', form).forEach(i => { i.checked = (p[n] || []).indexOf(i.value) >= 0; }));
        ['site_type', 'categories', 'tags', 'query', 'limit'].forEach(n => { if (form.elements[n] && p[n] !== undefined) form.elements[n].value = p[n]; });
        if (form.elements.require_terms) form.elements.require_terms.checked = !!Number(p.require_terms);
      };
      const loadPresets = async (selectId) => {
        presets = await api('presets');
        sel.innerHTML = '<option value="">— обрати збережений шаблон —</option>' + presets.map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
        if (selectId) sel.value = selectId;
      };
      sel.onchange = () => { const p = presets.find(x => x.id === sel.value); if (p) { apply(p.params); form.elements.name.value = p.name; say('Параметри завантажено'); } };
      $('#cmsf-preset-save').onclick = async () => {
        const cur = presets.find(x => x.id === sel.value);
        const name = prompt('Назва шаблону пошуку (така сама назва оновить існуючий):', cur ? cur.name : (form.elements.name.value || ''));
        if (!name) return;
        try { const r = await api('preset_save', Object.assign({ name }, collect())); await loadPresets(r.id); say('Шаблон збережено'); } catch (e) { say(e.message, true); }
      };
      $('#cmsf-preset-del').onclick = async () => {
        if (!sel.value) { say('Спершу оберіть шаблон', true); return; }
        if (!confirm('Видалити шаблон пошуку «' + sel.selectedOptions[0].textContent + '»?')) return;
        await api('preset_delete', { id: sel.value }); await loadPresets(); say('Видалено');
      };
      loadPresets().catch(e => say(e.message, true));

      (function zoneNote() {
        api('zone_info').then(z => {
          const parts = [];
          ['ua', 'eu', 'us'].forEach(k => { parts.push('.' + k + ': ' + (z[k].ready ? 'готовий (' + z[k].count + ' доменів)' : 'не завантажено')); });
          $('#cmsf-zone-note').textContent = 'Стан списку зон — ' + parts.join(' · ') + '. Завантажується автоматично при першому пошуку (~100 МБ, кілька хвилин).';
        }).catch(() => {});
      })();
    } else {
      const uf = $('#cmsf-use-filters');
      uf.addEventListener('change', () => { $('#cmsf-filters').hidden = !uf.checked; });
    }

    const fileEl = $('#cmsf-file');
    if (fileEl) fileEl.addEventListener('change', e => {
      const f = e.target.files[0]; if (!f) return;
      const r = new FileReader();
      r.onload = () => { const ta = form.elements.domains; ta.value = (ta.value ? ta.value + '\n' : '') + r.result; };
      r.readAsText(f);
    });

    async function run() {
      const data = Object.assign({ name: field('name') }, collect());
      const pasted = field('domains').trim();
      if (isImport) {
        if (!pasted) { alert('Вставте домени або виберіть файл.'); return; }
        data.sources = [];
        if (!$('#cmsf-use-filters').checked) { data.cms = []; data.regions = ['world']; data.languages = ['any']; data.site_type = 'any'; }
      } else if (!data.sources.length) { alert('Оберіть, де шукати кандидатів.'); return; }
      if (!data.regions.length) { alert('Оберіть країну.'); return; }
      if (!data.languages.length) data.languages = ['any'];
      if (!data.name) {
        const cmsName = data.cms.length ? data.cms.join(', ') : 'Будь-яка CMS';
        const reg = { ua: 'Україна', eu: 'Європа', us: 'США', world: 'Світ' };
        data.name = (isImport ? 'Свій список · ' : '') + cmsName + ' · ' + data.regions.map(r => reg[r]).join(', ') + ' · ' + new Date().toLocaleDateString('uk-UA');
      }
      running = true; ctrl.stop = false; startBtn.disabled = true; stopBtn.disabled = false;
      $('#cmsf-progress').hidden = false; logEl.textContent = ''; bar(2); $('#cmsf-counters').innerHTML = '';
      const limit = Number(data.limit) || 500;
      try {
        stage('Створення списку…');
        const { list_id } = await api('list_create', data);
        $('#cmsf-current').hidden = false;
        const lv = createListView($('#cmsf-listview'), list_id);
        log('Список «' + data.name + '» створено (id ' + list_id + '). Він збережений і доступний у розділі «Списки».');

        if (pasted) { const r = await api('add_domains', { list_id, text: pasted }); log('Додано доменів: ' + r.added + ' із ' + r.total); }
        let candidates = 0;
        if (!isImport) {
          const plan = await api('plan', { list_id });

          // 1) пошуковики — вони шукають за категоріями/тегами, тому йдуть першими
          const blocked = new Set();
          let qi = 0;
          for (const query of plan.queries) {
            qi++;
            if (ctrl.stop || candidates >= limit) break;
            stage('Пошук ' + qi + ' із ' + plan.queries.length + ': ' + query);
            for (const eng of plan.engines) {
              if (blocked.has(eng) || ctrl.stop) continue;
              for (let page = 0; page < (plan.pages[eng] || 1); page++) {
                const r = await api('search', { list_id, engine: eng, query, page, ua: plan.ua ? 1 : '' });
                if (r.error) { log((C.engines[eng] || eng) + ': ' + r.error); if (r.blocked) blocked.add(eng); break; }
                candidates += r.added; log((C.engines[eng] || eng) + ': знайдено ' + r.found + ', нових ' + r.added);
                if (!r.found) break;
                await sleep(700);
              }
            }
            bar(qi / plan.queries.length * 10);
          }
          if (blocked.size) log('Заблоковані пошуковики: ' + Array.from(blocked).map(b => C.engines[b] || b).join(', ') + '.');

          // 2) список доменів зони — добирає до ліміту
          for (const zone of plan.zone) {
            if (ctrl.stop || candidates >= limit) break;
            const info = await api('zone_info');
            if (!info[zone].ready) {
              stage('Завантаження списку доменів зони .' + zone + ' (один раз, ~100 МБ)…');
              let first = true;
              while (!ctrl.stop) {
                const st = await api('zone_step', { reset: first ? 1 : '' });
                first = false;
                if (st.done) { log('Список зон підготовлено.'); break; }
                bar(10 + st.pct * 0.2); stage('Завантаження списку доменів: ' + st.pct + '%');
              }
            }
            stage('Додаю домени зони .' + zone + ' до черги…');
            let pos = 0;
            while (!ctrl.stop && candidates < limit) {
              const r = await api('zone_take', { list_id, zone, pos, n: Math.min(500, limit - candidates) });
              candidates += r.taken; pos = r.pos; log('Зона .' + zone + ': додано ' + r.added + ' (усього кандидатів ' + candidates + ')');
              if (r.eof || !r.taken) break;
            }
          }
        }

        // 3) перевірка по коду
        stage('Перевірка по коду…');
        const t0 = Date.now();
        const poll = setInterval(() => lv.reload().catch(() => {}), 4000);
        const res = await verifyLoop(list_id, ctrl, r => {
          bar(30 + (r.done / Math.max(1, r.total)) * 70);
          $('#cmsf-counters').innerHTML = `Перевірено ${r.done} з ${r.total} · <span class="cmsf-ok">підходять: ${r.ok}</span> · відхилено: ${r.rejected} · сайт недоступний: ${r.failed}` + (r.errors ? ` · <span class="cmsf-bad">збої плагіна/сервера: ${r.errors}</span> (останній: ${esc(r.lastError)})` : '');
        });
        clearInterval(poll);
        bar(100);
        if (res.aborted) log(res.aborted);
        stage(res.aborted ? res.aborted : ctrl.stop ? 'Зупинено. Решту можна дошукати у списку («Продовжити перевірку»).' : 'Готово. Підходять: ' + res.ok + ' сайтів.');
        log('Завершено за ' + Math.round((Date.now() - t0) / 1000) + ' с. Підходять: ' + res.ok + ', відхилено: ' + res.rejected + ', сайт недоступний: ' + res.failed + (res.errors ? ', збоїв: ' + res.errors + ' (' + res.lastError + ')' : '') + '.');
        await lv.reload();
      } catch (e) {
        stage('Помилка: ' + e.message); log('Помилка: ' + e.message);
      }
      running = false; startBtn.disabled = false; stopBtn.disabled = true;
    }
    startBtn.onclick = () => { if (!running) run(); };
    stopBtn.onclick = () => { ctrl.stop = true; stopBtn.disabled = true; stage('Зупиняю після поточних запитів…'); };
  }

  // ============ СТОРІНКА «СПИСКИ» ============
  function initLists() {
    const box = $('#cmsf-lists'), open = $('#cmsf-open');
    const regs = { ua: 'Україна', eu: 'Європа', us: 'США', world: 'Світ' };
    async function render() {
      const lists = await api('lists');
      if (!lists.length) { box.innerHTML = '<div class="cmsf-card">Збережених списків ще немає. Створіть перший у розділі «Пошук».</div>'; return; }
      box.innerHTML = '<div class="cmsf-table-wrap"><table class="cmsf-table cmsf-lists"><thead><tr><th>Назва</th><th>CMS / країна</th><th>Створено</th><th>Сайтів</th><th>Надіслано</th><th></th></tr></thead><tbody>' +
        lists.map(l => `<tr data-id="${l.id}">
          <td data-label="Назва"><a href="#" class="l-open"><b>${esc(l.name)}</b></a></td>
          <td data-label="CMS / країна">${esc(l.cms.join(', ') || 'будь-яка')} · ${esc(l.regions.map(r => regs[r] || r).join(', ') || '—')}</td>
          <td data-label="Створено">${esc(l.created)}</td>
          <td data-label="Сайтів">${l.stats.ok}${l.stats.queued ? ' <small>(+' + l.stats.queued + ' у черзі)</small>' : ''}</td>
          <td data-label="Надіслано"><span class="cmsf-ok">${l.stats.sent}</span>${l.stats.sent_fail ? ' / <span class="cmsf-bad">' + l.stats.sent_fail + ' помилок</span>' : ''}</td>
          <td class="c-act"><button class="button l-open">Відкрити</button> <button class="button l-ren">Перейменувати</button> <button class="button l-del">Видалити</button></td></tr>`).join('') + '</tbody></table></div>';
    }
    let lv = null;
    function openList(id, name) {
      box.hidden = true; open.hidden = false; $('#cmsf-open-title').textContent = name || 'Список';
      lv = createListView($('#cmsf-listview'), id);
    }
    box.addEventListener('click', async e => {
      const tr = e.target.closest('tr[data-id]'); if (!tr) return;
      const id = Number(tr.dataset.id), name = tr.querySelector('.l-open b').textContent;
      if (e.target.closest('.l-open')) { e.preventDefault(); openList(id, name); }
      else if (e.target.closest('.l-ren')) { const n = prompt('Нова назва списку:', name); if (n) { await api('list_rename', { list_id: id, name: n }); render(); } }
      else if (e.target.closest('.l-del')) { if (confirm('Видалити список «' + name + '» разом з усіма сайтами та історією відправок?')) { await api('list_delete', { list_id: id }); render(); } }
    });
    $('#cmsf-back').onclick = () => { open.hidden = true; box.hidden = false; $('#cmsf-listview').innerHTML = ''; render(); };
    render().then(() => { if (C.openList) { const tr = $('tr[data-id="' + C.openList + '"]', box); if (tr) openList(C.openList, tr.querySelector('.l-open b').textContent); } }).catch(e => { box.textContent = e.message; });
  }

  // ============ СТОРІНКА «ШАБЛОНИ» ============
  function initTemplates() {
    const form = $('#cmsf-tpl-form'), msg = $('#cmsf-tpl-msg');
    let tpls = [];
    const say = (t, bad) => { msg.textContent = t; msg.className = 'cmsf-msg' + (bad ? ' cmsf-bad' : ''); };
    const get = () => ({ id: form.elements.id.value, name: form.elements.name.value, topic: form.elements.topic.value, subject: form.elements.subject.value, body: form.elements.body.value, is_html: form.elements.is_html.checked ? 1 : '' });
    function fill(t) {
      form.elements.id.value = t ? t.id : 0; form.elements.name.value = t ? t.name : ''; form.elements.topic.value = t ? t.topic : '';
      form.elements.subject.value = t ? t.subject : ''; form.elements.body.value = t ? t.body : ''; form.elements.is_html.checked = !!(t && Number(t.is_html));
      $('#cmsf-tpl-prev').hidden = true; say('');
      $$('.cmsf-tpl-item').forEach(i => i.classList.toggle('active', t && i.dataset.id === String(t.id)));
    }
    async function load(selectId) {
      tpls = await api('tpls');
      const groups = {};
      tpls.forEach(t => { (groups[t.topic || 'Без теми'] = groups[t.topic || 'Без теми'] || []).push(t); });
      $('#cmsf-tpl-groups').innerHTML = Object.keys(groups).map(g => `<h3>${esc(g)}</h3>` + groups[g].map(t => `<div class="cmsf-tpl-item" data-id="${t.id}">${esc(t.name)}</div>`).join('')).join('') || '<p>Шаблонів ще немає.</p>';
      $('#cmsf-topics').innerHTML = Array.from(new Set(tpls.map(t => t.topic).filter(Boolean))).map(t => `<option value="${esc(t)}">`).join('');
      const t = tpls.find(x => String(x.id) === String(selectId));
      if (t) fill(t);
    }
    $('#cmsf-tpl-groups').addEventListener('click', e => { const i = e.target.closest('.cmsf-tpl-item'); if (i) fill(tpls.find(t => String(t.id) === i.dataset.id)); });
    $('#cmsf-tpl-new').onclick = () => fill(null);
    async function save(asNew) {
      try { const d = get(); if (asNew) d.id = 0; const r = await api('tpl_save', d); await load(r.id); say('Збережено'); }
      catch (e) { say(e.message, true); }
    }
    $('#cmsf-tpl-save').onclick = () => save(false);
    $('#cmsf-tpl-saveas').onclick = () => save(true);
    $('#cmsf-tpl-delete').onclick = async () => {
      const id = form.elements.id.value; if (!Number(id)) return;
      if (confirm('Видалити шаблон?')) { await api('tpl_delete', { id }); await load(); fill(null); }
    };
    $('#cmsf-tpl-preview').onclick = async () => {
      try {
        const r = await api('tpl_preview', get()), box = $('#cmsf-tpl-prev');
        box.innerHTML = '<div class="cmsf-sub">Приклад для сайту example.com.ua (Joomla 3.10.12)</div><h3>' + esc(r.subject) + '</h3>' + (r.is_html ? '<div>' + r.body + '</div>' : '<pre>' + esc(r.body) + '</pre>');
        box.hidden = false;
      } catch (e) { say(e.message, true); }
    };
    load().then(() => { if (tpls[0]) fill(tpls[0]); });
  }

  // ============ СТОРІНКА «НАЛАШТУВАННЯ» ============
  function initSettings() {
    const form = $('#cmsf-settings-form');
    const data = () => { const o = {}; new FormData(form).forEach((v, k) => { o[k] = v; }); return o; };
    const say = (el, t, bad) => { el.textContent = t; el.className = 'cmsf-msg' + (bad ? ' cmsf-bad' : ' cmsf-ok'); };
    $('#cmsf-settings-save').onclick = () => api('save_settings', data()).then(m => say($('#cmsf-settings-msg'), m)).catch(e => say($('#cmsf-settings-msg'), e.message, true));
    $('#cmsf-refresh-latest').onclick = () => { const m = $('#cmsf-latest-msg'); say(m, 'Запит…'); api('refresh_latest').then(c => say(m, 'Joomla: ' + (c.Joomla || 'не визначено') + ' · WordPress: ' + (c.WordPress || 'не визначено') + ' · Drupal: ' + (c.Drupal || 'не визначено'))).catch(e => say(m, e.message, true)); };
    $('#cmsf-test-send').onclick = () => { const m = $('#cmsf-test-msg'); say(m, 'Відправка…'); api('test_mail', { to: $('#cmsf-test-to').value }).then(t => say(m, t)).catch(e => say(m, e.message, true)); };
  }

  document.addEventListener('DOMContentLoaded', () => {
    const page = $('.cmsf') && $('.cmsf').dataset.page;
    if (page === 'search' || page === 'import') initSearch(page);
    else if (page === 'lists') initLists();
    else if (page === 'templates') initTemplates();
    else if (page === 'settings') initSettings();
  });
})();
