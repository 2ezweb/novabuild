let currentUser = null;
let freelancerId = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client') {
    location.href = 'dashboard.html';
    return;
  }

  freelancerId = Number(new URLSearchParams(location.search).get('user_id'));
  if (!freelancerId) {
    location.href = 'dashboard.html';
    return;
  }

  loadProfile();
});

async function loadProfile() {
  try {
    const f = await apiFetch(`/freelancer_profile.php?user_id=${freelancerId}`);
    const name = [f.first_name, f.last_name_initial].filter(Boolean).join(' ') || 'Фрилансер';

    renderAvatarEl(document.getElementById('fp-avatar'), name, f.avatar_path);
    document.getElementById('fp-name').innerHTML = `${esc(name)} ${roleBadgeHtml(f)}`;
    document.getElementById('fp-specialization').textContent = f.specialization || 'Специализация не указана';
    document.getElementById('fp-about').textContent = f.about || 'Фрилансер пока не заполнил описание профиля.';

    document.getElementById('profile-content').classList.remove('d-none');
  } catch (e) {
    showError('profile-error', e.message);
  }
}

async function startConversation() {
  const btn = document.getElementById('fp-message-btn');
  btn.disabled = true;
  try {
    const res = await apiPost('/conversations.php', { action: 'start', other_user_id: freelancerId });
    location.href = `chat.html?conversation_id=${res.id}`;
  } catch (e) {
    showError('profile-error', e.message);
    btn.disabled = false;
  }
}
