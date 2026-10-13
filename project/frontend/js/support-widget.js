// ─────────────────────────────────────────────────────────────────────────────
// «Звернутися до підтримки» — floating support window (bottom-right).
//
// SupportWidget.init({ conversationId }) once after requireAuth(); any element
// with onclick="SupportWidget.open()" opens it. Three options:
//   - «Мене обманули», «Проблема з транзакціями» — stubs for now;
//   - «Виклик адміністратора» — a temporary chat with the admins (api/support.php),
//     lives 3 days; admins see it as «🚨 Екстрена заявка».
// Polls every 4 s while open; every 20 s while closed (red dot on new replies).
// ─────────────────────────────────────────────────────────────────────────────
const SupportWidget = (() => {
  const OPEN_POLL_MS = 4000;
  const IDLE_POLL_MS = 20000;
  const STUBS = {
    scam:         { icon: '🕵️', title: 'Мене обманули' },
    transactions: { icon: '💳', title: 'Проблема з транзакціями' },
  };

  let ctx = { conversationId: null, me: null };
  let ticket = null;       // current ticket view from the API (or null)
  let lastId = 0;          // last rendered message id
  let seenId = 0;          // last message id the user has seen (for the red dot)
  let isOpen = false;
  let timer = null;

  function el(id) { return document.getElementById(id); }

  function hhmm(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
  }
  function ddmmHHMM(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleString('uk-UA', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  function injectMarkup() {
    if (el('sw-panel')) return;
    document.body.insertAdjacentHTML('beforeend', `
      <div id="sw-panel" class="support-panel shadow-lg" style="display:none" role="dialog" aria-label="Підтримка">
        <div class="support-panel-header">
          <button type="button" class="btn btn-sm btn-link text-white p-0 me-2" id="sw-back" style="display:none" onclick="SupportWidget.showMenu()" title="Назад">←</button>
          <span class="fw-semibold flex-grow-1" id="sw-title">Звернутися до підтримки</span>
          <button type="button" class="btn-close btn-close-white" onclick="SupportWidget.close()" title="Згорнути"></button>
        </div>
        <div class="support-panel-body" id="sw-body"></div>
        <form class="support-panel-input" id="sw-input" style="display:none" onsubmit="SupportWidget.send(event)">
          <input type="text" class="form-control form-control-sm" id="sw-text" maxlength="2000" autocomplete="off" placeholder="Повідомлення адміністратору...">
          <button class="btn btn-sm btn-danger" type="submit" id="sw-send">Надіслати</button>
        </form>
      </div>`);
  }

  function setChrome(title, { back = false, input = false } = {}) {
    el('sw-title').textContent = title;
    el('sw-back').style.display = back ? '' : 'none';
    el('sw-input').style.display = input ? '' : 'none';
  }

  // ─── views ──────────────────────────────────────────────────────────────────
  function showMenu() {
    if (ticket && ticket.status === 'open') return showTicket();
    setChrome('Звернутися до підтримки');
    el('sw-body').innerHTML = `
      <p class="small text-muted mb-2">Оберіть, що сталося:</p>
      <div class="d-grid gap-2">
        <button type="button" class="btn btn-outline-secondary text-start" onclick="SupportWidget.showStub('scam')">🕵️ Мене обманули</button>
        <button type="button" class="btn btn-outline-secondary text-start" onclick="SupportWidget.showStub('transactions')">💳 Проблема з транзакціями</button>
        <button type="button" class="btn btn-danger text-start" onclick="SupportWidget.showCallForm()">🛎 Виклик адміністратора</button>
      </div>`;
  }

  function showStub(key) {
    const s = STUBS[key];
    setChrome(`${s.icon} ${s.title}`, { back: true });
    el('sw-body').innerHTML = `
      <div class="alert alert-light border small">Розділ «${esc(s.title)}» ще в розробці.</div>
      <p class="small text-muted">Якщо справа термінова — викличте адміністратора: він підключиться до окремого чату з вами.</p>
      <button type="button" class="btn btn-danger btn-sm w-100" onclick="SupportWidget.showCallForm()">🛎 Виклик адміністратора</button>`;
  }

  function showCallForm() {
    setChrome('🛎 Виклик адміністратора', { back: true });
    el('sw-body').innerHTML = `
      <p class="small text-muted">Усі адміністратори отримають термінову заявку, і перший вільний підключиться до чату з вами. Чат діє 3 дні.</p>
      <textarea class="form-control form-control-sm mb-2" id="sw-first" rows="4" maxlength="2000" placeholder="Коротко опишіть, що сталося (необов'язково)"></textarea>
      <div id="sw-error" class="alert alert-danger small py-1 d-none"></div>
      <button type="button" class="btn btn-danger btn-sm w-100" id="sw-call-btn" onclick="SupportWidget.createTicket()">🛎 Викликати адміністратора</button>`;
  }

  function ticketStatusHtml() {
    if (ticket.status !== 'open') {
      return `<div class="support-status bg-secondary-subtle">${ticket.status === 'expired' ? '⌛ Термін дії заявки минув.' : '✔️ Заявку завершено.'}</div>`;
    }
    const admins = ticket.admins || [];
    return admins.length
      ? `<div class="support-status bg-success-subtle">✅ У чаті: ${admins.map(a => esc(a.name)).join(', ')}</div>`
      : `<div class="support-status bg-warning-subtle">⏳ Очікуємо адміністратора…</div>`;
  }

  function showTicket(messages) {
    const open = ticket.status === 'open';
    setChrome(`Заявка №${ticket.number}`, { input: open });
    el('sw-body').innerHTML = `
      ${ticketStatusHtml()}
      <div class="small text-muted text-center mb-2">${open ? 'Чат діє до ' + esc(ddmmHHMM(ticket.expires_at)) : ''}</div>
      <div id="sw-messages" class="support-messages"></div>
      ${open ? '' : '<button type="button" class="btn btn-outline-danger btn-sm w-100 mt-2" onclick="SupportWidget.newRequest()">Нове звернення</button>'}`;
    lastId = 0;
    if (messages) appendMessages(messages);
    if (open) setTimeout(() => el('sw-text')?.focus(), 0);
  }

  function messageHtml(m) {
    if (m.sender_role === 'system') {
      return `<div class="support-msg system">${esc(m.body)}</div>`;
    }
    const mine = ctx.me && m.sender_id === ctx.me.id;
    const who = m.sender_role === 'admin' ? `🛡 ${esc(m.sender_name || 'Адміністратор')}` : '';
    return `<div class="support-msg ${mine ? 'mine' : 'theirs'}">
      ${who ? `<div class="support-msg-author">${who}</div>` : ''}
      <div>${esc(m.body)}</div>
      <span class="support-msg-time">${esc(hhmm(m.created_at))}</span>
    </div>`;
  }

  function appendMessages(list) {
    const box = el('sw-messages');
    if (!box) return;
    const fresh = list.filter(m => m.id > lastId);
    if (!fresh.length) return;
    box.insertAdjacentHTML('beforeend', fresh.map(messageHtml).join(''));
    lastId = Math.max(lastId, ...fresh.map(m => m.id));
    if (isOpen) seenId = lastId;
    box.scrollTop = box.scrollHeight;
  }

  // ─── API ────────────────────────────────────────────────────────────────────
  async function createTicket() {
    hideError('sw-error');
    const btn = el('sw-call-btn');
    btn.disabled = true;
    try {
      const res = await apiPost('/support.php', {
        action: 'create',
        category: 'admin_call',
        conversation_id: ctx.conversationId,
        body: el('sw-first').value.trim(),
      });
      ticket = res.ticket;
      showTicket(res.messages);
      schedule();
    } catch (e) {
      showError('sw-error', e.message);
      btn.disabled = false;
    }
  }

  async function send(event) {
    event.preventDefault();
    const input = el('sw-text');
    const text = input.value.trim();
    if (!text || !ticket) return;
    el('sw-send').disabled = true;
    try {
      const res = await apiPost('/support.php', { action: 'send', ticket_id: ticket.id, body: text, after_id: lastId });
      input.value = '';
      appendMessages(res.messages);
    } catch (e) {
      alert(e.message);
      await poll();   // e.g. the ticket expired meanwhile — repaint its state
    } finally {
      el('sw-send').disabled = false;
    }
  }

  async function poll() {
    if (!ticket) return;
    try {
      const res = await apiFetch(`/support.php?action=messages&ticket_id=${ticket.id}&after_id=${lastId}`);
      const statusChanged = res.ticket.status !== ticket.status
        || (res.ticket.admins || []).length !== (ticket.admins || []).length;
      ticket = res.ticket;
      if (isOpen && statusChanged) {
        await repaintTicket();   // an admin joined / the ticket ended — redraw header + history
      } else if (isOpen) {
        appendMessages(res.messages);
      } else if (res.messages.length) {
        lastId = Math.max(lastId, ...res.messages.map(m => m.id));
      }
      updateDot();
      if (ticket.status !== 'open') stopTimer();
    } catch { /* network hiccup — next tick */ }
  }

  // Fetch the whole history + fresh ticket state and redraw the chat view.
  async function repaintTicket() {
    try {
      const full = await apiFetch(`/support.php?action=messages&ticket_id=${ticket.id}&after_id=0`);
      ticket = full.ticket;
      showTicket(full.messages);
      seenId = lastId;
    } catch {
      showTicket();
    }
  }

  function updateDot() {
    const unread = !isOpen && ticket && lastId > seenId;
    document.querySelectorAll('.support-launcher-dot').forEach(d => d.style.display = unread ? '' : 'none');
  }

  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }
  function schedule() {
    stopTimer();
    if (ticket && ticket.status === 'open') timer = setInterval(poll, isOpen ? OPEN_POLL_MS : IDLE_POLL_MS);
  }

  // ─── public ─────────────────────────────────────────────────────────────────
  async function init(options) {
    ctx = Object.assign({}, ctx, options);
    injectMarkup();
    try {
      const res = await apiFetch('/support.php?action=mine');
      if (res.ticket) {
        ticket = res.ticket;
        lastId = res.messages.length ? Math.max(...res.messages.map(m => m.id)) : 0;
        seenId = lastId;
        schedule();
      }
    } catch { /* widget still works — create() will tell if something's off */ }
  }

  async function open() {
    injectMarkup();
    isOpen = true;
    el('sw-panel').style.display = '';
    if (ticket && ticket.status === 'open') {
      await repaintTicket();
    } else {
      showMenu();
    }
    updateDot();
    schedule();
  }

  function close() {
    isOpen = false;
    el('sw-panel').style.display = 'none';
    updateDot();
    schedule();
  }

  function newRequest() {
    ticket = null;
    lastId = seenId = 0;
    stopTimer();
    showMenu();
  }

  return { init, open, close, showMenu, showStub, showCallForm, createTicket, send, newRequest };
})();
