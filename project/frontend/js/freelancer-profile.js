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

    document.getElementById('fp-rating').innerHTML =
      `${ratingBadgeHtml(f.rating_avg, f.rating_count)} <span class="text-muted small">· выполнено контрактов: ${Number(f.completed_contracts) || 0}</span>`;
    document.getElementById('fp-reviews').innerHTML = (f.reviews || []).length
      ? f.reviews.map(r => `
          <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between small">
              <span>${starsHtml(r.rating)} <span class="ms-1">${esc(r.client_first_name || 'Клиент')}</span></span>
              <span class="text-muted">${esc(timeAgo(r.created_at))}</span>
            </div>
            ${r.comment ? `<div class="small mt-1" style="white-space:pre-wrap">${esc(r.comment)}</div>` : ''}
          </div>`).join('')
      : '<p class="text-muted small">Отзывов пока нет.</p>';

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
