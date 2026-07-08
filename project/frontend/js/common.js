// ─── CONFIG ──────────────────────────────────────────────────────────────────
// pages live in /frontend/pages/, api lives in /project/api
const API = '../../api';

// ─── DOM HELPERS ──────────────────────────────────────────────────────────────
function val(id)  { return document.getElementById(id)?.value?.trim() ?? ''; }
function esc(s)   { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function initials(name) { return String(name || '?').trim().charAt(0).toUpperCase(); }

function displayName(me) {
  return me.first_name || me.company_name || me.email;
}

// Uploaded file paths are stored relative to /project/; pages live in /project/frontend/pages/.
// Used for avatars, verification docs (admin-only, via a different endpoint) and offer attachments.
function assetUrl(path) { return path ? '../../' + path : null; }

function truncateText(text, maxLen) {
  if (!text || text.length <= maxLen) return text || '';
  return text.slice(0, maxLen).trimEnd() + '…';
}

// Sets a circular avatar element to either the uploaded photo or an initial-on-background fallback.
function renderAvatarEl(el, name, avatarPath) {
  if (!el) return;
  const url = assetUrl(avatarPath);
  if (url) {
    el.style.backgroundImage = `url('${url}')`;
    el.style.backgroundSize = 'cover';
    el.style.backgroundPosition = 'center';
    el.textContent = '';
  } else {
    el.style.backgroundImage = '';
    el.textContent = initials(name);
  }
}

// Small badge shown next to a display name: gold for admin, green check for verified peers.
function roleBadgeHtml(me) {
  if (me.role === 'admin') return '<span class="badge badge-admin ms-1">★ Админ</span>';
  if (me.verification_status === 'verified') return '<span class="badge bg-success ms-1">✓</span>';
  return '';
}

function timeAgo(mysqlDatetime) {
  const diffMin = Math.floor((Date.now() - new Date(mysqlDatetime.replace(' ', 'T')).getTime()) / 60000);
  if (diffMin < 1)   return 'только что';
  if (diffMin < 60)  return `${diffMin} мин назад`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24)   return `${diffHr} ч назад`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay === 1) return 'вчера';
  if (diffDay < 30)  return `${diffDay} дн назад`;
  return `${Math.floor(diffDay / 30)} мес назад`;
}

function showError(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.classList.remove('d-none');
}
function hideError(id) {
  document.getElementById(id).classList.add('d-none');
}

// ─── API HELPERS ──────────────────────────────────────────────────────────────
function apiError(data) {
  return Object.assign(new Error(data.error || 'Server error'), data);
}

async function apiFetch(path) {
  const res = await fetch(API + path, {
    headers: { Authorization: 'Bearer ' + localStorage.getItem('token') }
  });
  const data = await res.json();
  if (!res.ok) throw apiError(data);
  return data;
}

async function apiPost(path, body) {
  const res = await fetch(API + path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization:  'Bearer ' + localStorage.getItem('token')
    },
    body: JSON.stringify(body)
  });
  const data = await res.json();
  if (!res.ok) throw apiError(data);
  return data;
}

async function apiPut(path, body) {
  const res = await fetch(API + path, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      Authorization:  'Bearer ' + localStorage.getItem('token')
    },
    body: JSON.stringify(body)
  });
  const data = await res.json();
  if (!res.ok) throw apiError(data);
  return data;
}

async function apiDelete(path, body) {
  const res = await fetch(API + path, {
    method: 'DELETE',
    headers: {
      'Content-Type': 'application/json',
      Authorization:  'Bearer ' + localStorage.getItem('token')
    },
    body: JSON.stringify(body)
  });
  const data = await res.json();
  if (!res.ok) throw apiError(data);
  return data;
}

async function apiUpload(path, formData) {
  const res = await fetch(API + path, {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + localStorage.getItem('token') },
    body: formData,
  });
  const data = await res.json();
  if (!res.ok) throw apiError(data);
  return data;
}

function formatFileSize(bytes) {
  if (bytes < 1024) return bytes + ' Б';
  if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' КБ';
  return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
}

function fileIcon(mime) {
  if (mime === 'application/pdf') return '📄';
  if (mime.startsWith('image/')) return '🖼️';
  if (mime.includes('spreadsheet')) return '📊';
  if (mime.includes('wordprocessing') || mime === 'application/zip') return '📝';
  return '📎';
}

// Renders image attachments as a thumbnail grid (click opens the fullscreen gallery)
// and everything else as downloadable file rows. Shared by the offer detail modal and offer.html.
function renderAttachments(container, attachments) {
  if (!attachments.length) {
    container.innerHTML = '<p class="text-muted small mb-0">Файлов нет.</p>';
    return;
  }

  const images = attachments.filter(a => a.mime_type.startsWith('image/'));
  const files  = attachments.filter(a => !a.mime_type.startsWith('image/'));
  const imageUrls = images.map(a => assetUrl(a.file_path));

  let html = '';
  if (images.length) {
    html += '<div class="d-flex flex-wrap gap-2 mb-2">' + images.map((a, i) => `
      <img src="${assetUrl(a.file_path)}" alt="${esc(a.original_name)}" title="${esc(a.original_name)}"
           class="attachment-thumb" onclick="openGallery(${esc(JSON.stringify(imageUrls))}, ${i})">
    `).join('') + '</div>';
  }
  if (files.length) {
    html += '<div class="d-flex flex-wrap gap-1">' + files.map(a => `
      <a href="${assetUrl(a.file_path)}" target="_blank" rel="noopener" class="border rounded px-2 py-1 small text-decoration-none">
        ${fileIcon(a.mime_type)} ${esc(a.original_name)} <span class="text-muted">(${formatFileSize(a.size)})</span>
      </a>`).join('') + '</div>';
  }
  container.innerHTML = html;
}

// ─── FULLSCREEN IMAGE GALLERY ─────────────────────────────────────────────────
let galleryImages = [];
let galleryIndex = 0;

function openGallery(images, index) {
  galleryImages = images;
  galleryIndex = index;
  renderGalleryImage();
  document.getElementById('image-gallery-overlay').style.display = 'flex';
}

function closeGallery() {
  document.getElementById('image-gallery-overlay').style.display = 'none';
}

function galleryStep(delta) {
  galleryIndex = (galleryIndex + delta + galleryImages.length) % galleryImages.length;
  renderGalleryImage();
}

function renderGalleryImage() {
  document.getElementById('gallery-image').src = galleryImages[galleryIndex];
  document.getElementById('gallery-counter').textContent = `${galleryIndex + 1} / ${galleryImages.length}`;
  const multi = galleryImages.length > 1;
  document.getElementById('gallery-prev').style.display = multi ? '' : 'none';
  document.getElementById('gallery-next').style.display = multi ? '' : 'none';
}

document.addEventListener('keydown', (e) => {
  const overlay = document.getElementById('image-gallery-overlay');
  if (!overlay || overlay.style.display === 'none') return;
  if (e.key === 'Escape') closeGallery();
  if (e.key === 'ArrowLeft') galleryStep(-1);
  if (e.key === 'ArrowRight') galleryStep(1);
});

// ─── AUTH GUARD ───────────────────────────────────────────────────────────────
// Call from protected pages (cabinet.html, dashboard.html) on load.
// Resolves with the current user, or redirects to login.html and never resolves.
async function requireAuth() {
  if (!localStorage.getItem('token')) {
    location.href = 'login.html';
    return new Promise(() => {});
  }
  try {
    const me = await apiFetch('/me.php');
    const label = document.getElementById('nav-email');
    if (label) label.textContent = me.email;
    renderAvatarEl(document.getElementById('nav-avatar'), displayName(me), me.avatar_path);

    const homeHref = me.role === 'admin' ? 'admin.html' : 'dashboard.html';
    const brand = document.getElementById('nav-brand');
    if (brand) brand.href = homeHref;
    const homeLink = document.getElementById('nav-home-link');
    if (homeLink) {
      homeLink.href = homeHref;
      homeLink.textContent = me.role === 'admin' ? 'Админка' : 'Дашборд';
    }

    initNotificationBell();
    return me;
  } catch {
    localStorage.removeItem('token');
    location.href = 'login.html';
    return new Promise(() => {});
  }
}

function logout() {
  localStorage.removeItem('token');
  location.href = 'login.html';
}

// ─── SHARED UI HELPERS ────────────────────────────────────────────────────────
function setVerificationBadge(status) {
  document.querySelectorAll('.verification-badge').forEach(badge => {
    if (!status || status === 'none') {
      badge.style.display = 'none';
      return;
    }
    const map = {
      verified: ['bg-success', '✓ Верифицирован'],
      pending:  ['bg-warning text-dark', '⏳ На проверке'],
      rejected: ['bg-danger', '✗ Отклонено'],
    };
    const [cls, label] = map[status] ?? map.pending;
    badge.className = `badge verification-badge ${cls}`;
    badge.textContent = label;
    badge.style.display = '';
  });
}

// ─── NOTIFICATIONS ────────────────────────────────────────────────────────────
async function initNotificationBell() {
  const bellBtn = document.getElementById('nav-bell');
  if (!bellBtn) return;
  bellBtn.addEventListener('click', loadNotifications);
  loadNotifications();
}

async function loadNotifications() {
  const list = document.getElementById('notifications-list');
  const dot  = document.getElementById('bell-dot');
  if (!list) return;
  try {
    const items = await apiFetch('/notifications.php');
    if (dot) dot.style.display = items.some(n => !n.is_read) ? '' : 'none';

    if (!items.length) {
      list.innerHTML = '<div class="text-muted small p-3 text-center">Уведомлений нет</div>';
      return;
    }
    list.innerHTML = items.map(n => `
      <div class="notification-item p-2 ${n.is_read ? '' : 'notification-unread'}" onclick="markNotificationRead(${n.id}, this)">
        <div class="small">${esc(n.message)}</div>
        <div class="text-muted" style="font-size:.75rem">${timeAgo(n.created_at)}</div>
      </div>
    `).join('');
  } catch (e) {
    list.innerHTML = `<div class="text-danger small p-3">${esc(e.message)}</div>`;
  }
}

async function markNotificationRead(id, el) {
  el.classList.remove('notification-unread');
  try {
    await apiPost('/notifications.php', { action: 'mark_read', id });
    const dot = document.getElementById('bell-dot');
    if (dot && !document.querySelectorAll('.notification-unread').length) dot.style.display = 'none';
  } catch { /* non-critical */ }
}

function statusLabel(s) {
  return { open: 'Открыт', in_progress: 'В работе', closed: 'Закрыт' }[s] ?? s;
}

function emptyState(msg) {
  return `<div class="text-center text-muted py-5">
    <div class="fs-1 mb-2">📋</div>
    <p>${msg}</p>
  </div>`;
}
