// Cabinet tab «👷 Бригада» (brigade.html, api/brigades.php). Freelancers only.
// No brigade → create one (you become бригадир) or accept an invitation.
// Бригадир → edit brigade, invite by e-mail, set roles, remove members,
// see brigade orders and split the pay (BrigadeAllocation), dissolve.
// Member → see brigade, roles, orders with own share; leave.
let currentUser = null;
let mine = null;   // GET mine response

const BRIGADE_ORDER_STATUS = {
  proposed: 'Очікує підтвердження', active: 'Активний', completed: 'Виконано',
  failed: 'Не виконано', planned: 'За планом',
};

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'freelancer') {
    location.href = 'cabinet.html';
    return;
  }
  setupCabinetTabs(currentUser);
  loadBrigade();
});

async function loadBrigade() {
  hideError('brigade-error');
  try {
    mine = await apiFetch('/brigades.php?action=mine');
  } catch (e) {
    showError('brigade-error', e.message);
    return;
  }
  renderInvitations();
  if (!mine.brigade) renderCreateForm();
  else if (mine.my_role === 'owner') renderOwnerView();
  else renderMemberView();
}

// ─── invitations (for someone not yet in a brigade) ─────────────────────────
function renderInvitations() {
  const el = document.getElementById('brigade-invitations');
  if (!mine.invitations.length || mine.my_role) { el.innerHTML = ''; return; }
  el.innerHTML = mine.invitations.map(i => `
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div>📨 <b>${esc(i.owner_name)}</b> запрошує вас до бригади <b>«${esc(i.name)}»</b>${i.role ? ` на роль «${esc(i.role)}»` : ''}.</div>
        ${i.description ? `<div class="small text-muted">${esc(truncateText(i.description, 200))}</div>` : ''}
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-success" onclick="respondInvite(${i.brigade_id}, true)">Приєднатися</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="respondInvite(${i.brigade_id}, false)">Відхилити</button>
      </div>
    </div>`).join('');
}

async function respondInvite(brigadeId, accept) {
  try {
    await apiPost('/brigades.php', { action: 'respond_invite', brigade_id: brigadeId, accept });
    loadBrigade();
  } catch (e) {
    showError('brigade-error', e.message);
  }
}

// ─── no brigade yet ─────────────────────────────────────────────────────────
function renderCreateForm() {
  document.getElementById('brigade-content').innerHTML = `
    <div class="card p-3">
      <h5 class="fw-semibold">Створити бригаду</h5>
      <p class="small text-muted">Ви станете бригадиром: запрошуєте людей, призначаєте ролі, клієнти зможуть пропонувати контракти вашій бригаді, а оплату ви розподіляєте між учасниками.</p>
      <div class="mb-2">
        <label class="form-label">Назва бригади</label>
        <input type="text" class="form-control" id="new-brigade-name" maxlength="150" placeholder="Бригада Петренка — оздоблювальні роботи">
      </div>
      <div class="mb-3">
        <label class="form-label">Опис <span class="text-muted small">(необов'язково)</span></label>
        <textarea class="form-control" id="new-brigade-desc" rows="3" maxlength="2000" placeholder="Чим займаєтеся, досвід, район роботи..."></textarea>
      </div>
      <button class="btn btn-primary" onclick="createBrigade()">Створити бригаду</button>
    </div>`;
}

async function createBrigade() {
  hideError('brigade-error');
  const name = val('new-brigade-name');
  if (!name) return showError('brigade-error', 'Вкажіть назву бригади');
  try {
    await apiPost('/brigades.php', { action: 'create', name, description: val('new-brigade-desc') });
    loadBrigade();
  } catch (e) {
    showError('brigade-error', e.message);
  }
}

// ─── бригадир ───────────────────────────────────────────────────────────────
function renderOwnerView() {
  const b = mine.brigade;
  const active = b.members.filter(m => m.status === 'active');
  const invited = b.members.filter(m => m.status === 'invited');

  const memberRows = active.map(m => `
    <tr>
      <td class="small">${esc(m.name)}<div class="text-muted" style="font-size:.75rem">${esc(m.email || '')}</div></td>
      <td>
        <div class="input-group input-group-sm">
          <input type="text" class="form-control" id="role-${m.id}" maxlength="100" value="${esc(m.role || '')}" placeholder="Роль (плиточник, електрик...)">
          <button class="btn btn-outline-secondary" onclick="saveRole(${m.id}, this)">Зберегти</button>
        </div>
      </td>
      <td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="removeMember(${m.id})">Виключити</button></td>
    </tr>`).join('');

  const invitedRows = invited.map(m => `
    <tr class="table-light">
      <td class="small">${esc(m.name)}<div class="text-muted" style="font-size:.75rem">${esc(m.email || '')}</div></td>
      <td class="small text-muted">⏳ запрошено${m.role ? ` · ${esc(m.role)}` : ''}</td>
      <td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick="cancelInvite(${m.id})">Скасувати</button></td>
    </tr>`).join('');

  document.getElementById('brigade-content').innerHTML = `
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div>
          <span class="badge bg-warning text-dark mb-1">👷 Ви бригадир</span>
          <h4 class="fw-bold mb-0">${esc(b.name)}</h4>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleEditBrigade()">✏️ Редагувати</button>
      </div>
      ${b.description ? `<p class="text-muted mb-0" style="white-space:pre-wrap">${esc(b.description)}</p>` : ''}
      <div id="edit-brigade" class="mt-3" style="display:none">
        <input type="text" class="form-control mb-2" id="edit-brigade-name" maxlength="150" value="${esc(b.name)}">
        <textarea class="form-control mb-2" id="edit-brigade-desc" rows="3" maxlength="2000">${esc(b.description || '')}</textarea>
        <button class="btn btn-sm btn-primary" onclick="saveBrigade()">Зберегти</button>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h5 class="fw-semibold">Склад бригади <span class="text-muted small">(${active.length + 1} ос.)</span></h5>
      <table class="table table-sm align-middle mb-2">
        <thead><tr><th>Учасник</th><th>Роль</th><th></th></tr></thead>
        <tbody>
          <tr><td class="small">${esc(b.owner.name)} <span class="text-muted">(ви)</span></td><td class="small">👷 Бригадир</td><td></td></tr>
          ${memberRows}${invitedRows}
        </tbody>
      </table>
      <div class="border rounded p-2 bg-light">
        <div class="small fw-semibold mb-1">Запросити фрилансера</div>
        <div class="row g-2">
          <div class="col-md-5"><input type="text" class="form-control form-control-sm" id="invite-email" placeholder="Email фрилансера"></div>
          <div class="col-md-4"><input type="text" class="form-control form-control-sm" id="invite-role" maxlength="100" placeholder="Роль (необов'язково)"></div>
          <div class="col-md-3"><button class="btn btn-sm btn-primary w-100" onclick="inviteMember()">Запросити</button></div>
        </div>
        <div class="form-text">Людина отримає сповіщення й прийме запрошення у своєму кабінеті.</div>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h5 class="fw-semibold">Замовлення бригади</h5>
      <div id="brigade-orders"><p class="text-muted small mb-0">Завантаження...</p></div>
    </div>

    <div class="text-end">
      <button class="btn btn-sm btn-outline-danger" onclick="dissolveBrigade()">Розпустити бригаду</button>
    </div>`;
  loadOrders(true);
}

function toggleEditBrigade() {
  const el = document.getElementById('edit-brigade');
  el.style.display = el.style.display === 'none' ? '' : 'none';
}

async function saveBrigade() {
  try {
    await apiPost('/brigades.php', { action: 'update', name: val('edit-brigade-name'), description: val('edit-brigade-desc') });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

async function inviteMember() {
  hideError('brigade-error');
  const email = val('invite-email');
  if (!email) return showError('brigade-error', 'Вкажіть email фрилансера');
  try {
    await apiPost('/brigades.php', { action: 'invite', email, role: val('invite-role') });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

async function saveRole(userId, btn) {
  try {
    await apiPost('/brigades.php', { action: 'set_role', user_id: userId, role: val(`role-${userId}`) });
    btn.textContent = '✓';
    setTimeout(() => { btn.textContent = 'Зберегти'; }, 1200);
  } catch (e) { showError('brigade-error', e.message); }
}

async function removeMember(userId) {
  if (!confirm('Виключити учасника з бригади? Уже призначені йому частки в незакритих контрактах залишаться, доки ви їх не зміните.')) return;
  try {
    await apiPost('/brigades.php', { action: 'remove_member', user_id: userId });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

async function cancelInvite(userId) {
  try {
    await apiPost('/brigades.php', { action: 'cancel_invite', user_id: userId });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

async function dissolveBrigade() {
  if (!confirm('Розпустити бригаду? Усі учасники вийдуть із неї. Це можна зробити лише без незавершених замовлень.')) return;
  try {
    await apiPost('/brigades.php', { action: 'dissolve' });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

// ─── member ─────────────────────────────────────────────────────────────────
function renderMemberView() {
  const b = mine.brigade;
  const me = b.members.find(m => m.id === currentUser.id);
  document.getElementById('brigade-content').innerHTML = `
    <div class="card p-3 mb-3">
      <span class="badge bg-info text-dark mb-1 align-self-start">Ви учасник${me && me.role ? ` · ${esc(me.role)}` : ''}</span>
      <h4 class="fw-bold mb-1">${esc(b.name)}</h4>
      <div class="small text-muted mb-2">Бригадир: ${esc(b.owner.name)}</div>
      ${b.description ? `<p class="text-muted mb-0" style="white-space:pre-wrap">${esc(b.description)}</p>` : ''}
    </div>
    <div class="card p-3 mb-3">
      <h5 class="fw-semibold">Склад бригади <span class="text-muted small">(${b.members.length + 1} ос.)</span></h5>
      <ul class="list-unstyled mb-0 small">
        <li class="mb-1">👷 ${esc(b.owner.name)} — бригадир</li>
        ${b.members.map(m => `<li class="mb-1">${esc(m.name)}${m.id === currentUser.id ? ' <span class="text-muted">(ви)</span>' : ''} — ${esc(m.role || 'роль не призначено')}</li>`).join('')}
      </ul>
    </div>
    <div class="card p-3 mb-3">
      <h5 class="fw-semibold">Замовлення бригади</h5>
      <div id="brigade-orders"><p class="text-muted small mb-0">Завантаження...</p></div>
    </div>
    <div class="text-end">
      <button class="btn btn-sm btn-outline-danger" onclick="leaveBrigade()">Вийти з бригади</button>
    </div>`;
  loadOrders(false);
}

async function leaveBrigade() {
  if (!confirm('Вийти з бригади?')) return;
  try {
    await apiPost('/brigades.php', { action: 'leave' });
    loadBrigade();
  } catch (e) { showError('brigade-error', e.message); }
}

// ─── orders (both views) ────────────────────────────────────────────────────
async function loadOrders(isOwner) {
  const el = document.getElementById('brigade-orders');
  let rows;
  try {
    rows = await apiFetch('/brigades.php?action=contracts');
  } catch (e) {
    el.innerHTML = `<div class="alert alert-warning small mb-0">${esc(e.message)}</div>`;
    return;
  }
  if (!rows.length) {
    el.innerHTML = '<p class="text-muted small mb-0">Замовлень поки немає. Клієнт може запропонувати контракт бригаді в чаті з бригадиром.</p>';
    return;
  }
  el.innerHTML = rows.map(r => {
    const staged = r.type === 'staged';
    const label = r.parent_contract_id
      ? `↳ Етап ${r.seq}${r.title ? ` «${esc(r.title)}»` : ''}`
      : `${staged ? 'Поетапний контракт' : 'Контракт'} #${esc(r.number)}${r.title ? ` «${esc(r.title)}»` : ''}`;
    let money = '';
    if (!staged) {
      money = isOwner
        ? `<div class="small">${esc(formatUAH(r.price))} · учасникам ${esc(formatUAH(r.allocated_total))} · вам ${esc(formatUAH(r.owner_share))}</div>`
        : `<div class="small">${esc(formatUAH(r.price))} · <b>ваша частка: ${esc(formatUAH(r.my_share))}</b></div>`;
    }
    const allocBtn = isOwner && !staged
      ? `<button class="btn btn-sm btn-outline-primary" onclick="openAllocation(${r.id}, '${esc(r.number)}')">💰 Розподіл</button>` : '';
    return `
      <div class="d-flex justify-content-between align-items-center gap-2 border rounded p-2 mb-1 ${r.parent_contract_id ? 'ms-3' : ''}">
        <div style="min-width:0">
          <div class="small fw-semibold text-truncate">${label}</div>
          <div class="text-muted" style="font-size:.78rem">${r.parent_contract_id ? '' : `Клієнт: ${esc(r.client_name)} · `}${esc(BRIGADE_ORDER_STATUS[r.status] || r.status)}${r.deadline ? ' · до ' + esc(r.deadline) : ''}</div>
          ${money}
        </div>
        ${allocBtn}
      </div>`;
  }).join('');
}

function openAllocation(contractId, number) {
  document.getElementById('allocation-title').textContent = `Розподіл оплати · #${number}`;
  document.getElementById('allocation-footer').innerHTML = '<button class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>';
  BrigadeAllocation.render(document.getElementById('allocation-body'), contractId, { onSaved: () => loadOrders(true) });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('allocationModal')).show();
}
