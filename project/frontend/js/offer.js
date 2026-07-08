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
      </div>
      <div id="offer-attachments" class="mt-3"></div>`;

    if (offer.attachment_count) loadOfferAttachments();
  } catch (e) {
    showError('offer-error', e.message);
  }
}

async function loadOfferAttachments() {
  const container = document.getElementById('offer-attachments');
  try {
    const attachments = await apiFetch(`/offer_attachments.php?offer_id=${offerId}`);
    if (!attachments.length) return;
    container.innerHTML = '<div class="text-muted small mb-1">Файлы:</div><div></div>';
    renderAttachments(container.querySelector('div'), attachments);
  } catch { /* non-critical */ }
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
  const avatarUrlValue = assetUrl(b.avatar_path);
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
      ${coverNoteHtml(b.cover_note, b.id)}
      <div class="text-muted small mt-2">Подана ${timeAgo(b.created_at)}</div>
    </div>`;
}

// Clients see the first 3 paragraphs of a freelancer's pitch by default;
// clicking "Показать полностью" expands the rest in place.
function coverNoteHtml(note, bidId) {
  if (!note) return '';

  const paragraphs = note.split('\n').filter(p => p.trim());
  const previewHtml = esc(paragraphs.slice(0, 3).join('\n')).replace(/\n/g, '<br>');

  if (paragraphs.length <= 3) {
    return `<p class="text-muted small mt-2 mb-0">${previewHtml}</p>`;
  }

  const fullHtml = esc(note).replace(/\n/g, '<br>');
  return `
    <div class="mt-2">
      <p class="text-muted small mb-1" id="cover-preview-${bidId}">${previewHtml}…</p>
      <p class="text-muted small mb-1 d-none" id="cover-full-${bidId}">${fullHtml}</p>
      <a href="#" class="small" id="cover-toggle-${bidId}" onclick="toggleCoverNote(${bidId}); return false;">Показать полностью</a>
    </div>`;
}

function toggleCoverNote(bidId) {
  const preview = document.getElementById(`cover-preview-${bidId}`);
  const full    = document.getElementById(`cover-full-${bidId}`);
  const toggle  = document.getElementById(`cover-toggle-${bidId}`);
  const showingFull = !full.classList.contains('d-none');

  preview.classList.toggle('d-none', !showingFull);
  full.classList.toggle('d-none', showingFull);
  toggle.textContent = showingFull ? 'Показать полностью' : 'Свернуть';
}
