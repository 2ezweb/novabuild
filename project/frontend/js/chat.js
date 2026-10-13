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

  document.getElementById('message-input').focus();

  try {
    otherUser = await apiFetch(`/conversations.php?id=${conversationId}`);
  } catch (e) {
    showError('chat-error', e.message);
    return;
  }

  const otherName = [otherUser.first_name, otherUser.last_name].filter(Boolean).join(' ') || 'Собеседник';
  renderAvatarEl(document.getElementById('chat-avatar'), otherName, otherUser.avatar_path);
  document.getElementById('chat-title').textContent = otherName;

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

  const body = document.createElement('div');
  body.textContent = m.body;
  bubble.appendChild(body);

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
  const text = input.value.trim();
  if (!text) return;

  try {
    const res = await apiPost('/messages.php', { conversation_id: conversationId, body: text });
    appendMessage({ id: res.id, sender_id: currentUser.id, body: text, created_at: res.created_at });
    input.value = '';
  } catch (e) {
    showError('chat-error', e.message);
  }
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
  proposed:  'Предложен',
  active:    'Активен',
  completed: 'Выполнен',
  failed:    'Не выполнен',
  rejected:  'Отклонён',
};

function renderContractCards() {
  const el = document.getElementById('contract-cards');
  el.innerHTML = contracts.length
    ? contracts.map(contractChatCard).join('')
    : '<p class="text-muted small mb-0">Контрактов пока нет.</p>';
}

function contractChatCard(c) {
  const badge  = `<span class="badge ${CONTRACT_STATUS_BADGE[c.status] || 'bg-secondary'} flex-shrink-0">${esc(MINI_STATUS[c.status] || c.status)}</span>`;
  const staged = c.type === 'staged';
  const title  = staged
    ? `📄 Поэтапный #${esc(c.number || c.id)}`
    : `📄 Контракт #${esc(c.number || c.id)}`;
  const sub = staged
    ? `${(c.stages || []).length} эт. · ${esc(formatUAH(c.price))}`
    : `${esc(formatUAH(c.price))}${c.deadline ? ' · до ' + esc(c.deadline) : ''}`;
  const escrow = c.status === 'active' && Number(c.escrowed_amount) >= 0 && !staged
    ? `<div class="text-muted mt-1" style="font-size:.8rem">эскроу: ${esc(formatUAH(c.escrowed_amount))} / ${esc(formatUAH(c.price))}</div>`
    : (staged && Number(c.escrowed_amount) > 0
        ? `<div class="text-muted mt-1" style="font-size:.8rem">в эскроу: ${esc(formatUAH(c.escrowed_amount))}</div>` : '');

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
      ? '<div class="text-primary mt-1" style="font-size:.8rem">⏳ ждёт вашего решения</div>'
      : '<div class="text-muted mt-1" style="font-size:.8rem">ждёт ответа фрилансера</div>';
  }
  if (c.status === 'active' && c.type !== 'staged') {
    if (currentUser.role === 'client') {
      const remaining = Math.max(0, Number(c.price) - Number(c.escrowed_amount || 0));
      if (remaining > 0) {
        return `<div class="text-primary mt-1" style="font-size:.8rem">💳 к оплате: ${esc(formatUAH(remaining))}</div>`;
      }
    } else {
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
  const hasOpen = contracts.some(c => c.status === 'proposed' || c.status === 'active');
  btn.style.display = (currentUser.role === 'client' && !hasOpen) ? '' : 'none';
}

// ─── PROPOSE A CONTRACT (chat-only entry point) ─────────────────────────────
let clientOpenOffers = [];   // this client's own OPEN offers, for the picker below

async function loadClientOffers() {
  if (currentUser.role !== 'client') return;
  try {
    const offers = await apiFetch('/offers.php');
    // "Открытый" = still open AND not already matched with someone on another contract.
    clientOpenOffers = offers.filter(o => o.status === 'open' && !o.is_outdated);
  } catch {
    clientOpenOffers = [];
  }
}

function renderProposeOfferOptions() {
  const sel = document.getElementById('propose-offer-select');
  sel.innerHTML = '<option value="">— без привязки к офферу —</option>'
    + clientOpenOffers.map(o => `<option value="${o.id}">${esc(o.title)}${o.budget ? ' — ' + esc(formatUAH(o.budget)) : ''}</option>`).join('');
}

// Pull price + deadline from the selected offer into the form. The user can
// still edit either field afterwards — this is just a shortcut, not a lock.
function applyOfferToProposeForm(offerId) {
  const o = clientOpenOffers.find(x => x.id === offerId);
  if (!o) return;
  if (o.budget) document.getElementById('propose-price').value = o.budget;
  document.getElementById('propose-deadline').value = o.deadline || '';
}

function onProposeOfferChange() {
  const offerId = Number(document.getElementById('propose-offer-select').value) || null;
  if (offerId) applyOfferToProposeForm(offerId);
}

async function openProposeModal() {
  hideError('propose-error');
  document.getElementById('propose-price').value = '';
  document.getElementById('propose-deadline').value = '';
  document.getElementById('propose-stage-title').value = '';
  document.getElementById('propose-type-full').checked = true;
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

function onProposeTypeChange() {
  const staged = proposeType() === 'staged';
  document.getElementById('propose-stage-title-wrap').style.display = staged ? '' : 'none';
  document.getElementById('propose-price-label').textContent    = staged ? 'Цена первого этапа, ₴' : 'Цена, ₴';
  document.getElementById('propose-deadline-label').innerHTML   = staged
    ? 'Срок первого этапа <span class="text-muted small">(опционально)</span>'
    : 'Срок <span class="text-muted small">(опционально)</span>';
  document.getElementById('propose-type-hint').textContent = staged
    ? 'Договор за первый этап; следующие формируются по мере выполнения. Каждый этап — мини-контракт.'
    : 'Договорились за цену и срок, сделали работу — закрыли.';
}

async function submitPropose() {
  hideError('propose-error');
  const type = proposeType();
  const price = Number(val('propose-price'));
  const deadline = val('propose-deadline');
  const materials = document.querySelector('input[name="propose-materials"]:checked')?.value || null;
  const stageTitle = val('propose-stage-title');
  const offerId = Number(document.getElementById('propose-offer-select').value) || null;

  if (!price || price <= 0) return showError('propose-error', 'Укажите цену');
  if (type === 'staged' && !stageTitle) return showError('propose-error', 'Укажите название первого этапа');

  const payload = {
    action: 'propose',
    conversation_id: conversationId,
    type,
    price,
    deadline: deadline || null,
    materials_paid_by: materials,
    offer_id: offerId,
  };
  if (type === 'staged') payload.stage_title = stageTitle;

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
