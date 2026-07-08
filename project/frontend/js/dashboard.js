let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role === 'admin') {
    location.href = 'admin.html';
    return;
  }
  renderSidebarProfile();
  if (currentUser.role === 'client') {
    document.getElementById('client-content').style.display = '';
    loadClientDashboard();
  } else {
    document.getElementById('freelancer-content').style.display = '';
    loadFreelancerDashboard();
  }
});

// ─── SIDEBAR ──────────────────────────────────────────────────────────────────
function renderSidebarProfile() {
  const isFreelancer = currentUser.role === 'freelancer';
  const name = displayName(currentUser);
  const sub = isFreelancer
    ? (currentUser.specialization || 'Специализация не указана')
    : (currentUser.company_name ? currentUser.company_name : 'Частное лицо');

  document.querySelectorAll('.sidebar-avatar').forEach(el => renderAvatarEl(el, name, currentUser.avatar_path));
  document.querySelectorAll('.sidebar-name').forEach(el => el.innerHTML = `${esc(name)} ${roleBadgeHtml(currentUser)}`);
  document.querySelectorAll('.sidebar-sub').forEach(el => el.textContent = sub);

  setVerificationBadge(currentUser.verification_status);

  if (isFreelancer) {
    const fields = [currentUser.first_name, currentUser.phone, currentUser.specialization, currentUser.about];
    const pct = Math.round(fields.filter(Boolean).length / fields.length * 100);
    document.getElementById('profile-completeness-pct').textContent = pct + '%';
    document.getElementById('profile-completeness-bar').style.width = pct + '%';
    document.getElementById('stat-connects').textContent = currentUser.connects_balance ?? '—';
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

    const visibleOffers = offers.filter(o => o.status !== 'closed');
    const list = document.getElementById('client-offers-list');
    if (!visibleOffers.length) {
      list.innerHTML = emptyState('Офферов пока нет. Разместите первый!');
      return;
    }
    list.innerHTML = visibleOffers.map(offerCardClient).join('');
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
      <h6 class="fw-semibold mb-1"><a class="offer-title-link" onclick="openOfferDetail(${o.id})">${esc(o.title)}</a></h6>
      <p class="text-muted small mb-2">${esc(truncateText(o.description, 150))}</p>
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap">
          <span class="badge bg-secondary">${statusLabel(o.status)}</span>
          ${o.budget ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
          ${o.attachment_count ? `<span class="badge bg-light text-dark border">📎 ${o.attachment_count}</span>` : ''}
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-primary" href="offer.html?id=${o.id}">Заявки${o.bid_count ? ` (${o.bid_count})` : ''}</a>
          <button class="btn btn-sm btn-outline-secondary" onclick="editOffer(${o.id})">Редактировать</button>
          <button class="btn btn-sm btn-outline-danger" onclick="closeOffer(${o.id})">Закрыть</button>
        </div>
      </div>
    </div>`;
}

function openCreateOffer() {
  document.getElementById('offer-id').value = '';
  document.getElementById('offer-title').value = '';
  document.getElementById('offer-desc').value = '';
  document.getElementById('offer-budget').value = '';
  document.getElementById('offer-deadline').value = '';
  document.getElementById('offer-files').value = '';
  document.getElementById('offer-existing-files').innerHTML = '';
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
  document.getElementById('offer-files').value = '';
  document.getElementById('offerModalLabel').textContent = 'Редактировать оффер';
  document.getElementById('offer-submit-btn').textContent = 'Сохранить';
  hideError('offer-error');
  loadOfferAttachmentsIntoEditModal(o.id);
  new bootstrap.Modal(document.getElementById('offerModal')).show();
}

function attachmentRow(a, deletable) {
  return `
    <div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1 small">
      <a href="${assetUrl(a.file_path)}" target="_blank" rel="noopener" class="text-decoration-none">
        ${fileIcon(a.mime_type)} ${esc(a.original_name)} <span class="text-muted">(${formatFileSize(a.size)})</span>
      </a>
      ${deletable ? `<button type="button" class="btn-close" style="font-size:.65rem" title="Удалить" onclick="deleteOfferAttachment(${a.id}, ${a.offer_id})"></button>` : ''}
    </div>`;
}

async function loadOfferAttachmentsIntoEditModal(offerId) {
  const container = document.getElementById('offer-existing-files');
  container.innerHTML = '<p class="text-muted small">Загрузка...</p>';
  try {
    const attachments = await apiFetch(`/offer_attachments.php?offer_id=${offerId}`);
    container.innerHTML = attachments.map(a => attachmentRow({ ...a, offer_id: offerId }, true)).join('');
  } catch {
    container.innerHTML = '';
  }
}

async function deleteOfferAttachment(id, offerId) {
  if (!confirm('Удалить файл?')) return;
  try {
    await apiDelete('/offer_attachments.php', { id });
    loadOfferAttachmentsIntoEditModal(offerId);
  } catch (e) {
    alert(e.message);
  }
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
    let offerId = Number(id) || null;
    if (offerId) {
      await apiPut('/offers.php', { id: offerId, title, description, budget, deadline });
    } else {
      const res = await apiPost('/offers.php', { title, description, budget, deadline });
      offerId = res.id;
    }

    const filesInput = document.getElementById('offer-files');
    if (filesInput.files.length) {
      const formData = new FormData();
      formData.append('offer_id', offerId);
      for (const file of filesInput.files) formData.append('files[]', file);
      await apiUpload('/offer_attachments.php', formData);
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
let freelancerOffers = [];
let bidOfferIds = new Set();

async function loadFreelancerDashboard() {
  try {
    const [offers, myBids] = await Promise.all([
      apiFetch('/offers.php?status=open'),
      apiFetch('/bids.php?my=1'),
    ]);
    freelancerOffers = offers;

    document.getElementById('stat-open-offers').textContent = offers.length;
    document.getElementById('stat-my-bids').textContent     = myBids.length;

    // mark which offers I've already bid on
    bidOfferIds = new Set(myBids.map(b => b.offer_id));

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
  const clientLabel = o.client_company_name ? esc(o.client_company_name) : 'Частное лицо';
  const clientBadge = o.client_verification_status === 'verified' ? '<span class="badge bg-success ms-1">✓</span>' : '';
  return `
    <div class="card offer-card mb-3 p-3">
      <div class="d-flex justify-content-between text-muted small mb-2">
        <span>Опубликовано ${timeAgo(o.created_at)}</span>
        <span>Заявок: ${o.bid_count || 0}</span>
      </div>
      <div class="text-muted small mb-1">${clientLabel}${clientBadge}</div>
      <h6 class="fw-semibold mb-1"><a class="offer-title-link" onclick="openOfferDetail(${o.id})">${esc(o.title)}</a></h6>
      <p class="text-muted small mb-2">${esc(truncateText(o.description, 150))}</p>
      <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex gap-2 flex-wrap">
          ${o.budget   ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
          ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
          ${o.attachment_count ? `<span class="badge bg-light text-dark border">📎 ${o.attachment_count}</span>` : ''}
        </div>
        ${alreadyBid
          ? `<span class="badge bg-success">✓ Заявка подана</span>`
          : `<button class="btn btn-sm btn-outline-primary" onclick="openBidModal(${o.id})">Подать заявку</button>`
        }
      </div>
    </div>`;
}

async function openBidModal(offerId) {
  document.getElementById('bid-offer-id').value = offerId;
  document.getElementById('bid-connects').value = 10;
  document.getElementById('bid-balance').textContent = currentUser.connects_balance ?? 0;
  document.getElementById('bid-message').value = '';
  hideError('bid-error');

  const container = document.getElementById('bid-attachments');
  container.innerHTML = '';
  try {
    const attachments = await apiFetch(`/offer_attachments.php?offer_id=${offerId}`);
    if (attachments.length) {
      container.innerHTML = '<label class="form-label">Файлы от заказчика</label><div></div>';
      renderAttachments(container.querySelector('div'), attachments);
    }
  } catch { /* non-critical */ }

  new bootstrap.Modal(document.getElementById('bidModal')).show();
}

// ─── OFFER DETAIL MODAL ───────────────────────────────────────────────────────
async function openOfferDetail(offerId) {
  const isClient = currentUser.role === 'client';
  const source = isClient ? clientOffers : freelancerOffers;
  const o = source.find(x => x.id === offerId);
  if (!o) return;

  document.getElementById('offer-detail-title').textContent = o.title;
  document.getElementById('offer-detail-description').textContent = o.description || '';
  document.getElementById('offer-detail-badges').innerHTML = `
    <span class="badge bg-secondary">${statusLabel(o.status)}</span>
    ${o.budget ? `<span class="badge bg-light text-dark border">₴ ${Number(o.budget).toLocaleString('uk-UA')}</span>` : ''}
    ${o.deadline ? `<span class="badge bg-light text-dark border">до ${o.deadline}</span>` : ''}
  `;

  const clientInfoEl = document.getElementById('offer-detail-client-info');
  if (isClient) {
    clientInfoEl.innerHTML = '';
  } else {
    const clientLabel = o.client_company_name ? esc(o.client_company_name) : 'Частное лицо';
    const clientBadge = o.client_verification_status === 'verified' ? '<span class="badge bg-success ms-1">✓</span>' : '';
    clientInfoEl.innerHTML = `<div class="text-muted small mb-2">Заказчик: ${clientLabel}${clientBadge}</div>`;
  }

  const bidBtn = document.getElementById('offer-detail-bid-btn');
  if (!isClient && !bidOfferIds.has(offerId)) {
    bidBtn.style.display = '';
    bidBtn.onclick = () => {
      const detailModalEl = document.getElementById('offerDetailModal');
      detailModalEl.addEventListener('hidden.bs.modal', () => openBidModal(offerId), { once: true });
      bootstrap.Modal.getInstance(detailModalEl).hide();
    };
  } else {
    bidBtn.style.display = 'none';
  }

  const attachmentsEl = document.getElementById('offer-detail-attachments');
  attachmentsEl.innerHTML = '<p class="text-muted small">Загрузка...</p>';
  try {
    const attachments = await apiFetch(`/offer_attachments.php?offer_id=${offerId}`);
    renderAttachments(attachmentsEl, attachments);
  } catch (e) {
    attachmentsEl.innerHTML = `<p class="text-danger small">${esc(e.message)}</p>`;
  }

  new bootstrap.Modal(document.getElementById('offerDetailModal')).show();
}

async function submitBid() {
  const offerId  = Number(val('bid-offer-id'));
  const connects = Number(val('bid-connects'));
  const message  = val('bid-message');
  hideError('bid-error');
  if (!connects || connects < 10) return showError('bid-error', 'Минимальная ставка — 10 коннектов');

  try {
    const res = await apiPost('/bids.php', { offer_id: offerId, connects, cover_note: message });
    bootstrap.Modal.getInstance(document.getElementById('bidModal')).hide();

    currentUser.connects_balance = res.connects_balance;
    document.getElementById('stat-connects').textContent = res.connects_balance;

    loadFreelancerDashboard();
  } catch (e) {
    showError('bid-error', e.message);
  }
}
