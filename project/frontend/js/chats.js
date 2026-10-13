let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client' && currentUser.role !== 'freelancer') {
    location.href = 'dashboard.html';
    return;
  }
  loadChats();
});

async function loadChats() {
  const list = document.getElementById('chats-list');
  try {
    const conversations = await apiFetch('/conversations.php');
    list.innerHTML = conversations.length
      ? conversations.map(chatRow).join('')
      : emptyState('Переписок поки немає.');
  } catch (e) {
    list.innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`;
  }
}

function chatRow(c) {
  const name = [c.first_name, c.last_name].filter(Boolean).join(' ') || 'Співрозмовник';
  const avatarUrlValue = assetUrl(c.avatar_path);
  const avatarStyle = avatarUrlValue
    ? `background-image:url('${avatarUrlValue}');background-size:cover;background-position:center`
    : '';
  const preview = c.last_message ? truncateText(c.last_message, 80) : 'Немає повідомлень';
  const unread = c.unread_count > 0;

  return `
    <div class="card offer-card mb-2 p-3" style="cursor:pointer" onclick="location.href='chat.html?conversation_id=${c.id}'">
      <div class="d-flex align-items-center gap-3">
        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-semibold flex-shrink-0" style="width:44px;height:44px;font-size:1rem;${avatarStyle}">${avatarUrlValue ? '' : esc(initials(name))}</div>
        <div class="flex-grow-1" style="min-width:0">
          <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold">${esc(name)}</span>
            ${c.last_message_at ? `<span class="text-muted small">${timeAgo(c.last_message_at)}</span>` : ''}
          </div>
          <div class="text-muted small text-truncate">${esc(preview)}</div>
        </div>
        ${unread ? `<span class="badge bg-primary rounded-pill flex-shrink-0">${c.unread_count}</span>` : ''}
      </div>
    </div>`;
}
