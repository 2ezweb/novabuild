// If already logged in, skip straight to the dashboard.
window.addEventListener('DOMContentLoaded', () => {
  if (localStorage.getItem('token')) location.href = 'dashboard.html';
});

async function doLogin() {
  const email    = val('login-email');
  const password = val('login-password');
  hideError('login-error');
  try {
    const res = await apiPost('/auth.php', { action: 'login', email, password });
    localStorage.setItem('token', res.token);
    location.href = res.role === 'admin' ? 'admin.html' : 'dashboard.html';
  } catch (e) {
    if (e.pending_verification) {
      localStorage.setItem('pending_email', e.email);
      location.href = 'verify.html';
      return;
    }
    showError('login-error', e.message);
  }
}

async function doRegister() {
  const email    = val('reg-email');
  const password = val('reg-password');
  const role     = val('reg-role');
  hideError('reg-error');
  if (!role) return showError('reg-error', 'Выберите роль');
  try {
    await apiPost('/auth.php', { action: 'register', email, password, role });
    localStorage.setItem('pending_email', email);
    location.href = 'verify.html';
  } catch (e) {
    showError('reg-error', e.message);
  }
}

function selectRole(role) {
  document.querySelectorAll('.role-card').forEach(c => c.classList.remove('role-selected'));
  document.querySelector(`[data-role="${role}"]`).classList.add('role-selected');
  document.getElementById('reg-role').value = role;
}
