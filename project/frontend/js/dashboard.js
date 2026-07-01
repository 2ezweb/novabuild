let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role === 'client') {
    document.getElementById('client-content').style.display = '';
    loadClientDashboard();
  } else {
    document.getElementById('freelancer-content').style.display = '';
    setVerificationBadge(currentUser.verification_status);
    loadFreelancerDashboard();
  }
});

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
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h6 class="fw-semibold mb-1">${esc(o.title)}</h6>
          <p class="text-muted small mb-2">${esc(o.description || '')}</p>
          <span class="badge bg-secondary me-1">${statusLabel(o.status)}</span>
          ${o.budget ? `<span class="badge bg-light text-dark border">₽ ${Number(o.budget).toLocaleString('ru')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
        </div>
        <div class="text-end ms-3">
          <div class="fw-bold text-primary fs-5">${o.bid_count || 0}</div>
          <div class="text-muted small">заявок</div>
        </div>
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
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h6 class="fw-semibold mb-1">${esc(o.title)}</h6>
          <p class="text-muted small mb-2">${esc(o.description || '')}</p>
          ${o.budget   ? `<span class="badge bg-light text-dark border me-1">₽ ${Number(o.budget).toLocaleString('ru')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
        </div>
        <div class="ms-3 flex-shrink-0">
          ${alreadyBid
            ? `<span class="badge bg-success">✓ Заявка подана</span>`
            : `<button class="btn btn-sm btn-outline-primary" onclick="placeBid(${o.id}, this)">Подать заявку</button>`
          }
        </div>
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
