let currentUser = null;
let offerId = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client') {
    location.href = 'dashboard.html';
    return;
  }

  offerId = Number(new URLSearchParams(location.search).get('id'));
  if (!offerId) {
    location.href = 'dashboard.html';
    return;
  }

  loadOffer();
  loadBids();
});

async function loadOffer() {
  try {
    const offers = await apiFetch('/offers.php');
    const offer = offers.find(o => o.id === offerId);
    if (!offer) {
      document.getElementById('offer-summary').innerHTML = '';
      showError('offer-error', 'Оффер не найден');
      return;
    }

    document.getElementById('offer-summary').innerHTML = `
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <h4 class="fw-bold mb-1">${esc(offer.title)}</h4>
          <p class="text-muted mb-2">${esc(offer.description || '')}</p>
          <span class="badge bg-secondary">${statusLabel(offer.status)}</span>
          ${offer.budget ? `<span class="badge bg-light text-dark border">₴ ${Number(offer.budget).toLocaleString('uk-UA')}</span>` : ''}
          ${offer.deadline ? `<span class="badge bg-light text-dark border">до ${offer.deadline}</span>` : ''}
        </div>
      </div>`;
  } catch (e) {
    showError('offer-error', e.message);
  }
}

async function loadBids() {
  const list = document.getElementById('bids-list');
  try {
    const bids = await apiFetch(`/bids.php?offer_id=${offerId}`);
    if (!bids.length) {
      list.innerHTML = emptyState('Заявок пока нет.');
      return;
    }
    list.innerHTML = bids.map((b, i) => bidCard(b, i + 1)).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function bidCard(b, rank) {
  const name = [b.first_name, b.last_name_initial].filter(Boolean).join(' ') || 'Фрилансер';
  const verified = b.verification_status === 'verified' ? '<span class="badge bg-success ms-1">✓</span>' : '';
  const rankBadge = rank <= 5
    ? `<span class="badge bg-primary">#${rank}</span>`
    : `<span class="badge bg-light text-dark border">#${rank}</span>`;
  const avatarUrlValue = avatarUrl(b.avatar_path);
  const avatarStyle = avatarUrlValue
    ? `background-image:url('${avatarUrlValue}');background-size:cover;background-position:center`
    : '';

  return `
    <div class="card offer-card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-semibold" style="width:48px;height:48px;font-size:1rem;${avatarStyle}">${avatarUrlValue ? '' : esc(initials(name))}</div>
          <div>
            <div class="fw-semibold">${esc(name)}${verified}</div>
            <div class="text-muted small">${esc(b.specialization || 'Специализация не указана')}</div>
          </div>
        </div>
        <div class="text-end">
          ${rankBadge}
          <div class="text-muted small mt-1">Ставка: ${b.connects_spent} коннектов</div>
        </div>
      </div>
      ${b.cover_note ? `<p class="text-muted small mt-2 mb-0">${esc(b.cover_note)}</p>` : ''}
      <div class="text-muted small mt-2">Подана ${timeAgo(b.created_at)}</div>
    </div>`;
}
