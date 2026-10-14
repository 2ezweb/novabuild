let currentUser = null;
let conversationId = null;
let contextOfferId = null;
let otherUser = null;
let contracts = [];              // top-level contracts in this conversation, newest first (staged parents carry .stages)
let renderedIds = new Set();
let pollTimer = null;
let contractsTimer = null;

// getContract must also resolve stage ids nested under a staged parent.
function getContractById(id) {
  id = Number(id);
  for (const c of contracts) {
    if (c.id === id) return c;
    if (c.stages) { const s = c.stages.find(x => x.id === id); if (s) return s; }
  }
  return null;
}

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client' && currentUser.role !== 'freelancer') {
    location.href = 'dashboard.html';
    return;
  }

  const params = new URLSearchParams(location.search);
  conversationId = Number(params.get('conversation_id'));
  contextOfferId = Number(params.get('offer_id')) || null;
  if (!conversationId) {
    location.href = 'dashboard.html';
    return;
  }

  ContractModal.init({
    me: currentUser,
    getContract: getContractById,
    reload: loadContracts,
    showChatLink: false,          // already in the conversation
  });

  // «Звернутися до підтримки» — the admin sees which conversation it came from.
  SupportWidget.init({ me: currentUser, conversationId });

  document.getElementById('message-input').focus();

  try {
    otherUser = await apiFetch(`/conversations.php?id=${conversationId}`);
  } catch (e) {
    showError('chat-error', e.message);
    return;
  }

  const otherName = [otherUser.first_name, otherUser.last_name].filter(Boolean).join(' ') || 'Співрозмовник';
  renderAvatarEl(document.getElementById('chat-avatar'), otherName, otherUser.avatar_path);
  document.getElementById('chat-title').textContent = otherName;
  loadOtherBrigade();

  await loadMessages();
  await loadContracts();
  setupRealtime();

  // Contract events by the other party don't arrive over the message channel
  // — poll them on a slow timer regardless of realtime state.
  contractsTimer = setInterval(loadContracts, 10000);
});

// ─── MESSAGES ─────────────────────────────────────────────────────────────────
async function loadMessages() {
  try {
    const messages = await apiFetch(`/messages.php?conversation_id=${conversationId}`);
    messages.forEach(appendMessage);
  } catch (e) {
    showError('chat-error', e.message);
  }
}

function appendMessage(m) {
  if (renderedIds.has(m.id)) return;
  renderedIds.add(m.id);

  const list = document.getElementById('messages-list');
  const mine = m.sender_id === currentUser.id;
  const bubble = document.createElement('div');
  bubble.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');

  if (m.body) {
    const body = document.createElement('div');
    body.textContent = m.body;
    bubble.appendChild(body);
  }

  // Files/photos (message_attachments) — thumbnails open the fullscreen gallery.
  if (m.attachments && m.attachments.length) {
    const files = document.createElement('div');
    files.className = 'chat-attachments' + (m.body ? ' mt-2' : '');
    files.innerHTML = attachmentsHtml(m.attachments);
    bubble.appendChild(files);
  }

  const time = document.createElement('span');
  time.className = 'chat-time';
  time.textContent = timeAgo(m.created_at);
  bubble.appendChild(time);

  list.appendChild(bubble);
  list.scrollTop = list.scrollHeight;
}

async function sendMessage(event) {
  event.preventDefault();
  const input = document.getElementById('message-input');
  const fileInput = document.getElementById('message-files');
  const text = input.value.trim();
  const files = fileInput.files;
  if (!text && !files.length) return;
  hideError('chat-error');

  const fileErr = checkUploadSelection(files);
  if (fileErr) return showError('chat-error', fileErr);

  const sendBtn = document.getElementById('message-send-btn');
  sendBtn.disabled = true;
  try {
    let res;
    if (files.length) {
      const fd = new FormData();
      fd.append('conversation_id', conversationId);
      fd.append('body', text);
      for (const f of files) fd.append('files[]', f);
      res = await apiUpload('/messages.php', fd);
      if (res.attachments.length < files.length) {
        showError('chat-error', `${files.length - res.attachments.length} файл(ів) не пройшли перевірку формату й не надіслані.`);
      }
    } else {
      res = await apiPost('/messages.php', { conversation_id: conversationId, body: text });
    }
    appendMessage(res);
    input.value = '';
    clearChatFiles();
  } catch (e) {
    showError('chat-error', e.message);
  } finally {
    sendBtn.disabled = false;
  }
}

// 📎 picker: shows what's attached under the input until the message is sent.
function onChatFilesChange() {
  const files = Array.from(document.getElementById('message-files').files);
  const box = document.getElementById('message-files-preview');
  if (!files.length) { box.innerHTML = ''; return; }
  box.innerHTML = files.map(f => `<span class="badge bg-light text-dark border me-1">${fileIcon(f.type || '')} ${esc(truncateText(f.name, 30))}</span>`).join('')
    + `<button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="clearChatFiles()">прибрати</button>`;
}

function clearChatFiles() {
  document.getElementById('message-files').value = '';
  document.getElementById('message-files-preview').innerHTML = '';
}

// ─── REALTIME: Pusher if configured, plain polling fallback otherwise ────────
async function setupRealtime() {
  let cfg;
  try {
    cfg = await apiFetch('/pusher_config.php');
  } catch {
    cfg = { configured: false };
  }

  if (cfg.configured && window.Pusher) {
    const pusher = new Pusher(cfg.key, {
      cluster: cfg.cluster,
      authEndpoint: API + '/pusher_auth.php',
      auth: { headers: { Authorization: 'Bearer ' + localStorage.getItem('token') } },
    });
    const channel = pusher.subscribe(`private-conversation-${conversationId}`);
    channel.bind('new-message', appendMessage);
  } else {
    pollTimer = setInterval(pollNewMessages, 4000);
  }
}

async function pollNewMessages() {
  const lastId = Math.max(0, ...[...renderedIds]);
  try {
    const messages = await apiFetch(`/messages.php?conversation_id=${conversationId}&after_id=${lastId}`);
    messages.forEach(appendMessage);
  } catch { /* non-critical, try again next tick */ }
}

// ─── CONTRACTS ────────────────────────────────────────────────────────────────
async function loadContracts() {
  try {
    contracts = await apiFetch(`/contracts.php?conversation_id=${conversationId}`);
  } catch (e) {
    contracts = [];
  }
  renderContractCards();
  renderProposeButton();
}

// Short status labels for the narrow sidebar badge (full labels stay in the modal).
const MINI_STATUS = {
  proposed:  'Запропоновано',
  active:    'Активний',
  completed: 'Виконано',
  failed:    'Не виконано',
  rejected:  'Відхилено',
};

// The same client and freelancer can run several contracts in parallel, so the
// sidebar splits them: everything still in play (proposed + active) on top,
// proposals waiting on the freelancer first; finished ones folded underneath.
let doneContractsOpen = false;   // remembers the fold across the 10-second refresh

function renderContractCards() {
  const el = document.getElementById('contract-cards');
  if (!contracts.length) {
    el.innerHTML = '<p class="text-muted small mb-0">Контрактів поки немає.</p>';
    return;
  }
  const open = contracts
    .filter(c => c.status === 'proposed' || c.status === 'active')
    .sort((a, b) => (a.status === 'proposed' ? 0 : 1) - (b.status === 'proposed' ? 0 : 1) || b.id - a.id);
  const done = contracts.filter(c => c.status !== 'proposed' && c.status !== 'active');

  let html = `<div class="text-muted small fw-semibold mb-1">В роботі (${open.length})</div>`;
  html += open.length
    ? open.map(contractChatCard).join('')
    : '<p class="text-muted small">Активних контрактів немає.</p>';
  if (done.length) {
    html += `<details class="mt-2" ${doneContractsOpen ? 'open' : ''} ontoggle="doneContractsOpen = this.open">
      <summary class="text-muted small fw-semibold mb-1">Завершені (${done.length})</summary>
      ${done.map(contractChatCard).join('')}
    </details>`;
  }
  el.innerHTML = html;
}

function contractChatCard(c) {
  const badge  = `<span class="badge ${CONTRACT_STATUS_BADGE[c.status] || 'bg-secondary'} flex-shrink-0">${esc(MINI_STATUS[c.status] || c.status)}</span>`;
  const staged = c.type === 'staged';
  const name   = contractName(c);
  const title  = (staged ? `📄 Поетапний #${esc(c.number || c.id)}` : `📄 Контракт #${esc(c.number || c.id)}`)
    + (name ? `<div class="text-truncate" title="${esc(name)}">${esc(name)}</div>` : '')
    + (c.brigade_name ? `<div class="text-truncate text-muted" style="font-size:.75rem">👷 бригада «${esc(c.brigade_name)}»</div>` : '');
  const sub = staged
    ? `${c.stage_mode === 'planned' ? 'план · ' : ''}${(c.stages || []).length} ет. · ${esc(formatUAH(c.price))}`
    : `${esc(formatUAH(c.price))}${c.deadline ? ' · до ' + esc(c.deadline) : ''}`;
  const escrow = c.status === 'active' && Number(c.escrowed_amount) >= 0 && !staged
    ? `<div class="text-muted mt-1" style="font-size:.8rem">ескроу: ${esc(formatUAH(c.escrowed_amount))} / ${esc(formatUAH(c.price))}</div>`
    : (staged && Number(c.escrowed_amount) > 0
        ? `<div class="text-muted mt-1" style="font-size:.8rem">в ескроу: ${esc(formatUAH(c.escrowed_amount))}</div>` : '');

  return `
    <div class="card mb-2 p-2 border-primary" role="button" onclick="openContractModal(${c.id})">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div style="min-width:0">
          <div class="fw-semibold small text-truncate">${title}</div>
          <div class="text-muted" style="font-size:.8rem">${sub}</div>
          ${escrow}
          ${contractChatHint(c)}
        </div>
        ${badge}
      </div>
    </div>`;
}

function contractChatHint(c) {
  if (c.status === 'proposed') {
    return currentUser.role === 'freelancer'
      ? '<div class="text-primary mt-1" style="font-size:.8rem">⏳ чекає на ваше рішення</div>'
      : '<div class="text-muted mt-1" style="font-size:.8rem">чекає на відповідь фрилансера</div>';
  }
  if (c.status === 'active' && c.type !== 'staged') {
    if (currentUser.role === 'client') {
      const remaining = Math.max(0, Number(c.price) - Number(c.escrowed_amount || 0));
      if (remaining > 0 && !c.deposit_ready) {
        // Deposit opens only after the estimate (роботи + матеріали) — escrow.php.
        return '<div class="text-muted mt-1" style="font-size:.8rem">📋 чекає на кошторис — потім депозит</div>';
      }
      if (remaining > 0) {
        return `<div class="text-primary mt-1" style="font-size:.8rem">💳 до сплати: ${esc(formatUAH(remaining))}</div>`;
      }
    } else {
      if (!c.deposit_ready && Number(c.escrowed_amount || 0) <= 0) {
        return '<div class="text-primary mt-1" style="font-size:.8rem">📋 складіть кошторис — без нього клієнт не внесе депозит</div>';
      }
      // Freelancer: whether the client actually paid the reserve, at a glance —
      // no reason to start work before the deposit is in (see contract-actions.js paymentBadgeHtml).
      const badge = paymentBadgeHtml(c);
      if (badge) return `<div class="mt-1">${badge}</div>`;
    }
  }
  return '';
}

function renderProposeButton() {
  const btn = document.getElementById('propose-contract-btn');
  if (!btn) return;
  // Parallel contracts are allowed — the client can always propose another one.
  btn.style.display = currentUser.role === 'client' ? '' : 'none';
}

// ─── BRIGADE of the freelancer in this chat (api/brigades.php of_user) ───────
// If he leads a brigade, the client may address the contract to the whole
// brigade (propose to_brigade) — the бригадир then splits the pay.
let otherBrigade = null;

async function loadOtherBrigade() {
  if (currentUser.role !== 'client' || !otherUser) return;
  try {
    otherBrigade = (await apiFetch(`/brigades.php?action=of_user&user_id=${otherUser.other_user_id}`)).brigade;
  } catch { otherBrigade = null; }
  const wrap = document.getElementById('propose-brigade-wrap');
  if (!otherBrigade) { wrap.style.display = 'none'; return; }
  wrap.style.display = '';
  document.getElementById('propose-brigade-label').textContent = `👷 Запропонувати бригаді «${otherBrigade.name}» (${otherBrigade.member_count} ос.)`;
  const roles = otherBrigade.members.map(m => m.role ? `${m.name} — ${m.role}` : m.name);
  document.getElementById('propose-brigade-info').textContent =
    'Ваш співрозмовник — бригадир. Замовлення отримає вся бригада, а бригадир розподілить оплату між учасниками.'
    + (roles.length ? ' Склад: ' + roles.join(', ') + '.' : '');
}

// ─── PROPOSE A CONTRACT (chat-only entry point) ─────────────────────────────
let clientOpenOffers = [];   // this client's own OPEN offers, for the picker below

async function loadClientOffers() {
  if (currentUser.role !== 'client') return;
  try {
    const offers = await apiFetch('/offers.php');
    // "Открытый" = still open AND not already matched with someone on another contract,
    // AND not already the subject of an open contract right here (API refuses that duplicate).
    const busyOfferIds = new Set(contracts
      .filter(c => c.offer_id && (c.status === 'proposed' || c.status === 'active'))
      .map(c => Number(c.offer_id)));
    clientOpenOffers = offers.filter(o => o.status === 'open' && !o.is_outdated && !busyOfferIds.has(Number(o.id)));
  } catch {
    clientOpenOffers = [];
  }
}

function renderProposeOfferOptions() {
  const sel = document.getElementById('propose-offer-select');
  sel.innerHTML = '<option value="">— без прив\'язки до офера —</option>'
    + clientOpenOffers.map(o => `<option value="${o.id}">${esc(o.title)}${o.budget ? ' — ' + esc(formatUAH(o.budget)) : ''}</option>`).join('');
}

// Pull price + deadline from the selected offer into the form. The user can
// still edit either field afterwards — this is just a shortcut, not a lock.
// The contract name is filled from the offer title too — but only while the
// user hasn't typed their own (tracked via the last value we put there).
let proposeAutoTitle = '';

function applyOfferToProposeForm(offerId) {
  const o = clientOpenOffers.find(x => x.id === offerId);
  if (!o) return;
  if (o.budget) document.getElementById('propose-price').value = o.budget;
  document.getElementById('propose-deadline').value = o.deadline || '';
  const titleInput = document.getElementById('propose-title');
  if (!titleInput.value.trim() || titleInput.value === proposeAutoTitle) {
    titleInput.value = proposeAutoTitle = o.title || '';
  }
}

function onProposeOfferChange() {
  const offerId = Number(document.getElementById('propose-offer-select').value) || null;
  if (offerId) applyOfferToProposeForm(offerId);
}

async function openProposeModal() {
  hideError('propose-error');
  document.getElementById('propose-title').value = '';
  document.getElementById('propose-to-brigade').checked = false;
  proposeAutoTitle = '';
  document.getElementById('propose-price').value = '';
  document.getElementById('propose-deadline').value = '';
  document.getElementById('propose-stage-title').value = '';
  document.getElementById('propose-type-full').checked = true;
  document.getElementById('propose-mode-incremental').checked = true;
  document.getElementById('propose-plan-rows').innerHTML = '';
  onProposeTypeChange();

  await loadClientOffers();
  renderProposeOfferOptions();

  // If this chat was opened from a specific offer's bid, preselect it —
  // still only when that offer is actually open (otherwise leave unset).
  const sel = document.getElementById('propose-offer-select');
  if (contextOfferId && clientOpenOffers.some(o => o.id === contextOfferId)) {
    sel.value = String(contextOfferId);
    applyOfferToProposeForm(contextOfferId);
  } else {
    sel.value = '';
  }

  bootstrap.Modal.getOrCreateInstance(document.getElementById('proposeModal')).show();
}

function proposeType() {
  return document.querySelector('input[name="propose-type"]:checked')?.value || 'full';
}

// Staged only: 'incremental' (stage 1 now, the rest as you go) or 'planned'
// (the whole plan of stages up front — see api/contracts.php propose).
function proposeMode() {
  if (proposeType() !== 'staged') return null;
  return document.querySelector('input[name="propose-mode"]:checked')?.value || 'incremental';
}

function onProposeModeChange() {
  const planned = proposeMode() === 'planned';
  document.getElementById('propose-single-wrap').style.display = planned ? 'none' : '';
  document.getElementById('propose-plan-wrap').style.display = planned ? '' : 'none';
  // Start the plan with two empty stages; carry over what was typed for stage 1.
  if (planned && !document.querySelectorAll('#propose-plan-rows tr').length) {
    addPlanRow({ title: val('propose-stage-title'), price: val('propose-price'), deadline: val('propose-deadline') });
    addPlanRow();
  }
}

function planRowHtml(s) {
  s = s || { title: '', price: '', deadline: '' };
  return `
    <tr>
      <td class="text-muted small plan-num"></td>
      <td><input class="form-control form-control-sm plan-title" maxlength="255" value="${esc(s.title || '')}" placeholder="Демонтаж, чорнові роботи..."></td>
      <td><input type="number" min="1" class="form-control form-control-sm plan-price" value="${esc(s.price || '')}" oninput="recalcPlan()"></td>
      <td><input type="date" class="form-control form-control-sm plan-deadline" value="${esc(s.deadline || '')}"></td>
      <td><button type="button" class="btn-close" title="Прибрати етап" onclick="this.closest('tr').remove(); recalcPlan()"></button></td>
    </tr>`;
}

function addPlanRow(s) {
  document.getElementById('propose-plan-rows').insertAdjacentHTML('beforeend', planRowHtml(s));
  recalcPlan();
}

function recalcPlan() {
  let total = 0;
  document.querySelectorAll('#propose-plan-rows tr').forEach((tr, i) => {
    tr.querySelector('.plan-num').textContent = i + 1;
    total += Number(tr.querySelector('.plan-price').value) || 0;
  });
  document.getElementById('propose-plan-total').textContent = 'Разом: ' + formatUAH(total);
}

function readPlan() {
  return Array.from(document.querySelectorAll('#propose-plan-rows tr')).map(tr => ({
    title: tr.querySelector('.plan-title').value.trim(),
    price: Number(tr.querySelector('.plan-price').value) || 0,
    deadline: tr.querySelector('.plan-deadline').value || null,
  }));
}

function onProposeTypeChange() {
  const staged = proposeType() === 'staged';
  document.getElementById('propose-mode-wrap').style.display = staged ? '' : 'none';
  if (!staged) {
    document.getElementById('propose-single-wrap').style.display = '';
    document.getElementById('propose-plan-wrap').style.display = 'none';
  } else {
    onProposeModeChange();
  }
  document.getElementById('propose-stage-title-wrap').style.display = staged ? '' : 'none';
  document.getElementById('propose-price-label').textContent    = staged ? 'Ціна першого етапу, ₴' : 'Ціна, ₴';
  document.getElementById('propose-deadline-label').innerHTML   = staged
    ? 'Термін першого етапу <span class="text-muted small">(необов\'язково)</span>'
    : 'Термін <span class="text-muted small">(необов\'язково)</span>';
  document.getElementById('propose-type-hint').textContent = staged
    ? 'Домовляєтеся про перший етап; наступні формуються в міру виконання. Кожен етап — міні-контракт.'
    : 'Домовилися про ціну й термін, зробили роботу — закрили.';
}

async function submitPropose() {
  hideError('propose-error');
  const type = proposeType();
  const price = Number(val('propose-price'));
  const deadline = val('propose-deadline');
  const stageTitle = val('propose-stage-title');
  const offerId = Number(document.getElementById('propose-offer-select').value) || null;

  const mode = proposeMode();
  let plan = null;
  if (mode === 'planned') {
    plan = readPlan();
    if (plan.length < 2) return showError('propose-error', 'У плані має бути щонайменше 2 етапи');
    const badIdx = plan.findIndex(s => !s.title || s.price <= 0);
    if (badIdx >= 0) return showError('propose-error', `Етап ${badIdx + 1}: вкажіть назву та ціну`);
  } else {
    if (!price || price <= 0) return showError('propose-error', 'Вкажіть ціну');
    if (type === 'staged' && !stageTitle) return showError('propose-error', 'Вкажіть назву першого етапу');
  }

  const payload = {
    action: 'propose',
    conversation_id: conversationId,
    type,
    price,
    deadline: deadline || null,
    offer_id: offerId,
    title: val('propose-title'),
    to_brigade: otherBrigade ? document.getElementById('propose-to-brigade').checked : false,
  };
  if (type === 'staged') {
    payload.stage_mode = mode;
    if (plan) payload.stages = plan;
    else payload.stage_title = stageTitle;
  }

  try {
    await apiPost('/contracts.php', payload);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('proposeModal')).hide();
    document.getElementById('propose-price').value = '';
    document.getElementById('propose-deadline').value = '';
    document.getElementById('propose-stage-title').value = '';
    loadContracts();
  } catch (e) {
    showError('propose-error', e.message);
  }
}

// Смета (bill of materials) is now part of each contract/stage's own card —
// see contract-actions.js (cmEstimateSection and friends), shared with
// contracts.html. Nothing conversation-wide left to manage here.
