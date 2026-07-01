let currentUser = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  fillProfileForm();
});

function fillProfileForm() {
  const isFreelancer = currentUser.role === 'freelancer';
  document.getElementById('profile-fields-freelancer').style.display = isFreelancer ? '' : 'none';
  document.getElementById('profile-fields-client').style.display = isFreelancer ? 'none' : '';

  if (isFreelancer) {
    document.getElementById('profile-full-name').value      = currentUser.full_name ?? '';
    document.getElementById('profile-phone-fl').value       = currentUser.phone ?? '';
    document.getElementById('profile-specialization').value = currentUser.specialization ?? '';
    document.getElementById('profile-about').value           = currentUser.about ?? '';
    setVerificationBadge(currentUser.verification_status);
  } else {
    document.getElementById('profile-company-name').value = currentUser.company_name ?? '';
    document.getElementById('profile-contact-name').value = currentUser.contact_name ?? '';
    document.getElementById('profile-phone-cl').value     = currentUser.phone ?? '';
  }
}

async function submitProfile() {
  hideError('profile-error');
  document.getElementById('profile-success').classList.add('d-none');
  const isFreelancer = currentUser.role === 'freelancer';
  const payload = isFreelancer
    ? {
        full_name:      val('profile-full-name'),
        phone:          val('profile-phone-fl'),
        specialization: val('profile-specialization'),
        about:          val('profile-about'),
      }
    : {
        company_name: val('profile-company-name'),
        contact_name: val('profile-contact-name'),
        phone:        val('profile-phone-cl'),
      };

  try {
    await apiPost('/profile.php', payload);
    currentUser = await apiFetch('/me.php');
    document.getElementById('profile-success').classList.remove('d-none');
  } catch (e) {
    showError('profile-error', e.message);
  }
}
