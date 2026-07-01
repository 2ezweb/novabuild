// ─── CONFIG ──────────────────────────────────────────────────────────────────
// pages live in /frontend/pages/, api lives in /project/api
const API = '../../api';

// ─── DOM HELPERS ──────────────────────────────────────────────────────────────
function val(id)  { return document.getElementById(id)?.value?.trim() ?? ''; }
function esc(s)   { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

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
