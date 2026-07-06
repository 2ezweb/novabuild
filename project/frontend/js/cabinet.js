let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  fillProfileForm();
});

function fillProfileForm() {
  const role = currentUser.role;

  document.getElementById('profile-fields-freelancer').style.display = role === 'freelancer' ? '' : 'none';
  document.getElementById('profile-fields-client').style.display = role === 'client' ? '' : 'none';
  document.getElementById('profile-fields-admin').style.display = role === 'admin' ? '' : 'none';
  document.getElementById('last-name-field').style.display = role === 'admin' ? 'none' : '';

  document.getElementById('profile-first-name').value = currentUser.first_name ?? '';
  document.getElementById('profile-last-name').value = currentUser.last_name ?? '';

  const lastNameHint = document.querySelector('#last-name-field .text-muted');
  if (lastNameHint) {
    lastNameHint.textContent = role === 'client'
      ? '(видна только администратору)'
      : '(другим видна как «Имя Ф.»)';
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
}

function renderVerificationAction() {
  const el = document.getElementById('verification-action');
  if (currentUser.role === 'admin') { el.innerHTML = ''; return; }

  const status = currentUser.verification_status;
  if (status === 'pending') {
    el.innerHTML = '<span class="text-muted small">⏳ Заявка на рассмотрении</span>';
  } else if (status === 'verified') {
    el.innerHTML = '';
  } else {
    el.innerHTML = `
      ${status === 'rejected' ? '<div class="text-danger small mb-1">Предыдущая заявка отклонена</div>' : ''}
      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#verificationModal">
        Подать заявку на верификацию
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
    const res = await fetch(API + '/avatar.php', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + localStorage.getItem('token') },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok) throw apiError(data);

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
  if (!file) return showError('verification-error', 'Выберите файл');

  const formData = new FormData();
  formData.append('document', file);

  try {
    const res = await fetch(API + '/verification.php', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + localStorage.getItem('token') },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok) throw apiError(data);

    bootstrap.Modal.getInstance(document.getElementById('verificationModal')).hide();
    currentUser = await apiFetch('/me.php');
    setVerificationBadge(currentUser.verification_status);
    renderVerificationAction();
  } catch (e) {
    showError('verification-error', e.message);
  }
}
