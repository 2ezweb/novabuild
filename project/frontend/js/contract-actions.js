// ─────────────────────────────────────────────────────────────────────────────
// Shared contract detail modal + actions.
//
// Used by BOTH the contracts page (contracts.html) and the chat page
// (chat.html). Each host renders its own list of contract cards; clicking one
// calls openContractModal(id) from here, which opens #contractModal with the
// full contract, a contextual "⋮" actions menu, and the change / deposit /
// stage child dialogs.
//
// A "staged" contract is a parent row whose child rows (stages) are ordinary
// contracts with parent_contract_id set — each stage reuses the whole
// lifecycle. The parent has no price/escrow of its own; the API sends
// c.stages plus aggregate c.price / c.escrowed_amount.
//
// The host calls ContractModal.init({ me, getContract, reload, showChatLink })
// once. getContract(id) must also find stages (search c.stages).
// ─────────────────────────────────────────────────────────────────────────────

const CONTRACT_STATUS_LABELS = {
  proposed:  'Ожидает подтверждения',
  active:    'Активен',
  completed: 'Выполнен',
  failed:    'Не выполнен',
  rejected:  'Отклонён',
};
const CONTRACT_STATUS_BADGE = {
  proposed:  'bg-warning text-dark',
  active:    'bg-success',
  completed: 'bg-success',
  failed:    'bg-danger',
  rejected:  'bg-secondary',
};

let _cmCtx  = { me: null, getContract: () => null, reload: async () => {}, showChatLink: true };
let _cmOpen = null;      // contract currently shown in #contractModal
let _cmChange = null;    // its pending change request (active full/stage only)
let _cmHold = null;      // its dispute hold (failed full/stage + freelancer only)
let _cmEstimates = [];   // its own СМЕТА records, newest first (full/stage only, not the staged parent)
let _cmEscalation = null; // latest "вызов администратора" for THIS contract/stage, if any
let _cmSubmissions = [];  // сдача-приёмка работы, newest first (full/stage only)
let _cmReview = undefined; // client's review of a closed top-level contract: undefined = n/a, null = none yet

const ESCALATION_RESOLUTION_LABELS = {
  terms_changed:   'условия контракта изменены администрацией',
  closed_success:  'контракт закрыт с выплатой фрилансеру',
  closed_failure:  'контракт закрыт с возвратом депозита клиенту',
  frozen:          'контракт заморожен до выяснения обстоятельств',
};

function cmIsStagedParent(c) { return c.type === 'staged' && !c.parent_contract_id; }

// Whether a contract/stage's own escrow currently covers its price — null
// when the question doesn't apply (not active, or a staged parent, which
// holds no escrow of its own; look at its stages instead). Shared by the
// detail modal and both host pages' list cards so "оплачено ли" reads the
// same everywhere.
function paymentStatus(c) {
  if (c.status !== 'active') return null;
  if (cmIsStagedParent(c)) return null;
  const price = Number(c.price) || 0;
  if (price <= 0) return null;
  const escrowed = Number(c.escrowed_amount) || 0;
  if (escrowed >= price) return 'full';
  if (escrowed > 0) return 'partial';
  return 'none';
}

// Small role-agnostic badge for list cards (contracts.html, chat sidebar).
function paymentBadgeHtml(c) {
  const p = paymentStatus(c);
  if (p === 'full')    return '<span class="text-success" style="font-size:.75rem">✅ оплачено</span>';
  if (p === 'partial') return '<span class="text-warning" style="font-size:.75rem">⚠️ частично оплачено</span>';
  if (p === 'none')    return '<span class="text-danger" style="font-size:.75rem">❌ не оплачено</span>';
  return '';
}

function cmHeading(c) {
  if (c.parent_contract_id) return `Этап ${c.seq} · #${c.number || c.id}`;
  if (c.type === 'staged')  return `Поэтапный контракт #${c.number || c.id}`;
  return `Контракт #${c.number || c.id}`;
}

function contractModalInit(ctx) {
  _cmCtx = Object.assign({}, _cmCtx, ctx);

  // Bootstrap has no real stacked-modal support, so a child dialog closing
  // hops back to the contract modal (covers both "cancel" and "after submit").
  ['changeModal', 'depositModal', 'stageModal', 'estimateModal', 'escalateModal'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('hidden.bs.modal', () => {
      if (_cmOpen && !document.getElementById('contractModal').classList.contains('show')) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('contractModal')).show();
      }
    });
  });
}

async function openContractModal(id) {
  const c = _cmCtx.getContract(id);
  if (!c) return;
  _cmOpen = c;
  await cmLoadDetails(c);
  cmRender();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('contractModal')).show();
}

async function cmLoadDetails(c) {
  _cmChange = null;
  _cmHold = null;
  _cmEstimates = [];
  _cmEscalation = null;
  _cmSubmissions = [];
  _cmReview = undefined;
  // Отзыв — on a closed top-level contract (full, or the staged parent as a whole).
  if (!c.parent_contract_id && ['completed', 'failed'].includes(c.status)) {
    try { _cmReview = (await apiFetch(`/reviews.php?contract_id=${c.id}`)).review; } catch { _cmReview = undefined; }
  }
  if (cmIsStagedParent(c)) return;   // change/dispute/estimate/escalation/hand-over live on stages, not the parent
  if (c.status !== 'proposed') {
    try { _cmSubmissions = await apiFetch(`/work_submissions.php?contract_id=${c.id}`); } catch { /* keep [] */ }
  }
  if (c.status === 'active') {
    try { _cmChange = await apiFetch(`/contract_change_requests.php?contract_id=${c.id}`); } catch { /* keep null */ }
    try { _cmEscalation = await apiFetch(`/escalations.php?contract_id=${c.id}`); } catch { /* keep null */ }
  }
  if (c.status === 'failed' && _cmCtx.me.role === 'freelancer') {
    try { _cmHold = await apiFetch(`/disputes.php?contract_id=${c.id}`); } catch { /* keep null */ }
  }
  // Смета is part of the contract/stage — history stays visible after it closes.
  if (c.status !== 'proposed') {
    try { _cmEstimates = await apiFetch(`/contract_estimates.php?contract_id=${c.id}`); } catch { /* keep [] */ }
  }
}

// after a successful action: refetch host list, then repaint this modal
async function cmAfterMutation() {
  const id = _cmOpen && _cmOpen.id;
  await _cmCtx.reload();
  if (!id) return;
  const fresh = _cmCtx.getContract(id);
  if (!fresh) {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('contractModal')).hide();
    _cmOpen = null;
    return;
  }
  _cmOpen = fresh;
  await cmLoadDetails(fresh);
  cmRender();
}

function cmRender() {
  cmRenderBody(_cmOpen);
  cmRenderMenu(_cmOpen);
}

function cmRenderBody(c) {
  const otherName = [c.other_first_name, c.other_last_name_initial].filter(Boolean).join(' ') || 'Собеседник';
  document.getElementById('contract-modal-title').textContent = cmHeading(c);

  const chatLink = document.getElementById('contract-chat-link');
  if (chatLink) {
    if (_cmCtx.showChatLink) {
      chatLink.style.display = '';
      chatLink.href = `chat.html?conversation_id=${c.conversation_id}`;
    } else {
      chatLink.style.display = 'none';
    }
  }

  const typeRow = `<dt class="col-5 fw-normal text-muted">Тип</dt>
    <dd class="col-7 mb-2">${c.type === 'staged' ? 'поэтапный' : 'полный'}</dd>`;
  const parentRow = c.parent_contract_id
    ? `<dt class="col-5 fw-normal text-muted">Контракт</dt>
       <dd class="col-7 mb-2">#${esc(c.parent_number || c.parent_contract_id)}
         <button class="btn btn-link btn-sm p-0 ms-1 align-baseline" onclick="openContractModal(${c.parent_contract_id})">↑ открыть</button></dd>`
    : '';
  const offerRow = c.offer_id && _cmCtx.me.role === 'client'
    ? `<dt class="col-5 fw-normal text-muted">Оффер</dt><dd class="col-7 mb-2"><a href="offer.html?id=${c.offer_id}">#${c.offer_id}</a></dd>`
    : '';
  const createdRow = `<dt class="col-5 fw-normal text-muted">Создан</dt>
    <dd class="col-7 mb-2">${esc(cmFmtDate(c.created_at))}</dd>`;

  let dl;
  if (cmIsStagedParent(c)) {
    const stages = c.stages || [];
    const done = stages.filter(s => s.status === 'completed').length;
    dl = `
      ${typeRow}
      <dt class="col-5 fw-normal text-muted">Этапов</dt>
      <dd class="col-7 mb-2">${stages.length} <span class="text-muted">(завершено ${done})</span></dd>
      <dt class="col-5 fw-normal text-muted">Сумма по этапам</dt>
      <dd class="col-7 mb-2 fw-semibold">${esc(formatUAH(c.price))}</dd>
      ${Number(c.escrowed_amount) > 0 ? `<dt class="col-5 fw-normal text-muted">В эскроу</dt><dd class="col-7 mb-2">${esc(formatUAH(c.escrowed_amount))}</dd>` : ''}
      ${createdRow}
      ${offerRow}`;
  } else {
    dl = `
      ${parentRow}
      ${typeRow}
      <dt class="col-5 fw-normal text-muted">Цена</dt>
      <dd class="col-7 mb-2 fw-semibold">${esc(formatUAH(c.price))}</dd>
      <dt class="col-5 fw-normal text-muted">Срок</dt>
      <dd class="col-7 mb-2">${c.deadline ? esc(c.deadline) : '<span class="text-muted">не указан</span>'}</dd>
      ${c.status === 'active' ? `<dt class="col-5 fw-normal text-muted">В эскроу</dt><dd class="col-7 mb-2">${esc(formatUAH(c.escrowed_amount))} <span class="text-muted">из ${esc(formatUAH(c.price))}</span></dd>` : ''}
      ${createdRow}
      ${offerRow}`;
  }

  let html = `
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="badge ${CONTRACT_STATUS_BADGE[c.status] || 'bg-secondary'}">${esc(CONTRACT_STATUS_LABELS[c.status] || c.status)}</span>
      <span class="fw-semibold">${esc(otherName)}</span>
    </div>
    <dl class="row small mb-0">${dl}</dl>`;

  html += cmRenderExtra(c);
  document.getElementById('contract-modal-body').innerHTML = html;
}

function cmStagesSection(c) {
  const stages = c.stages || [];
  const rows = stages.map(s => `
    <div class="d-flex justify-content-between align-items-center gap-2 border rounded p-2 mb-1" role="button" onclick="openContractModal(${s.id})">
      <div style="min-width:0">
        <div class="small fw-semibold text-truncate">Этап ${s.seq}: ${esc(s.title || '—')}</div>
        <div class="text-muted" style="font-size:.8rem">${esc(formatUAH(s.price))}${s.deadline ? ' · до ' + esc(s.deadline) : ''}</div>
      </div>
      <span class="badge ${CONTRACT_STATUS_BADGE[s.status] || 'bg-secondary'} flex-shrink-0">${esc(CONTRACT_STATUS_LABELS[s.status] || s.status)}</span>
    </div>`).join('') || '<p class="text-muted small mb-1">Этапов пока нет.</p>';

  const last = stages[stages.length - 1];
  const canAdd = _cmCtx.me.role === 'client'
    && c.status === 'active'
    && (!last || ['completed', 'failed', 'rejected'].includes(last.status));
  let addBtn = '';
  if (canAdd) {
    addBtn = `<button class="btn btn-sm btn-outline-primary mt-1" onclick="cmOpenStage()">＋ Сформировать следующий этап</button>`;
  } else if (_cmCtx.me.role === 'client' && c.status === 'active') {
    addBtn = `<p class="text-muted small mt-1 mb-0">Следующий этап можно сформировать после завершения текущего.</p>`;
  }

  return `<div class="mt-3"><div class="fw-semibold small mb-2">Этапы</div>${rows}${addBtn}</div>`;
}

function cmRenderExtra(c) {
  const me = _cmCtx.me;

  if (cmIsStagedParent(c)) {
    let out = cmStagesSection(c);
    if (c.status === 'proposed')  out += `<div class="alert alert-primary mt-3 mb-0">Ждёт, пока фрилансер примет первый этап — откройте его в списке выше.</div>`;
    if (c.status === 'completed') out += `<div class="alert alert-success mt-3 mb-0">✅ Поэтапный контракт завершён.</div>`;
    if (c.status === 'failed')    out += `<div class="alert alert-danger mt-3 mb-0">Поэтапный контракт расторгнут. Незавершённые этапы закрыты, депозиты вернулись на баланс клиента с 7-дневной заморозкой.</div>`;
    if (c.status === 'rejected')  out += `<div class="alert alert-secondary mt-3 mb-0">Поэтапный контракт отклонён.</div>`;
    out += cmReviewSection(c);
    return out;
  }

  const isStage = !!c.parent_contract_id;
  const noun = isStage ? 'этап' : 'контракт';
  let out = '';

  if (c.frozen_at) {
    out += `<div class="alert alert-dark mt-3 mb-0">🧊 ${isStage ? 'Этап' : 'Контракт'} заморожен администрацией ${esc(cmFmtDate(c.frozen_at))} — идёт разбирательство, дождитесь решения.</div>`;
  }

  if (c.status === 'proposed') {
    out += me.role === 'freelancer'
      ? `<div class="alert alert-primary mt-3 mb-0">Вам предложили ${noun}. Через меню ⋮ вверху слева — подтвердить или отклонить.${isStage ? '' : ' Хотите другие условия — отклоните и обсудите их в переписке.'}</div>`
      : `<div class="alert alert-secondary mt-3 mb-0">Ожидает подтверждения фрилансером.</div>`;
  }

  if (c.status === 'active') {
    const pay = paymentStatus(c);
    if (pay === 'full') {
      out += `<div class="alert alert-success mt-3 mb-0">✅ Депозит внесён полностью — можно начинать работу.</div>`;
    } else if (pay === 'partial') {
      out += `<div class="alert alert-warning mt-3 mb-0">⚠️ Внесена часть депозита: ${esc(formatUAH(c.escrowed_amount))} из ${esc(formatUAH(c.price))}.${me.role === 'freelancer' ? ' Уточните с заказчиком остаток, прежде чем начинать работу.' : ''}</div>`;
    } else if (pay === 'none') {
      out += `<div class="alert alert-danger mt-3 mb-0">❌ Депозит ещё не внесён.${me.role === 'freelancer' ? ' Не рекомендуем начинать работу до оплаты.' : ' Клиенту нужно внести депозит.'}</div>`;
    }

    const remaining = Math.max(0, Number(c.price) - Number(c.escrowed_amount || 0));
    if (me.role === 'client' && remaining > 0) {
      out += `<button class="btn btn-primary w-100 mt-3" onclick="cmOpenDeposit()">💳 Оплатить ${noun} — осталось ${esc(formatUAH(remaining))}</button>`;
    }
    if (_cmChange) {
      const mine = Number(_cmChange.proposed_by) === me.id;
      const parts = [];
      if (_cmChange.new_price)    parts.push(`цена → ${formatUAH(_cmChange.new_price)}`);
      if (_cmChange.new_deadline) parts.push(`срок → ${_cmChange.new_deadline}`);
      const desc = esc(parts.join(', '));
      out += mine
        ? `<div class="alert alert-warning mt-3 mb-0">Вы предложили изменения (${desc}). Ожидает ответа второй стороны.</div>`
        : `<div class="alert alert-warning mt-3 mb-0">
             <div class="mb-2">Вам предложили изменить условия: ${desc}</div>
             <div class="d-flex gap-2">
               <button class="btn btn-sm btn-success" onclick="cmRespondChange(true)">Принять</button>
               <button class="btn btn-sm btn-outline-danger" onclick="cmRespondChange(false)">Отклонить</button>
             </div>
           </div>`;
    }
  }

  if (c.status === 'failed') {
    out += `<div class="alert alert-danger mt-3 mb-0">❌ ${isStage ? 'Этап' : 'Контракт'} закрыт как невыполненный.</div>`;
  }
  if (c.status === 'failed' && _cmHold) {
    out += _cmHold.disputed_at
      ? `<div class="alert alert-warning mt-3 mb-0">Спор подан, ожидает решения администратора.</div>`
      : `<div class="alert alert-secondary mt-3 mb-0">Депозит заморожен на стороне клиента до ${esc(_cmHold.release_at)}. Если считаете закрытие несправедливым — «Оспорить закрытие» в меню ⋮.</div>`;
  }

  if (c.status === 'completed') out += `<div class="alert alert-success mt-3 mb-0">✅ ${isStage ? 'Этап' : 'Контракт'} выполнен, оплата переведена фрилансеру.</div>`;
  if (c.status === 'rejected')  out += `<div class="alert alert-secondary mt-3 mb-0">${isStage ? 'Этап' : 'Контракт'} отклонён фрилансером.</div>`;

  // Сдача-приёмка: form/decision while active, history afterwards.
  if (c.status === 'active' || ((c.status === 'completed' || c.status === 'failed') && _cmSubmissions.length)) {
    out += cmSubmissionSection(c);
  }

  // Смета is part of this specific contract/stage — it never existed before
  // the contract went active, and its history stays visible after it closes.
  if (c.status === 'active' || c.status === 'completed' || c.status === 'failed') {
    out += cmEstimateSection(c);
  }

  if (!isStage) out += cmReviewSection(c);

  // «Вызов администратора» — a lever for genuine conflicts, only while the
  // contract/stage is still active (a closed one either already has a
  // decision or goes through the freelancer's post-close dispute instead).
  if (c.status === 'active') {
    out += cmEscalationSection(c);
  }

  // A finished stage is a dead end otherwise — nothing on its own card lets you
  // move forward. Offer the two real next steps right here instead of making
  // the user find the small "↑ открыть" link up to the parent first.
  if (isStage && ['completed', 'failed'].includes(c.status)) {
    out += cmStageNextStepPanel(c);
  }

  return out;
}

// Shown on a finished stage's own card, but only for the LATEST stage of an
// still-active staged contract — earlier stages already have a successor.
function cmStageNextStepPanel(c) {
  const parent = _cmCtx.getContract(c.parent_contract_id);
  if (!parent || parent.status !== 'active') return '';
  const stages = parent.stages || [];
  const last = stages[stages.length - 1];
  if (!last || last.id !== c.id) return '';

  const me = _cmCtx.me;
  const canAddStage = me.role === 'client';
  const canCloseParent = me.role === 'client' || freelancerCanClose(parent);
  if (!canAddStage && !canCloseParent) {
    return `<div class="alert alert-secondary mt-3 mb-0">Этап завершён. Ждите — клиент закроет контракт или сформирует следующий этап.</div>`;
  }

  let buttons = '';
  if (canAddStage) {
    buttons += `<button class="btn btn-sm btn-primary" onclick="cmAddStageFromStage()">＋ Сформировать следующий этап</button>`;
  }
  if (canCloseParent) {
    buttons += `
      <button class="btn btn-sm btn-outline-success" onclick="cmCloseParentFromStage('success')">Закрыть контракт: выполнен</button>
      <button class="btn btn-sm btn-outline-danger" onclick="cmCloseParentFromStage('failure')">Закрыть контракт: не выполнен</button>`;
  }

  return `<div class="alert alert-info mt-3 mb-0">
    <div class="mb-2">Этап завершён — можно закрыть весь контракт или сформировать следующий этап.</div>
    <div class="d-flex gap-2 flex-wrap">${buttons}</div>
  </div>`;
}

// Both jump from a finished stage's card up to its parent first (openContractModal
// just repaints the already-open modal), then run the parent-level action.
async function cmAddStageFromStage() {
  if (_cmOpen.parent_contract_id) await openContractModal(_cmOpen.parent_contract_id);
  cmOpenStage();
}

async function cmCloseParentFromStage(outcome) {
  if (_cmOpen.parent_contract_id) await openContractModal(_cmOpen.parent_contract_id);
  cmClose(outcome);
}

// ─── СДАЧА-ПРИЁМКА РАБОТЫ (api/work_submissions.php) ───────────────────────
// Freelancer hands the work over (comment + files); the client answers ДА
// (accept = close as completed, escrow paid out) or НЕТ (back to rework with
// a comment). One pending hand-over at a time; older ones fold into history.
const WORK_SUBMISSION_LABELS = { submitted: 'ждёт решения клиента', accepted: '✓ работа принята', rejected: '↩ возвращена на доработку' };
const WORK_SUBMISSION_CLASSES = { submitted: 'text-warning', accepted: 'text-success', rejected: 'text-danger' };

function cmSubmissionItemHtml(s) {
  return `<div class="border rounded p-2 mb-2">
    <div class="d-flex justify-content-between gap-2 small mb-1">
      <span class="text-muted">Сдано ${esc(cmFmtDate(s.created_at))}</span>
      <span class="${WORK_SUBMISSION_CLASSES[s.status] || 'text-muted'}">${esc(WORK_SUBMISSION_LABELS[s.status] || s.status)}</span>
    </div>
    ${s.comment ? `<div class="small mb-2" style="white-space:pre-wrap">${esc(s.comment)}</div>` : ''}
    ${attachmentsHtml(s.files)}
    ${s.client_comment ? `<div class="small mt-2 p-2 bg-light rounded"><span class="text-muted">Клиент:</span> ${esc(s.client_comment)}</div>` : ''}
  </div>`;
}

function cmSubmissionSection(c) {
  const me = _cmCtx.me;
  const latest = _cmSubmissions[0] || null;
  const pending = latest && latest.status === 'submitted' ? latest : null;
  const active = c.status === 'active';
  let body = '';

  if (pending) {
    body += cmSubmissionItemHtml(pending);
    if (me.role === 'client' && active) {
      const underpaid = paymentStatus(c) !== 'full'
        ? `<div class="text-danger small mb-2">Фрилансер получит только то, что внесено в эскроу: ${esc(formatUAH(c.escrowed_amount))} из ${esc(formatUAH(c.price))}.</div>`
        : '';
      body += `
        <textarea class="form-control form-control-sm mb-2" id="ws-client-comment" rows="2" maxlength="5000"
                  placeholder="Комментарий (обязателен, если возвращаете на доработку)"></textarea>
        ${underpaid}
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-success" onclick="cmDecideWork(${pending.id}, true)">✅ Да, принять работу</button>
          <button class="btn btn-sm btn-outline-danger" onclick="cmDecideWork(${pending.id}, false)">↩️ Нет, на доработку</button>
        </div>`;
    } else if (me.role === 'freelancer' && active) {
      body += `<p class="text-muted small mb-0">Ждём решения клиента.</p>`;
    }
  } else if (me.role === 'freelancer' && active) {
    if (latest && latest.status === 'rejected') {
      body += `<div class="alert alert-warning small py-2 mb-2">Клиент вернул работу на доработку${latest.client_comment ? ': «' + esc(latest.client_comment) + '»' : ''}. Когда исправите — сдайте снова.</div>`;
    }
    body += `
      <textarea class="form-control form-control-sm mb-2" id="ws-comment" rows="3" maxlength="5000" placeholder="Что сделано, на что обратить внимание..."></textarea>
      <input type="file" class="form-control form-control-sm mb-1" id="ws-files" multiple accept="${UPLOAD_ACCEPT}">
      <div class="form-text mb-2">Фото и документы: png/jpg/pdf/docx/xlsx, до ${UPLOAD_MAX_FILE_MB} МБ, не больше ${UPLOAD_MAX_FILES_COUNT} файлов</div>
      <div id="ws-error" class="alert alert-danger small py-1 d-none"></div>
      <button class="btn btn-sm btn-primary" onclick="cmSubmitWork(this)">📤 Сдать работу</button>`;
  } else if (me.role === 'client' && active && !latest) {
    body += `<p class="text-muted small mb-0">Фрилансер ещё не сдавал работу. Когда сдаст — здесь появятся его комментарий и файлы, и вы решите: принять или вернуть на доработку.</p>`;
  }

  const history = _cmSubmissions.filter(s => s !== pending);
  if (history.length) {
    body += `<details class="mt-2"${active ? '' : ' open'}>
      <summary class="small text-muted">${active ? 'Предыдущие сдачи' : 'Сдачи работы'} (${history.length})</summary>
      <div class="mt-2">${history.map(cmSubmissionItemHtml).join('')}</div>
    </details>`;
  }

  return `<div class="mt-3 pt-3 border-top">
    <div class="fw-semibold small mb-2">📦 Сдача работы</div>
    ${body}
  </div>`;
}

async function cmSubmitWork(btn) {
  hideError('ws-error');
  const comment = document.getElementById('ws-comment').value.trim();
  const files = document.getElementById('ws-files').files;
  if (!comment && !files.length) return showError('ws-error', 'Добавьте комментарий или файлы');
  const fileErr = checkUploadSelection(files);
  if (fileErr) return showError('ws-error', fileErr);

  const fd = new FormData();
  fd.append('action', 'submit');
  fd.append('contract_id', _cmOpen.id);
  fd.append('comment', comment);
  for (const f of files) fd.append('files[]', f);

  btn.disabled = true;
  try {
    const res = await apiUpload('/work_submissions.php', fd);
    if (files.length && res.files_saved < files.length) {
      alert(`Работа сдана, но ${files.length - res.files_saved} файл(ов) не прошли проверку формата и не сохранены.`);
    }
    await cmAfterMutation();
  } catch (e) {
    showError('ws-error', e.message);
    btn.disabled = false;
  }
}

async function cmDecideWork(submissionId, accept) {
  const comment = val('ws-client-comment');
  if (!accept && !comment) return alert('Напишите, что нужно доработать');
  if (accept) {
    const what = _cmOpen.parent_contract_id ? 'Этап' : 'Контракт';
    if (!confirm(`Принять работу? ${what} будет закрыт как выполненный, и ${formatUAH(_cmOpen.escrowed_amount)} из эскроу уйдут фрилансеру. Отменить нельзя.`)) return;
  }
  try {
    await apiPost('/work_submissions.php', { action: 'decide', submission_id: submissionId, accept, comment });
    await cmAfterMutation();
  } catch (e) {
    alert(e.message);
  }
}

// ─── ОТЗЫВ (api/reviews.php) — client rates the freelancer once per closed ──
// top-level contract (full, or a staged one as a whole). Shown read-only to both.
let _cmReviewRating = 0;

function cmReviewSection(c) {
  if (_cmReview === undefined) return '';
  if (_cmReview) {
    return `<div class="mt-3 pt-3 border-top">
      <div class="fw-semibold small mb-1">⭐ Отзыв клиента</div>
      <div>${starsHtml(_cmReview.rating)}</div>
      ${_cmReview.comment ? `<div class="small mt-1" style="white-space:pre-wrap">${esc(_cmReview.comment)}</div>` : ''}
    </div>`;
  }
  if (_cmCtx.me.role !== 'client') return '';

  _cmReviewRating = 0;
  const stars = [1, 2, 3, 4, 5].map(n =>
    `<button type="button" class="btn btn-link p-0 fs-4 text-decoration-none review-star" data-n="${n}" onclick="cmPickStar(${n})">☆</button>`
  ).join('');
  return `<div class="mt-3 pt-3 border-top">
    <div class="fw-semibold small mb-1">⭐ Оцените работу фрилансера</div>
    <div class="mb-2 lh-1">${stars}</div>
    <textarea class="form-control form-control-sm mb-2" id="review-comment" rows="2" maxlength="2000" placeholder="Пара слов о работе (необязательно)"></textarea>
    <div id="review-error" class="alert alert-danger small py-1 d-none"></div>
    <button class="btn btn-sm btn-primary" onclick="cmSubmitReview()">Оставить отзыв</button>
  </div>`;
}

function cmPickStar(n) {
  _cmReviewRating = n;
  document.querySelectorAll('.review-star').forEach(b => {
    const on = Number(b.dataset.n) <= n;
    b.textContent = on ? '★' : '☆';
    b.classList.toggle('text-warning', on);
  });
}

async function cmSubmitReview() {
  hideError('review-error');
  if (!_cmReviewRating) return showError('review-error', 'Выберите оценку от 1 до 5');
  try {
    await apiPost('/reviews.php', { contract_id: _cmOpen.id, rating: _cmReviewRating, comment: val('review-comment') });
    await cmAfterMutation();
  } catch (e) {
    showError('review-error', e.message);
  }
}

// ─── СМЕТА (bill of materials) — part of THIS contract/stage, not a separate ─
// conversation-wide artifact. _cmEstimates holds only this row's own history.
function cmEstimateSection(c) {
  const me = _cmCtx.me;
  const latest = _cmEstimates[0] || null;
  const isFreelancer = me.role === 'freelancer';
  const canCompose = isFreelancer && c.status === 'active' && (!latest || latest.status === 'rejected');

  let inner;
  if (!latest) {
    inner = `<p class="text-muted small mb-0">${isFreelancer ? 'Смета ещё не составлена.' : 'Фрилансер ещё не прислал смету.'}</p>`;
  } else {
    const labels = { submitted: isFreelancer ? 'на рассмотрении у заказчика' : 'требует решения', accepted: '✓ утверждена', rejected: 'отклонена' };
    const classes = { submitted: 'text-warning', accepted: 'text-success', rejected: 'text-muted' };
    const meta = `${latest.items.length} поз. · ${esc(formatUAH(latest.total))}`;
    let actions = '';
    if (latest.status === 'submitted' && !isFreelancer) {
      actions = `<div class="d-flex gap-2 mt-2">
        <button class="btn btn-sm btn-success" onclick="cmRespondEstimate(${latest.id}, true)">Утвердить</button>
        <button class="btn btn-sm btn-outline-danger" onclick="cmRespondEstimate(${latest.id}, false)">Отклонить</button>
      </div>`;
    }
    inner = `<div class="small mb-1">${meta} · <span class="${classes[latest.status] || 'text-muted'}">${esc(labels[latest.status] || latest.status)}</span></div>
      <button class="btn btn-sm btn-link p-0" onclick="cmOpenEstimate()">Открыть смету</button>
      ${actions}`;
  }

  const composeBtn = canCompose
    ? `<div class="mt-2"><button class="btn btn-sm btn-outline-primary" onclick="cmOpenEstimate()">${latest ? 'Составить заново' : 'Составить смету'}</button></div>`
    : '';

  return `<div class="mt-3 pt-3 border-top">
    <div class="fw-semibold small mb-2">📋 Смета на материалы</div>
    ${inner}
    ${composeBtn}
  </div>`;
}

function cmOpenEstimate() {
  hideError('estimate-error');
  const latest = _cmEstimates[0] || null;
  const isFreelancer = _cmCtx.me.role === 'freelancer';
  const editable = isFreelancer && _cmOpen.status === 'active' && (!latest || latest.status === 'rejected');

  const editor = document.getElementById('estimate-editor');
  const readonly = document.getElementById('estimate-readonly');
  const footer = document.getElementById('estimate-footer');

  if (editable) {
    editor.style.display = '';
    readonly.innerHTML = '';
    document.getElementById('estimate-rows').innerHTML = '';
    (latest && latest.items.length ? latest.items : [null, null]).forEach(addEstimateRow);
    document.getElementById('estimate-note').value = latest ? (latest.note || '') : '';
    recalcEstimate();
    footer.innerHTML = `
      <button class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
      <button class="btn btn-primary" onclick="cmSubmitEstimate()">Отправить смету</button>`;
  } else {
    editor.style.display = 'none';
    readonly.innerHTML = latest ? estimateReadonlyHtml(latest) : '<p class="text-muted">Смета не составлена.</p>';
    const canDecide = !isFreelancer && latest && latest.status === 'submitted';
    footer.innerHTML = canDecide
      ? `<button class="btn btn-outline-danger me-auto" onclick="cmRespondEstimate(${latest.id}, false)">Отклонить</button>
         <button class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
         <button class="btn btn-success" onclick="cmRespondEstimate(${latest.id}, true)">Утвердить</button>`
      : `<button class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>`;
  }

  cmOpenChild('estimateModal');
}

function estimateRowHtml(it) {
  it = it || { name: '', qty: '', unit: '', unit_price: '' };
  return `
    <tr>
      <td><input class="form-control form-control-sm est-name" value="${esc(it.name)}"></td>
      <td><input type="number" min="0" step="any" class="form-control form-control-sm est-qty" value="${esc(it.qty)}" oninput="recalcEstimate()"></td>
      <td><input class="form-control form-control-sm est-unit" value="${esc(it.unit)}" placeholder="шт"></td>
      <td><input type="number" min="0" step="any" class="form-control form-control-sm est-price" value="${esc(it.unit_price)}" oninput="recalcEstimate()"></td>
      <td><button class="btn-close" title="Убрать" onclick="this.closest('tr').remove(); recalcEstimate()"></button></td>
    </tr>`;
}

function addEstimateRow(it) {
  document.getElementById('estimate-rows').insertAdjacentHTML('beforeend', estimateRowHtml(it));
}

function recalcEstimate() {
  let total = 0;
  document.querySelectorAll('#estimate-rows tr').forEach(tr => {
    const qty = Number(tr.querySelector('.est-qty').value) || 0;
    const price = Number(tr.querySelector('.est-price').value) || 0;
    total += qty * price;
  });
  document.getElementById('estimate-editor-total').textContent = 'Итого: ' + formatUAH(total);
}

function estimateReadonlyHtml(e) {
  const rows = e.items.map(it => `
    <tr>
      <td>${esc(it.name)}</td>
      <td class="text-end">${esc(it.qty)}</td>
      <td>${esc(it.unit || '')}</td>
      <td class="text-end">${esc(formatUAH(it.unit_price))}</td>
      <td class="text-end">${esc(formatUAH(it.qty * it.unit_price))}</td>
    </tr>`).join('');
  return `
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>Материал / работа</th><th class="text-end">Кол-во</th><th>Ед.</th><th class="text-end">Цена/ед</th><th class="text-end">Сумма</th></tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr><th colspan="4" class="text-end">Итого</th><th class="text-end">${esc(formatUAH(e.total))}</th></tr></tfoot>
      </table>
    </div>
    ${e.note ? `<p class="text-muted small mb-0">${esc(e.note)}</p>` : ''}`;
}

async function cmSubmitEstimate() {
  hideError('estimate-error');
  const items = [];
  document.querySelectorAll('#estimate-rows tr').forEach(tr => {
    const name = tr.querySelector('.est-name').value.trim();
    const qty = Number(tr.querySelector('.est-qty').value) || 0;
    const unit = tr.querySelector('.est-unit').value.trim();
    const unit_price = Number(tr.querySelector('.est-price').value) || 0;
    if (name && qty > 0) items.push({ name, qty, unit, unit_price });
  });
  if (!items.length) return showError('estimate-error', 'Добавьте хотя бы одну позицию');

  try {
    await apiPost('/contract_estimates.php', {
      action: 'submit',
      contract_id: _cmOpen.id,
      items,
      note: val('estimate-note'),
    });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('estimateModal')).hide();
  } catch (e) {
    showError('estimate-error', e.message);
  }
}

async function cmRespondEstimate(estimateId, accept) {
  try {
    await apiPost('/contract_estimates.php', { action: 'respond', estimate_id: estimateId, accept });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('estimateModal')).hide();
  } catch (e) {
    alert(e.message);
  }
}

// ─── «ВЫЗОВ АДМИНИСТРАТОРА» — a lever for real conflicts ────────────────────
// Either side of an active contract/stage can flag it; an admin then sees the
// contract, the whole chat history, and the estimate (admin.php), and forces
// a resolution regardless of what either party would agree to on their own.
function cmEscalationSection(c) {
  if (_cmEscalation && _cmEscalation.status === 'open') {
    return `<div class="alert alert-info mt-3 mb-0">🛎 Обращение к администрации отправлено, ожидайте решения.</div>`;
  }
  if (_cmEscalation && _cmEscalation.status === 'resolved') {
    const label = ESCALATION_RESOLUTION_LABELS[_cmEscalation.resolution] || _cmEscalation.resolution;
    return `<div class="alert alert-secondary mt-3 mb-0">Обращение к администрации рассмотрено: ${esc(label)}.${_cmEscalation.resolution_note ? ' «' + esc(_cmEscalation.resolution_note) + '»' : ''}</div>`;
  }
  return `<div class="mt-3"><button class="btn btn-sm btn-outline-danger" onclick="cmOpenEscalate()">🛎 Позвать администратора</button></div>`;
}

function cmOpenEscalate() {
  hideError('escalate-error');
  document.getElementById('escalate-reason').value = '';
  cmOpenChild('escalateModal');
}

async function cmSubmitEscalate() {
  hideError('escalate-error');
  const reason = val('escalate-reason');
  if (!reason) return showError('escalate-error', 'Опишите суть конфликта');

  try {
    await apiPost('/escalations.php', { action: 'raise', contract_id: _cmOpen.id, reason });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('escalateModal')).hide();
  } catch (e) {
    showError('escalate-error', e.message);
  }
}

function cmRenderMenu(c) {
  const me = _cmCtx.me;
  const items = [];
  const stagedParent = cmIsStagedParent(c);

  if (!stagedParent && c.status === 'proposed' && me.role === 'freelancer') {
    const noun = c.parent_contract_id ? 'этап' : 'контракт';
    items.push(`<li><button class="dropdown-item" onclick="cmAccept()">Подтвердить ${noun}</button></li>`);
    items.push(`<li><button class="dropdown-item text-danger" onclick="cmReject()">Отклонить ${noun}</button></li>`);
  }

  if (c.status === 'active') {
    if (!stagedParent && !_cmChange) {
      items.push(`<li><button class="dropdown-item" onclick="cmOpenChange()">Изменить условия (цена / срок)</button></li>`);
    }
    const canClose = me.role === 'client' || freelancerCanClose(c);
    const attrs = canClose ? '' : 'disabled title="Доступно, если клиент не отвечает на ваше последнее сообщение 14 дней"';
    if (items.length) items.push(`<li><hr class="dropdown-divider"></li>`);
    items.push(`<li><button class="dropdown-item" ${attrs} onclick="cmClose('success')">Закрыть: выполнен</button></li>`);
    items.push(`<li><button class="dropdown-item text-danger" ${attrs} onclick="cmClose('failure')">Закрыть: не выполнен</button></li>`);
  }

  if (!stagedParent && c.status === 'failed' && me.role === 'freelancer' && _cmHold && !_cmHold.disputed_at) {
    items.push(`<li><button class="dropdown-item text-danger" onclick="cmRaiseDispute()">Оспорить закрытие</button></li>`);
  }

  document.getElementById('contract-actions-menu').innerHTML = items.join('');
  document.getElementById('contract-actions-wrap').style.display = items.length ? '' : 'none';
}

function freelancerCanClose(c) {
  if (_cmCtx.me.role !== 'freelancer') return false;
  if (!c.last_message_at || Number(c.last_message_sender_id) !== _cmCtx.me.id) return false;
  const ageMs = Date.now() - new Date(String(c.last_message_at).replace(' ', 'T')).getTime();
  return ageMs >= 14 * 24 * 60 * 60 * 1000;
}

// ─── direct actions ─────────────────────────────────────────────────────────
function cmAccept() {
  cmPost('/contracts.php', { action: 'accept', contract_id: _cmOpen.id });
}

function cmReject() {
  const noun = _cmOpen.parent_contract_id ? 'этап' : 'контракт';
  if (!confirm(`Отклонить ${noun}?`)) return;
  cmPost('/contracts.php', { action: 'reject', contract_id: _cmOpen.id });
}

function cmClose(outcome) {
  const staged = cmIsStagedParent(_cmOpen);
  const what = staged ? 'поэтапный контракт' : (_cmOpen.parent_contract_id ? 'этап' : 'контракт');
  const label = outcome === 'success' ? 'выполнен' : 'не выполнен';
  const extra = staged ? ' Все незавершённые этапы будут закрыты.' : '';
  if (!confirm(`Закрыть ${what} как «${label}»?${extra} Это действие нельзя отменить.`)) return;
  cmPost('/contracts.php', { action: 'close', contract_id: _cmOpen.id, outcome });
}

function cmRespondChange(accept) {
  cmPost('/contract_change_requests.php', { action: 'respond', request_id: _cmChange.id, accept });
}

function cmRaiseDispute() {
  if (!confirm('Оспорить закрытие? Решение примет администратор.')) return;
  cmPost('/disputes.php', { action: 'raise', contract_id: _cmOpen.id });
}

async function cmPost(path, payload) {
  try {
    await apiPost(path, payload);
    await cmAfterMutation();
  } catch (e) {
    alert(e.message);
  }
}

// ─── child dialogs: change terms / pay / add stage ─────────────────────────
function cmOpenChild(childId) {
  const contractModalEl = document.getElementById('contractModal');
  contractModalEl.addEventListener('hidden.bs.modal', () => {
    bootstrap.Modal.getOrCreateInstance(document.getElementById(childId)).show();
  }, { once: true });
  bootstrap.Modal.getOrCreateInstance(contractModalEl).hide();
}

function cmOpenChange() {
  hideError('change-error');
  document.getElementById('change-price').value = '';
  document.getElementById('change-deadline').value = '';
  cmOpenChild('changeModal');
}

async function cmSubmitChange() {
  hideError('change-error');
  const newPrice = val('change-price');
  const newDeadline = val('change-deadline');
  if (!newPrice && !newDeadline) return showError('change-error', 'Заполните цену и/или срок');

  try {
    await apiPost('/contract_change_requests.php', {
      action: 'propose',
      contract_id: _cmOpen.id,
      new_price: newPrice ? Number(newPrice) : null,
      new_deadline: newDeadline || null,
    });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('changeModal')).hide();
  } catch (e) {
    showError('change-error', e.message);
  }
}

// Balance first (escrow.php): the client's AVAILABLE site balance (minus frozen
// holds) is spent before the card. Re-read from me.php on every open — it
// changes whenever another contract is paid or refunded.
let _cmAvailableBalance = 0;

async function cmOpenDeposit() {
  hideError('deposit-error');
  try {
    const me = await apiFetch('/me.php');
    _cmAvailableBalance = Number(me.available_balance) || 0;
  } catch {
    _cmAvailableBalance = 0;   // server recomputes anyway; worst case the split preview is off
  }
  const remaining = Math.max(0, Number(_cmOpen.price) - Number(_cmOpen.escrowed_amount || 0));
  document.getElementById('deposit-amount').value = remaining;
  document.getElementById('deposit-remaining-hint').textContent = `Остаток: ${formatUAH(remaining)}`;
  document.getElementById('deposit-card-number').value = '';
  document.getElementById('deposit-card-expiry').value = '';
  document.getElementById('deposit-card-cvv').value = '';
  document.getElementById('deposit-withdraw-notice').textContent = 'ℹ️ ' + CLIENT_WITHDRAW_NOTICE;
  cmRenderDepositSplit();
  cmOpenChild('depositModal');
}

// Splits the entered amount into "с баланса" + "картой"; when the balance
// covers everything, the payment-method tabs are replaced by one button.
function cmDepositSplit() {
  const amount = Math.max(0, Number(val('deposit-amount')) || 0);
  const fromBalance = Math.min(amount, _cmAvailableBalance);
  const fromCard = Math.round((amount - fromBalance) * 100) / 100;
  return { amount, fromBalance, fromCard };
}

function cmRenderDepositSplit() {
  const { amount, fromBalance, fromCard } = cmDepositSplit();
  const box = document.getElementById('deposit-split');
  const methods = document.getElementById('deposit-methods');
  const balanceBtn = document.getElementById('deposit-balance-only-btn');

  if (_cmAvailableBalance <= 0) {
    box.style.display = 'none';
    methods.style.display = '';
    balanceBtn.style.display = 'none';
    return;
  }

  box.style.display = '';
  box.innerHTML = `
    <div class="d-flex justify-content-between"><span class="text-muted">Доступно на балансе сайта</span><span>${esc(formatUAH(_cmAvailableBalance))}</span></div>
    <div class="d-flex justify-content-between"><span>Спишется с баланса</span><span class="fw-semibold text-success">${esc(formatUAH(fromBalance))}</span></div>
    <div class="d-flex justify-content-between"><span>Доплатить картой</span><span class="fw-semibold">${esc(formatUAH(fromCard))}</span></div>`;

  const balanceCovers = amount > 0 && fromCard <= 0;
  methods.style.display = balanceCovers ? 'none' : '';
  balanceBtn.style.display = balanceCovers ? '' : 'none';
}

async function cmSubmitDeposit(method) {
  hideError('deposit-error');
  const amount = Number(val('deposit-amount'));
  if (!amount || amount <= 0) return showError('deposit-error', 'Укажите сумму');
  if (cmDepositSplit().fromCard <= 0) method = '';   // balance covers it all — no card involved

  if (method === 'card') {
    const number = document.getElementById('deposit-card-number').value.replace(/\s/g, '');
    const expiry = val('deposit-card-expiry');
    const cvv    = val('deposit-card-cvv');
    if (!/^\d{16}$/.test(number))       return showError('deposit-error', 'Введите номер карты (16 цифр)');
    if (!/^\d{2}\/\d{2}$/.test(expiry)) return showError('deposit-error', 'Формат срока действия — ММ/ГГ');
    if (!/^\d{3}$/.test(cvv))           return showError('deposit-error', 'CVV — 3 цифры');
  }

  try {
    await apiPost('/escrow.php', { action: 'deposit', contract_id: _cmOpen.id, amount, method });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('depositModal')).hide();
  } catch (e) {
    showError('deposit-error', e.message);
  }
}

function cmOpenStage() {
  hideError('stage-error');
  document.getElementById('stage-title').value = '';
  document.getElementById('stage-price').value = '';
  document.getElementById('stage-deadline').value = '';
  cmOpenChild('stageModal');
}

async function cmSubmitStage() {
  hideError('stage-error');
  const title = val('stage-title');
  const price = Number(val('stage-price'));
  const deadline = val('stage-deadline');
  if (!title) return showError('stage-error', 'Укажите название этапа');
  if (!price || price <= 0) return showError('stage-error', 'Укажите цену этапа');

  try {
    await apiPost('/contracts.php', {
      action: 'add_stage',
      contract_id: _cmOpen.id,
      title,
      price,
      deadline: deadline || null,
    });
    await cmAfterMutation();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('stageModal')).hide();
  } catch (e) {
    showError('stage-error', e.message);
  }
}

function cmFmtDate(s) {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? s : d.toLocaleDateString('uk-UA');
}

const ContractModal = { init: contractModalInit, open: openContractModal, refresh: cmAfterMutation };
