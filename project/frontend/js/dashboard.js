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
async function loadClientDashboard() {
  try {
    const offers = await apiFetch('/offers.php');
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
  return `
    <div class="card offer-card mb-3 p-3">
      <div class="d-flex justify-content-between text-muted small mb-2">
        <span>Опубликовано ${timeAgo(o.created_at)}</span>
        <span>Заявок: ${o.bid_count || 0}</span>
      </div>
      <h6 class="fw-semibold mb-1">${esc(o.title)}</h6>
      <p class="text-muted small mb-2">${esc(o.description || '')}</p>
      <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-secondary">${statusLabel(o.status)}</span>
        ${o.budget ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
        ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
      </div>
    </div>`;
}

async function submitOffer() {
  const title       = val('offer-title');
  const description = val('offer-desc');
  const budget      = val('offer-budget');
  const deadline    = val('offer-deadline');
  hideError('offer-error');
  if (!title) return showError('offer-error', 'Укажите заголовок');
  try {
    await apiPost('/offers.php', { title, description, budget, deadline });
    bootstrap.Modal.getInstance(document.getElementById('offerModal')).hide();
    loadClientDashboard();
  } catch (e) {
    showError('offer-error', e.message);
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
