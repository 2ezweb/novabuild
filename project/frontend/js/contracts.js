let currentUser = null;
let allContracts = [];
let currentTab = 'active';               // 'active' | 'done'

const ACTIVE_STATUSES = ['proposed', 'active'];
const DONE_STATUSES   = ['completed', 'failed', 'rejected'];

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client' && currentUser.role !== 'freelancer') {
    location.href = 'dashboard.html';
    return;
  }

  ContractModal.init({
    me: currentUser,
    getContract: getContractById,
    reload: loadContracts,
    showChatLink: true,
  });

  loadContracts();
});

// Resolve top-level contracts and stages nested under a staged parent.
function getContractById(id) {
  id = Number(id);
  for (const c of allContracts) {
    if (c.id === id) return c;
    if (c.stages) { const s = c.stages.find(x => x.id === id); if (s) return s; }
  }
  return null;
}

// ─── LIST ─────────────────────────────────────────────────────────────────────
async function loadContracts() {
  try {
    allContracts = await apiFetch('/contracts.php');
  } catch (e) {
    document.getElementById('contract-list').innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
    return;
  }
  renderList();
}

function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('#contract-tabs .nav-link').forEach(b => {
    b.classList.toggle('active', b.dataset.tab === tab);
  });
  renderList();
}

function renderList() {
  const active = allContracts.filter(c => ACTIVE_STATUSES.includes(c.status));
  const done   = allContracts.filter(c => DONE_STATUSES.includes(c.status));
  document.getElementById('tab-count-active').textContent = active.length;
  document.getElementById('tab-count-done').textContent   = done.length;

  const rows = currentTab === 'active' ? active : done;
  document.getElementById('contract-list').innerHTML = rows.length
    ? rows.map(contractCard).join('')
    : emptyState(currentTab === 'active' ? 'Активних контрактів поки немає.' : 'Завершених контрактів поки немає.');
}

function contractCard(c) {
  const otherName = [c.other_first_name, c.other_last_name_initial].filter(Boolean).join(' ') || 'Співрозмовник';
  const roleNote = c.status === 'proposed'
    ? (Number(c.client_user_id) === currentUser.id ? 'ви запропонували' : 'вам запропонували')
    : '';
  const staged = c.type === 'staged';
  const typeBadge = staged
    ? `<span class="badge bg-info text-dark">поетапний · ${(c.stages || []).length} ет.</span>`
    : '';
  const escrowNote = c.status === 'active' && Number(c.escrowed_amount) > 0
    ? `<div class="text-muted small mt-1">в ескроу: ${esc(formatUAH(c.escrowed_amount))}${staged ? '' : ' / ' + esc(formatUAH(c.price))}</div>`
    : '';
  const payBadge = paymentBadgeHtml(c);

  return `
    <div class="card offer-card mb-3 p-3" role="button" onclick="openContractModal(${c.id})">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="badge ${CONTRACT_STATUS_BADGE[c.status] || 'bg-secondary'}">${esc(CONTRACT_STATUS_LABELS[c.status] || c.status)}</span>
          ${typeBadge}
          <span class="text-muted small">#${esc(c.number || c.id)}</span>
          <span class="fw-semibold">${esc(otherName)}</span>
          ${roleNote ? `<span class="text-muted small">(${roleNote})</span>` : ''}
        </div>
        <div class="text-end">
          <div class="fw-semibold">${esc(formatUAH(c.price))}</div>
          ${!staged && c.deadline ? `<div class="text-muted small">до ${esc(c.deadline)}</div>` : ''}
        </div>
      </div>
      ${escrowNote}
      ${payBadge ? `<div class="mt-1">${payBadge}</div>` : ''}
    </div>`;
}
