// ─── CONFIG ──────────────────────────────────────────────────────────────────
// pages live in /frontend/pages/, api lives in /project/api
const API = '../../api';

// ─── DOM HELPERS ──────────────────────────────────────────────────────────────
function val(id)  { return document.getElementById(id)?.value?.trim() ?? ''; }
function esc(s)   { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function initials(name) { return String(name || '?').trim().charAt(0).toUpperCase(); }

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
async function apiFetch(path) {
  const res = await fetch(API + path, {
    headers: { Authorization: 'Bearer ' + localStorage.getItem('token') }
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Server error');
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
  if (!res.ok) throw new Error(data.error || 'Server error');
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
    const avatar = document.getElementById('nav-avatar');
    if (avatar) avatar.textContent = initials(me.full_name || me.company_name || me.email);
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
  const badge = document.getElementById('verification-badge');
  if (!badge) return;
  const map = {
    verified: ['bg-success', '✓ Верифицирован'],
    pending:  ['bg-warning text-dark', '⏳ На проверке'],
    rejected: ['bg-danger', '✗ Отклонено'],
  };
  const [cls, label] = map[status] ?? map.pending;
  badge.className = `badge ${cls}`;
  badge.textContent = label;
  badge.style.display = '';
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
