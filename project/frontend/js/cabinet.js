let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  fillProfileForm();
});

function fillProfileForm() {
  const role = currentUser.role;

  setupCabinetTabs(currentUser);
  document.getElementById('profile-fields-freelancer').style.display = role === 'freelancer' ? '' : 'none';
  document.getElementById('profile-fields-client').style.display = role === 'client' ? '' : 'none';
  document.getElementById('profile-fields-admin').style.display = role === 'admin' ? '' : 'none';
  document.getElementById('last-name-field').style.display = role === 'admin' ? 'none' : '';

  document.getElementById('profile-first-name').value = currentUser.first_name ?? '';
  document.getElementById('profile-last-name').value = currentUser.last_name ?? '';

  const lastNameHint = document.querySelector('#last-name-field .text-muted');
  if (lastNameHint) {
    lastNameHint.textContent = role === 'client'
      ? '(бачить лише адміністратор)'
      : '(іншим видно як «Ім\'я П.»)';
  }

  if (role === 'freelancer') {
    document.getElementById('profile-phone-fl').value = currentUser.phone ?? '';
    document.getElementById('profile-website').value = currentUser.website ?? '';
    document.getElementById('profile-specialization').value = currentUser.specialization ?? '';
    document.getElementById('profile-about').value = currentUser.about ?? '';
    document.getElementById('about-count').textContent = (currentUser.about ?? '').length;
  } else if (role === 'client') {
    document.getElementById('profile-company-name').value = currentUser.company_name ?? '';
    document.getElementById('profile-phone-cl').value = currentUser.phone ?? '';
    document.getElementById('profile-email-display').value = currentUser.email ?? '';
  } else if (role === 'admin') {
    document.getElementById('profile-login').value = currentUser.email ?? '';
  }

  renderAvatarEl(document.getElementById('profile-avatar'), displayName(currentUser), currentUser.avatar_path);
  setVerificationBadge(currentUser.verification_status);
  renderVerificationAction();
  renderBalanceBlock();
}

// ─── BALANCE (client + freelancer) ───────────────────────────────────────────
function renderBalanceBlock() {
  const role = currentUser.role;
  const block = document.getElementById('balance-block');
  if (role !== 'client' && role !== 'freelancer') { block.style.display = 'none'; return; }

  block.style.display = '';

  const hint = document.getElementById('balance-hint');
  const action = document.getElementById('withdrawal-action');

  if (role === 'client') {
    const frozen = currentUser.frozen_amount || 0;
    document.getElementById('balance-amount').textContent = formatUAH(currentUser.balance);
    hint.textContent = (frozen > 0
      ? `З них ${formatUAH(frozen)} заморожено (повернення за невиконаним контрактом, 7 днів на спір) — поки недоступно. `
      : 'Поповнюється автоматично, якщо контракт закрито як невиконаний — депозит повертається сюди. ')
      + 'Під час оплати наступного контракту спершу списується доступний баланс, решта — карткою.';
    action.innerHTML = `<div class="alert alert-info small py-2 mb-0" style="max-width:360px">ℹ️ ${esc(CLIENT_WITHDRAW_NOTICE)}</div>`;
  } else {
    document.getElementById('balance-amount').textContent = formatUAH(currentUser.balance);
    hint.textContent = 'Зараховується автоматично, коли клієнт закриває контракт як виконаний.';
    loadWithdrawalStatus();
  }
}

async function loadWithdrawalStatus() {
  const action = document.getElementById('withdrawal-action');
  try {
    const res = await apiFetch('/wallet.php');
    currentUser.balance = res.balance;
    document.getElementById('balance-amount').textContent = formatUAH(res.balance);

    const pending = res.requests.find(r => r.status === 'pending');
    action.innerHTML = pending
      ? `<span class="text-muted small">⏳ Заявка на виведення ${formatUAH(pending.amount)} в обробці</span>`
      : `<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#withdrawalModal" onclick="openWithdrawalModal()">Вивести</button>`;
  } catch {
    action.innerHTML = '';
  }
}

function openWithdrawalModal() {
  hideError('withdrawal-error');
  document.getElementById('withdrawal-available').textContent = formatUAH(currentUser.balance);
  document.getElementById('withdrawal-amount').value = '';
}

async function submitWithdrawal() {
  hideError('withdrawal-error');
  const amount = Number(val('withdrawal-amount'));
  if (!amount || amount <= 0) return showError('withdrawal-error', 'Вкажіть суму');
  if (amount > (currentUser.balance ?? 0)) return showError('withdrawal-error', 'Недостатньо коштів на балансі');

  try {
    await apiPost('/wallet.php', { action: 'request_withdrawal', amount });
    bootstrap.Modal.getInstance(document.getElementById('withdrawalModal')).hide();
    loadWithdrawalStatus();
  } catch (e) {
    showError('withdrawal-error', e.message);
  }
}

function renderVerificationAction() {
  const el = document.getElementById('verification-action');
  if (currentUser.role === 'admin') { el.innerHTML = ''; return; }

  const status = currentUser.verification_status;
  if (status === 'pending') {
    el.innerHTML = '<span class="text-muted small">⏳ Заявка на розгляді</span>';
  } else if (status === 'verified') {
    el.innerHTML = '';
  } else {
    el.innerHTML = `
      ${status === 'rejected' ? '<div class="text-danger small mb-1">Попередню заявку відхилено</div>' : ''}
      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#verificationModal">
        Подати заявку на верифікацію
      </button>`;
  }
}

async function submitProfile() {
  hideError('profile-error');
  document.getElementById('profile-success').classList.add('d-none');

  const role = currentUser.role;
  const payload = {
    first_name: val('profile-first-name'),
    last_name:  val('profile-last-name'),
  };

  if (role === 'freelancer') {
    payload.phone          = val('profile-phone-fl');
    payload.website        = val('profile-website');
    payload.specialization = val('profile-specialization');
    payload.about          = val('profile-about');
  } else if (role === 'client') {
    payload.company_name = val('profile-company-name');
    payload.phone         = val('profile-phone-cl');
  } else if (role === 'admin') {
    payload.login = val('profile-login');
  }

  try {
    await apiPost('/profile.php', payload);
    currentUser = await apiFetch('/me.php');
    fillProfileForm();
    document.getElementById('profile-success').classList.remove('d-none');
  } catch (e) {
    showError('profile-error', e.message);
  }
}

// ─── AVATAR ───────────────────────────────────────────────────────────────────
async function uploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append('avatar', file);

  try {
    const data = await apiUpload('/avatar.php', formData);
    currentUser.avatar_path = data.avatar_path;
    renderAvatarEl(document.getElementById('profile-avatar'), displayName(currentUser), currentUser.avatar_path);
    renderAvatarEl(document.getElementById('nav-avatar'), displayName(currentUser), currentUser.avatar_path);
  } catch (e) {
    showError('profile-error', e.message);
  } finally {
    input.value = '';
  }
}

// ─── VERIFICATION ─────────────────────────────────────────────────────────────
async function submitVerification() {
  hideError('verification-error');
  const file = document.getElementById('verification-file').files[0];
  if (!file) return showError('verification-error', 'Оберіть файл');

  const formData = new FormData();
  formData.append('document', file);

  try {
    await apiUpload('/verification.php', formData);
    bootstrap.Modal.getInstance(document.getElementById('verificationModal')).hide();
    currentUser = await apiFetch('/me.php');
    setVerificationBadge(currentUser.verification_status);
    renderVerificationAction();
  } catch (e) {
    showError('verification-error', e.message);
  }
}
