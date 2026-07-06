let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'admin') {
    location.href = 'dashboard.html';
    return;
  }

  document.querySelectorAll('.sidebar-avatar').forEach(el => renderAvatarEl(el, displayName(currentUser), currentUser.avatar_path));
  document.querySelectorAll('.sidebar-name').forEach(el => el.innerHTML = `${esc(displayName(currentUser))} ${roleBadgeHtml(currentUser)}`);

  loadUsers();
  loadRequests();
});

function showSection(section) {
  document.getElementById('admin-section-users').style.display = section === 'users' ? '' : 'none';
  document.getElementById('admin-section-requests').style.display = section === 'requests' ? '' : 'none';
  document.getElementById('nav-users-btn').classList.toggle('active', section === 'users');
  document.getElementById('nav-requests-btn').classList.toggle('active', section === 'requests');
}

// ─── USERS ────────────────────────────────────────────────────────────────────
async function loadUsers() {
  const list = document.getElementById('admin-users-list');
  try {
    const users = await apiFetch('/admin.php?action=users');
    if (!users.length) {
      list.innerHTML = emptyState('Пользователей пока нет.');
      return;
    }
    list.innerHTML = `
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Имя</th><th>Email / логин</th><th>Роль</th><th>Верификация</th><th>Статус</th><th>Регистрация</th>
            </tr>
          </thead>
          <tbody>
            ${users.map(userRow).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function userRow(u) {
  const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || '—';
  const roleLabel = { client: 'Клиент', freelancer: 'Фрилансер', admin: 'Админ' }[u.role] ?? u.role;
  const verMap = {
    verified: '<span class="badge bg-success">✓ Верифицирован</span>',
    pending:  '<span class="badge bg-warning text-dark">⏳ На проверке</span>',
    rejected: '<span class="badge bg-danger">✗ Отклонено</span>',
    none:     '<span class="text-muted small">—</span>',
  };
  return `
    <tr>
      <td>${esc(name)}</td>
      <td>${esc(u.email)}</td>
      <td>${esc(roleLabel)}</td>
      <td>${verMap[u.verification_status] ?? ''}</td>
      <td>${u.status === 'banned' ? '<span class="badge bg-danger">banned</span>' : '<span class="badge bg-light text-dark border">active</span>'}</td>
      <td class="text-muted small">${esc(u.created_at)}</td>
    </tr>`;
}

// ─── VERIFICATION REQUESTS ────────────────────────────────────────────────────
async function loadRequests() {
  const list = document.getElementById('admin-requests-list');
  try {
    const requests = await apiFetch('/admin.php?action=verification_requests');
    const badge = document.getElementById('requests-count');
    if (requests.length) {
      badge.textContent = requests.length;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }

    if (!requests.length) {
      list.innerHTML = emptyState('Заявок на рассмотрении нет.');
      return;
    }
    list.innerHTML = requests.map(requestCard).join('');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function requestCard(u) {
  const name = [u.first_name, u.last_name].filter(Boolean).join(' ') || u.email;
  const roleLabel = { client: 'Клиент', freelancer: 'Фрилансер' }[u.role] ?? u.role;
  return `
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="fw-semibold mb-1">${esc(name)} <span class="text-muted small">(${esc(roleLabel)})</span></h6>
          <p class="text-muted small mb-0">${esc(u.email)}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" onclick="viewDocument(${u.id})">Открыть документ</button>
          <button class="btn btn-sm btn-success" onclick="decideRequest(${u.id}, 'approve')">Одобрить</button>
          <button class="btn btn-sm btn-outline-danger" onclick="decideRequest(${u.id}, 'reject')">Отклонить</button>
        </div>
      </div>
    </div>`;
}

async function decideRequest(userId, action) {
  try {
    await apiPost('/admin.php', { action, user_id: userId });
    loadRequests();
    loadUsers();
  } catch (e) {
    alert(e.message);
  }
}

async function viewDocument(userId) {
  const viewer = document.getElementById('document-viewer');
  viewer.innerHTML = '<p class="text-muted">Загрузка...</p>';
  new bootstrap.Modal(document.getElementById('documentModal')).show();

  try {
    const res = await fetch(API + `/admin.php?action=document&user_id=${userId}`, {
      headers: { Authorization: 'Bearer ' + localStorage.getItem('token') }
    });
    if (!res.ok) throw new Error('Не удалось загрузить документ');

    const contentType = res.headers.get('Content-Type') || '';
    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);

    viewer.innerHTML = contentType.includes('pdf')
      ? `<iframe src="${url}" style="width:100%;height:70vh;border:0"></iframe>`
      : `<img src="${url}" style="max-width:100%;max-height:70vh">`;
  } catch (e) {
    viewer.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}
