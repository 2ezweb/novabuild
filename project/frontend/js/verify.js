let pendingEmail = null;

window.addEventListener('DOMContentLoaded', () => {
  pendingEmail = localStorage.getItem('pending_email');
  if (!pendingEmail) {
    location.href = 'login.html';
    return;
  }
  document.getElementById('verify-email-label').textContent = pendingEmail;
});

async function doVerify() {
  const code = val('verify-code');
  hideError('verify-error');
  if (!code) return showError('verify-error', 'Введите код');

  try {
    const res = await apiPost('/auth.php', { action: 'verify_email', email: pendingEmail, code });
    localStorage.setItem('token', res.token);
    localStorage.removeItem('pending_email');
    location.href = 'dashboard.html';
  } catch (e) {
    showError('verify-error', e.message);
  }
}

async function doResend() {
  hideError('verify-error');
  document.getElementById('verify-success').classList.add('d-none');
  try {
    await apiPost('/auth.php', { action: 'resend_code', email: pendingEmail });
    const ok = document.getElementById('verify-success');
    ok.textContent = 'Новый код отправлен';
    ok.classList.remove('d-none');
  } catch (e) {
    showError('verify-error', e.message);
  }
}
