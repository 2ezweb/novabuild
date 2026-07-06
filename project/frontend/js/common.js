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

// avatar_path is stored relative to /project/; pages live in /project/frontend/pages/
function avatarUrl(path) { return path ? '../../' + path : null; }

// Sets a circular avatar element to either the uploaded photo or an initial-on-background fallback.
function renderAvatarEl(el, name, avatarPath) {
  if (!el) return;
  const url = avatarUrl(avatarPath);
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
