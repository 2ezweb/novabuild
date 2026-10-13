let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'admin') {
    location.href = 'dashboard.html';
    return;
  }

  document.querySelectorAll('.sidebar-avatar').forEach(el => renderAvatarEl(el, displayName(currentUser), currentUser.avatar_path));
  document.querySelectorAll('.sidebar-name').forEach(el => el.innerHTML = `${esc(displayName(currentUser))} ${roleBadgeHtml(currentUser)}`);

  loadUsers();
  loadRequests();
  loadWithdrawals();
  loadDisputes();
  loadEscalations();
});

function showSection(section) {
  document.getElementById('admin-section-users').style.display = section === 'users' ? '' : 'none';
  document.getElementById('admin-section-requests').style.display = section === 'requests' ? '' : 'none';
  document.getElementById('admin-section-withdrawals').style.display = section === 'withdrawals' ? '' : 'none';
  document.getElementById('admin-section-disputes').style.display = section === 'disputes' ? '' : 'none';
  document.getElementById('admin-section-escalations').style.display = section === 'escalations' ? '' : 'none';
  document.getElementById('nav-users-btn').classList.toggle('active', section === 'users');
  document.getElementById('nav-requests-btn').classList.toggle('active', section === 'requests');
  document.getElementById('nav-withdrawals-btn').classList.toggle('active', section === 'withdrawals');
  document.getElementById('nav-disputes-btn').classList.toggle('active', section === 'disputes');
  document.getElementById('nav-escalations-btn').classList.toggle('active', section === 'escalations');
}

// ─── USERS ────────────────────────────────────────────────────────────────────
async function loadUsers() {
  const list = document.getElementById('admin-users-list');
  try {
    const users = await apiFetch('/admin.php?action=users');
    if (!users.length) {
      list.innerHTML = emptyState('Користувачів поки немає.');
      return;
    }
    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Ім'я</th><th>Email / логін</th><th>Роль</th><th>Верифікація</th><th>Статус</th><th>Реєстрація</th><th></th>
            </tr>
          </thead>
          <tbody>
            ${users.map(userRow).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function userRow(u) {
  const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || '—';
  const roleLabel = { client: 'Клієнт', freelancer: 'Фрилансер', admin: 'Адмін' }[u.role] ?? u.role;
  const verMap = {
    verified: '<span class="badge bg-success">✓ Верифіковано</span>',
    pending:  '<span class="badge bg-warning text-dark">⏳ На перевірці</span>',
    rejected: '<span class="badge bg-danger">✗ Відхилено</span>',
    none:     '<span class="text-muted small">—</span>',
  };
  return `
    <tr>
      <td>${esc(name)}</td>
      <td>${esc(u.email)}</td>
      <td>${esc(roleLabel)}</td>
      <td>${verMap[u.verification_status] ?? ''}</td>
      <td>${u.status === 'banned' ? '<span class="badge bg-danger">заблоковано</span>' : '<span class="badge bg-light text-dark border">активний</span>'}</td>
      <td class="text-muted small">${esc(u.created_at)}</td>
      <td class="text-end">${userBanButton(u)}</td>
    </tr>`;
}

// Admins can't be banned (admin.php set_user_status refuses too).
function userBanButton(u) {
  if (u.role === 'admin') return '';
  return u.status === 'banned'
    ? `<button class="btn btn-sm btn-outline-success" onclick="setUserStatus(${u.id}, 'active')">Розблокувати</button>`
    : `<button class="btn btn-sm btn-outline-danger" onclick="setUserStatus(${u.id}, 'banned')">🚫 Заблокувати</button>`;
}

async function setUserStatus(userId, status) {
  const q = status === 'banned'
    ? 'Заблокувати користувача? Він одразу втратить доступ до сайту, навіть якщо зараз залогінений.'
    : 'Розблокувати користувача?';
  if (!confirm(q)) return;
  try {
    await apiPost('/admin.php', { action: 'set_user_status', user_id: userId, status });
    loadUsers();
  } catch (e) {
    alert(e.message);
  }
}

// ─── VERIFICATION REQUESTS ────────────────────────────────────────────────────
async function loadRequests() {
  const list = document.getElementById('admin-requests-list');
  try {
    const requests = await apiFetch('/admin.php?action=verification_requests');
    const badge = document.getElementById('requests-count');
    if (requests.length) {
      badge.textContent = requests.length;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    if (!requests.length) {
      list.innerHTML = emptyState('Заявок на розгляді немає.');
      return;
    }
    list.innerHTML = requests.map(requestCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function requestCard(u) {
  const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.email;
  const roleLabel = { client: 'Клієнт', freelancer: 'Фрилансер' }[u.role] ?? u.role;
  return `
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="fw-semibold mb-1">${esc(name)} <span class="text-muted small">(${esc(roleLabel)})</span></h6>
          <p class="text-muted small mb-0">${esc(u.email)}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" onclick="viewDocument(${u.id})">Відкрити документ</button>
          <button class="btn btn-sm btn-success" onclick="decideRequest(${u.id}, 'approve')">Схвалити</button>
          <button class="btn btn-sm btn-outline-danger" onclick="decideRequest(${u.id}, 'reject')">Відхилити</button>
        </div>
      </div>
    </div>`;
}

async function decideRequest(userId, action) {
  try {
    await apiPost('/admin.php', { action, user_id: userId });
    loadRequests();
    loadUsers();
  } catch (e) {
    alert(e.message);
  }
}

// ─── WITHDRAWAL REQUESTS ──────────────────────────────────────────────────────
async function loadWithdrawals() {
  const list = document.getElementById('admin-withdrawals-list');
  try {
    const requests = await apiFetch('/admin.php?action=withdrawal_requests');
    const badge = document.getElementById('withdrawals-count');
    if (requests.length) {
      badge.textContent = requests.length;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    if (!requests.length) {
      list.innerHTML = emptyState('Заявок на виведення немає.');
      return;
    }
    list.innerHTML = requests.map(withdrawalCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function withdrawalCard(r) {
  const name = [r.first_name, r.last_name].filter(Boolean).join(' ') || r.email;
  return `
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="fw-semibold mb-1">${esc(name)} <span class="text-muted small">— ${esc(formatUAH(r.amount))}</span></h6>
          <p class="text-muted small mb-0">${esc(r.email)} · подано ${timeAgo(r.created_at)}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-success" onclick="decideWithdrawal(${r.id}, 'approve_withdrawal')">Схвалити</button>
          <button class="btn btn-sm btn-outline-danger" onclick="decideWithdrawal(${r.id}, 'reject_withdrawal')">Відхилити</button>
        </div>
      </div>
    </div>`;
}

async function decideWithdrawal(requestId, action) {
  try {
    await apiPost('/admin.php', { action, request_id: requestId });
    loadWithdrawals();
  } catch (e) {
    alert(e.message);
  }
}

// ─── DISPUTED CONTRACTS ───────────────────────────────────────────────────────
async function loadDisputes() {
  const list = document.getElementById('admin-disputes-list');
  try {
    const disputes = await apiFetch('/admin.php?action=disputes');
    const badge = document.getElementById('disputes-count');
    if (disputes.length) {
      badge.textContent = disputes.length;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    if (!disputes.length) {
      list.innerHTML = emptyState('Спірних контрактів немає.');
      return;
    }
    list.innerHTML = disputes.map(disputeCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function disputeCard(d) {
  const clientName = [d.client_first_name, d.client_last_name].filter(Boolean).join(' ') || 'Клієнт';
  const freelancerName = [d.freelancer_first_name, d.freelancer_last_name].filter(Boolean).join(' ') || 'Фрилансер';
  return `
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="fw-semibold mb-1">Контракт #${d.contract_number || d.contract_id} — ${esc(formatUAH(d.amount))}</h6>
          <p class="text-muted small mb-0">Клієнт: ${esc(clientName)} · Фрилансер: ${esc(freelancerName)}</p>
          <p class="text-muted small mb-0">Оскаржено ${timeAgo(d.disputed_at)}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" onclick="decideDispute(${d.hold_id}, 'to_client')">На користь клієнта</button>
          <button class="btn btn-sm btn-success" onclick="decideDispute(${d.hold_id}, 'to_freelancer')">На користь фрилансера</button>
        </div>
      </div>
    </div>`;
}

async function decideDispute(holdId, resolution) {
  if (!confirm('Рішення щодо спору не можна скасувати. Продовжити?')) return;
  try {
    await apiPost('/admin.php', { action: 'resolve_dispute', hold_id: holdId, resolution });
    loadDisputes();
  } catch (e) {
    alert(e.message);
  }
}

// ─── ESCALATIONS («Вызов администратора») ────────────────────────────────────
async function loadEscalations() {
  const list = document.getElementById('admin-escalations-list');
  try {
    const escalations = await apiFetch('/admin.php?action=escalations');
    const badge = document.getElementById('escalations-count');
    if (escalations.length) {
      badge.textContent = escalations.length;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    if (!escalations.length) {
      list.innerHTML = emptyState('Відкритих звернень немає.');
      return;
    }
    list.innerHTML = escalations.map(escalationCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function escalationCard(e) {
  const clientName = [e.client_first_name, e.client_last_name].filter(Boolean).join(' ') || 'Клієнт';
  const freelancerName = [e.freelancer_first_name, e.freelancer_last_name].filter(Boolean).join(' ') || 'Фрилансер';
  const noun = e.parent_contract_id ? 'Етап' : 'Контракт';
  return `
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="fw-semibold mb-1">${esc(noun)} #${esc(e.contract_number)} — ${esc(formatUAH(e.price))}</h6>
          <p class="text-muted small mb-0">Клієнт: ${esc(clientName)} · Фрилансер: ${esc(freelancerName)}</p>
          <p class="text-muted small mb-0">Звернувся ${timeAgo(e.created_at)}: «${esc(truncateText(e.reason || '', 140))}»</p>
        </div>
        <button class="btn btn-sm btn-primary" onclick="openEscalationDetail(${e.id})">Розглянути</button>
      </div>
    </div>`;
}

let currentEscalation = null; // { escalation, contract, messages, estimates } — bundle currently open

async function openEscalationDetail(id) {
  const body = document.getElementById('escalation-detail-body');
  body.innerHTML = '<p class="text-muted">Завантаження...</p>';
  hideError('escalation-detail-error');
  new bootstrap.Modal(document.getElementById('escalationDetailModal')).show();

  try {
    currentEscalation = await apiFetch(`/admin.php?action=escalation_detail&id=${id}`);
    renderEscalationDetail();
  } catch (e) {
    body.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

const ESCALATION_ESTIMATE_STATUS  = { submitted: 'на розгляді', accepted: 'затверджено', rejected: 'відхилено' };

// Plain new-tab links — the admin page has no fullscreen gallery overlay.
function adminFileLinks(files) {
  if (!files || !files.length) return '';
  return '<div class="d-flex flex-wrap gap-1 mt-1">' + files.map(f => `
    <a href="${assetUrl(f.file_path)}" target="_blank" rel="noopener" class="attachment-file-link border rounded px-2 py-1 small text-decoration-none">
      ${fileIcon(f.mime_type)} ${esc(f.original_name)}
    </a>`).join('') + '</div>';
}

function renderEscalationDetail() {
  const { escalation, contract, messages, estimates } = currentEscalation;
  const submissions = currentEscalation.submissions || [];
  const body = document.getElementById('escalation-detail-body');

  const clientName = contract.client
    ? [contract.client.first_name, contract.client.last_name].filter(Boolean).join(' ') || contract.client.email
    : '—';
  const freelancerName = contract.freelancer
    ? [contract.freelancer.first_name, contract.freelancer.last_name].filter(Boolean).join(' ') || contract.freelancer.email
    : '—';
  const raisedByName = escalation.raised_by
    ? [escalation.raised_by.first_name, escalation.raised_by.last_name].filter(Boolean).join(' ')
        || (escalation.raised_by.role === 'client' ? 'Клієнт' : 'Фрилансер')
    : '—';
  const what = contract.parent_contract_id
    ? `етап #${esc(contract.number)} контракту #${esc(contract.parent_number)}`
    : `контракт #${esc(contract.number)}`;

  const messagesHtml = messages.length
    ? messages.map(m => `
        <div class="mb-2">
          <div class="small fw-semibold">${esc([m.first_name, m.last_name].filter(Boolean).join(' ') || 'Учасник')} <span class="text-muted fw-normal">· ${esc(timeAgo(m.created_at))}</span></div>
          ${m.body ? `<div class="small" style="white-space:pre-wrap">${esc(m.body)}</div>` : ''}
          ${adminFileLinks(m.attachments)}
        </div>`).join('')
    : '<p class="text-muted small mb-0">Повідомлень немає.</p>';

  const SUBMISSION_STATUS = { submitted: 'чекає на рішення клієнта', accepted: 'прийнято', rejected: 'повернуто на доопрацювання' };
  const submissionsHtml = submissions.length
    ? submissions.map(s => `
        <div class="border rounded p-2 mb-2">
          <div class="small text-muted">${esc(timeAgo(s.created_at))} · ${esc(SUBMISSION_STATUS[s.status] || s.status)}</div>
          ${s.comment ? `<div class="small" style="white-space:pre-wrap">${esc(s.comment)}</div>` : ''}
          ${adminFileLinks(s.files)}
          ${s.client_comment ? `<div class="small mt-1"><span class="text-muted">Клієнт:</span> ${esc(s.client_comment)}</div>` : ''}
        </div>`).join('')
    : '<p class="text-muted small mb-0">Роботу не здавали.</p>';

  const latestEstimate = estimates[0] || null;
  const estimateHtml = latestEstimate ? `
    <div class="table-responsive">
      <table class="table table-sm mb-1">
        <thead><tr><th>Матеріал / робота</th><th class="text-end">К-сть</th><th>Од.</th><th class="text-end">Ціна/од</th><th class="text-end">Сума</th></tr></thead>
        <tbody>
          ${latestEstimate.items.map(it => `
            <tr>
              <td>${esc(it.name)}</td>
              <td class="text-end">${esc(it.qty)}</td>
              <td>${esc(it.unit || '')}</td>
              <td class="text-end">${esc(formatUAH(it.unit_price))}</td>
              <td class="text-end">${esc(formatUAH(it.qty * it.unit_price))}</td>
            </tr>`).join('')}
        </tbody>
        <tfoot><tr><th colspan="4" class="text-end">Разом</th><th class="text-end">${esc(formatUAH(latestEstimate.total))}</th></tr></tfoot>
      </table>
    </div>
    <div class="small text-muted">Статус: ${esc(ESCALATION_ESTIMATE_STATUS[latestEstimate.status] || latestEstimate.status)}</div>
    ${latestEstimate.note ? `<div class="small text-muted">«${esc(latestEstimate.note)}»</div>` : ''}
  ` : '<p class="text-muted small mb-0">Кошторис не складено.</p>';

  body.innerHTML = `
    <div class="mb-3">
      <h6 class="fw-semibold mb-1">Причина звернення</h6>
      <p class="mb-1">«${esc(escalation.reason || '')}»</p>
      <p class="text-muted small mb-0">Звернувся: ${esc(raisedByName)} · ${esc(timeAgo(escalation.created_at))}</p>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <h6 class="fw-semibold mb-1">Контракт</h6>
        <dl class="row small mb-0">
          <dt class="col-5 text-muted fw-normal">Тип</dt><dd class="col-7">${esc(what)}</dd>
          <dt class="col-5 text-muted fw-normal">Ціна</dt><dd class="col-7 fw-semibold">${esc(formatUAH(contract.price))}</dd>
          <dt class="col-5 text-muted fw-normal">Термін</dt><dd class="col-7">${contract.deadline ? esc(contract.deadline) : '—'}</dd>
          <dt class="col-5 text-muted fw-normal">В ескроу</dt><dd class="col-7">${esc(formatUAH(contract.escrowed_amount))}</dd>
        </dl>
      </div>
      <div class="col-md-6">
        <h6 class="fw-semibold mb-1">Сторони</h6>
        <p class="small mb-1">Клієнт: ${esc(clientName)}${contract.client ? ' · ' + esc(contract.client.email) : ''}</p>
        <p class="small mb-0">Фрилансер: ${esc(freelancerName)}${contract.freelancer ? ' · ' + esc(contract.freelancer.email) : ''}</p>
      </div>
    </div>

    <div class="mb-3">
      <h6 class="fw-semibold mb-2">Переписка</h6>
      <div class="border rounded p-2" style="max-height:260px; overflow-y:auto">${messagesHtml}</div>
    </div>

    <div class="mb-3">
      <h6 class="fw-semibold mb-2">Здача роботи</h6>
      ${submissionsHtml}
    </div>

    <div class="mb-4">
      <h6 class="fw-semibold mb-2">Кошторис на матеріали</h6>
      ${estimateHtml}
    </div>

    <hr>

    <h6 class="fw-semibold mb-2">Рішення</h6>
    <div class="mb-3">
      <label class="form-label">Коментар адміністрації <span class="text-muted small">(побачать обидві сторони)</span></label>
      <textarea class="form-control" id="escalation-note" rows="2"></textarea>
    </div>

    <div class="card p-3 mb-2">
      <div class="fw-semibold small mb-2">Змінити умови без урахування голосів сторін</div>
      <div class="row g-2 mb-2">
        <div class="col">
          <input type="number" class="form-control form-control-sm" id="escalation-new-price" placeholder="Нова ціна, ₴" min="1">
        </div>
        <div class="col">
          <input type="date" class="form-control form-control-sm" id="escalation-new-deadline">
        </div>
      </div>
      <button class="btn btn-sm btn-primary" onclick="resolveEscalation('terms_changed')">Застосувати</button>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-sm btn-success" onclick="resolveEscalation('closed_success')">Закрити: виплата фрилансеру</button>
      <button class="btn btn-sm btn-outline-secondary" onclick="resolveEscalation('closed_failure')">Закрити: повернення клієнту (в резерв)</button>
      <button class="btn btn-sm btn-outline-dark" onclick="resolveEscalation('frozen')">🧊 Заморозити до з'ясування</button>
    </div>
    <p class="text-muted small mt-2 mb-0">«Заморозити» — заглушка: лише позначає контракт і сповіщає сторони, справжній тікет підтримки та внутрішнє розслідування біржі поки не реалізовано.</p>
  `;
}

async function resolveEscalation(resolution) {
  const labels = {
    terms_changed:  'змінити умови контракту',
    closed_success: 'закрити контракт із виплатою фрилансеру',
    closed_failure: 'закрити контракт із поверненням депозиту клієнту',
    frozen:         'заморозити контракт до з\'ясування',
  };
  if (!confirm(`Рішення не можна скасувати. ${labels[resolution] || resolution}?`)) return;

  const payload = {
    action: 'resolve_escalation',
    id: currentEscalation.escalation.id,
    resolution,
    note: val('escalation-note'),
  };
  if (resolution === 'terms_changed') {
    const price = val('escalation-new-price');
    const deadline = val('escalation-new-deadline');
    if (!price && !deadline) return showError('escalation-detail-error', 'Вкажіть нову ціну та/або термін');
    if (price) payload.new_price = Number(price);
    if (deadline) payload.new_deadline = deadline;
  }

  try {
    await apiPost('/admin.php', payload);
    bootstrap.Modal.getInstance(document.getElementById('escalationDetailModal')).hide();
    loadEscalations();
  } catch (e) {
    showError('escalation-detail-error', e.message);
  }
}

async function viewDocument(userId) {
  const viewer = document.getElementById('document-viewer');
  viewer.innerHTML = '<p class="text-muted">Завантаження...</p>';
  new bootstrap.Modal(document.getElementById('documentModal')).show();

  try {
    const res = await fetch(API + `/admin.php?action=document&user_id=${userId}`, {
      headers: { Authorization: 'Bearer ' + localStorage.getItem('token') }
    });
    if (!res.ok) throw new Error('Не вдалося завантажити документ');

    const contentType = res.headers.get('Content-Type') || '';
    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);

    viewer.innerHTML = contentType.includes('pdf')
      ? `<iframe src="${url}" style="width:100%;height:70vh;border:0"></iframe>`
      : `<img src="${url}" style="max-width:100%;max-height:70vh">`;
  } catch (e) {
    viewer.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}
