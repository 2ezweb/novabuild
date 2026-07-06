let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  renderSidebarProfile();
  if (currentUser.role === 'client') {
    document.getElementById('client-content').style.display = '';
    loadClientDashboard();
  } else {
    document.getElementById('freelancer-content').style.display = '';
    setVerificationBadge(currentUser.verification_status);
    loadFreelancerDashboard();
  }
});

// ─── SIDEBAR ──────────────────────────────────────────────────────────────────
function renderSidebarProfile() {
  const isFreelancer = currentUser.role === 'freelancer';
  const name = isFreelancer
    ? (currentUser.full_name || currentUser.email)
    : (currentUser.company_name || currentUser.contact_name || currentUser.email);
  const sub = isFreelancer
    ? (currentUser.specialization || 'Специализация не указана')
    : (currentUser.contact_name || 'Контакт не указан');

  document.querySelectorAll('.sidebar-avatar').forEach(el => el.textContent = initials(name));
  document.querySelectorAll('.sidebar-name').forEach(el => el.textContent = name);
  document.querySelectorAll('.sidebar-sub').forEach(el => el.textContent = sub);

  if (isFreelancer) {
    const fields = [currentUser.full_name, currentUser.phone, currentUser.specialization, currentUser.about];
    const pct = Math.round(fields.filter(Boolean).length / fields.length * 100);
    document.getElementById('profile-completeness-pct').textContent = pct + '%';
    document.getElementById('profile-completeness-bar').style.width = pct + '%';
  }
}

// ─── CLIENT ───────────────────────────────────────────────────────────────────
let clientOffers = [];

async function loadClientDashboard() {
  try {
    const offers = await apiFetch('/offers.php');
    clientOffers = offers;
    document.getElementById('stat-offers').textContent = offers.length;

    const totalBids = offers.reduce((sum, o) => sum + (o.bid_count || 0), 0);
    document.getElementById('stat-bids').textContent = totalBids;

    const list = document.getElementById('client-offers-list');
    if (!offers.length) {
      list.innerHTML = emptyState('Офферов пока нет. Разместите первый!');
      return;
    }
    list.innerHTML = offers.map(offerCardClient).join('');
  } catch (e) {
    document.getElementById('client-offers-list').innerHTML =
      `<div class="alert alert-warning">${e.message}</div>`;
  }
}

function offerCardClient(o) {
  const canManage = o.status !== 'closed';
  return `
    <div class="card offer-card mb-3 p-3">
      <div class="d-flex justify-content-between text-muted small mb-2">
        <span>Опубликовано ${timeAgo(o.created_at)}</span>
        <span>Заявок: ${o.bid_count || 0}</span>
      </div>
      <h6 class="fw-semibold mb-1">${esc(o.title)}</h6>
      <p class="text-muted small mb-2">${esc(o.description || '')}</p>
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap">
          <span class="badge bg-secondary">${statusLabel(o.status)}</span>
          ${o.budget ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
        </div>
        ${canManage ? `
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="editOffer(${o.id})">Редактировать</button>
            <button class="btn btn-sm btn-outline-danger" onclick="closeOffer(${o.id})">Закрыть</button>
          </div>` : ''}
      </div>
    </div>`;
}

function openCreateOffer() {
  document.getElementById('offer-id').value = '';
  document.getElementById('offer-title').value = '';
  document.getElementById('offer-desc').value = '';
  document.getElementById('offer-budget').value = '';
  document.getElementById('offer-deadline').value = '';
  document.getElementById('offerModalLabel').textContent = 'Новый оффер';
  document.getElementById('offer-submit-btn').textContent = 'Разместить';
  hideError('offer-error');
  new bootstrap.Modal(document.getElementById('offerModal')).show();
}

function editOffer(id) {
  const o = clientOffers.find(x => x.id === id);
  if (!o) return;
  document.getElementById('offer-id').value = o.id;
  document.getElementById('offer-title').value = o.title;
  document.getElementById('offer-desc').value = o.description || '';
  document.getElementById('offer-budget').value = o.budget || '';
  document.getElementById('offer-deadline').value = o.deadline || '';
  document.getElementById('offerModalLabel').textContent = 'Редактировать оффер';
  document.getElementById('offer-submit-btn').textContent = 'Сохранить';
  hideError('offer-error');
  new bootstrap.Modal(document.getElementById('offerModal')).show();
}

async function submitOffer() {
  const id          = val('offer-id');
  const title       = val('offer-title');
  const description = val('offer-desc');
  const budget      = val('offer-budget');
  const deadline    = val('offer-deadline');
  hideError('offer-error');
  if (!title) return showError('offer-error', 'Укажите заголовок');
  try {
    if (id) {
      await apiPut('/offers.php', { id: Number(id), title, description, budget, deadline });
    } else {
      await apiPost('/offers.php', { title, description, budget, deadline });
    }
    bootstrap.Modal.getInstance(document.getElementById('offerModal')).hide();
    loadClientDashboard();
  } catch (e) {
    showError('offer-error', e.message);
  }
}

async function closeOffer(id) {
  if (!confirm('Закрыть этот оффер? Фрилансеры больше не смогут его увидеть или откликнуться.')) return;
  try {
    await apiPut('/offers.php', { id, status: 'closed' });
    loadClientDashboard();
  } catch (e) {
    alert(e.message);
  }
}

// ─── FREELANCER ───────────────────────────────────────────────────────────────
async function loadFreelancerDashboard() {
  try {
    const [offers, myBids] = await Promise.all([
      apiFetch('/offers.php?status=open'),
      apiFetch('/bids.php?my=1'),
    ]);

    document.getElementById('stat-open-offers').textContent = offers.length;
    document.getElementById('stat-my-bids').textContent     = myBids.length;

    // mark which offers I've already bid on
    const bidOfferIds = new Set(myBids.map(b => b.offer_id));

    const list = document.getElementById('freelancer-offers-list');
    if (!offers.length) {
      list.innerHTML = emptyState('Нет доступных офферов. Загляните позже.');
      return;
    }
    list.innerHTML = offers.map(o => offerCardFreelancer(o, bidOfferIds.has(o.id))).join('');
  } catch (e) {
    document.getElementById('freelancer-offers-list').innerHTML =
      `<div class="alert alert-warning">${e.message}</div>`;
  }
}

function offerCardFreelancer(o, alreadyBid) {
  return `
    <div class="card offer-card mb-3 p-3">
      <div class="d-flex justify-content-between text-muted small mb-2">
        <span>Опубликовано ${timeAgo(o.created_at)}</span>
        <span>Заявок: ${o.bid_count || 0}</span>
      </div>
      <h6 class="fw-semibold mb-1">${esc(o.title)}</h6>
      <p class="text-muted small mb-2">${esc(o.description || '')}</p>
      <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex gap-2 flex-wrap">
          ${o.budget   ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
        </div>
        ${alreadyBid
          ? `<span class="badge bg-success">✓ Заявка подана</span>`
          : `<button class="btn btn-sm btn-outline-primary" onclick="placeBid(${o.id}, this)">Подать заявку</button>`
        }
      </div>
    </div>`;
}

async function placeBid(offerId, btn) {
  btn.disabled = true;
  btn.textContent = '...';
  try {
    await apiPost('/bids.php', { offer_id: offerId });
    btn.outerHTML = `<span class="badge bg-success">✓ Заявка подана</span>`;
    const stat = document.getElementById('stat-my-bids');
    stat.textContent = (parseInt(stat.textContent) || 0) + 1;
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Подать заявку';
    alert(e.message);
  }
}
