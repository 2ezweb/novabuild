let currentUser = null;
let selectedAmount = null;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'freelancer') {
    location.href = 'dashboard.html';
    return;
  }

  setupCabinetTabs(currentUser);
  document.getElementById('current-balance').textContent = currentUser.connects_balance ?? 0;
  document.querySelectorAll('.amount-btn').forEach(btn => btn.addEventListener('click', () => selectAmount(btn)));
  document.getElementById('custom-amount').addEventListener('input', updateAmountFromCustom);
});

// ─── STEP 1: AMOUNT ─────────────────────────────────────────────────────────
function selectAmount(btn) {
  document.querySelectorAll('.amount-btn').forEach(b => {
    b.classList.remove('btn-primary');
    b.classList.add('btn-outline-primary');
  });
  btn.classList.remove('btn-outline-primary');
  btn.classList.add('btn-primary');

  const customWrap = document.getElementById('custom-amount-wrap');
  if (btn.dataset.amount === 'custom') {
    customWrap.style.display = '';
    document.getElementById('custom-amount').focus();
    updateAmountFromCustom();
  } else {
    customWrap.style.display = 'none';
    selectedAmount = Number(btn.dataset.amount);
    updateAmountPreview();
  }
}

function updateAmountFromCustom() {
  selectedAmount = Number(document.getElementById('custom-amount').value) || null;
  updateAmountPreview();
}

function updateAmountPreview() {
  const el = document.getElementById('amount-preview');
  if (!selectedAmount) { el.style.display = 'none'; return; }
  el.style.display = '';
  el.textContent = `К зачислению: ${selectedAmount} коннектов (курс 1 ₴ = 1 коннект)`;
}

// ─── STEP 2: PAYMENT (formatCardNumber/formatCardExpiry live in common.js) ───
async function submitPurchase(method) {
  hideError('purchase-error');
  document.getElementById('purchase-success').classList.add('d-none');

  if (!selectedAmount || selectedAmount < 10) {
    return showError('purchase-error', 'Выберите сумму пополнения (минимум 10 ₴)');
  }

  if (method === 'card') {
    const number = document.getElementById('card-number').value.replace(/\s/g, '');
    const expiry = val('card-expiry');
    const cvv    = val('card-cvv');
    if (!/^\d{16}$/.test(number))    return showError('purchase-error', 'Введите номер карты (16 цифр)');
    if (!/^\d{2}\/\d{2}$/.test(expiry)) return showError('purchase-error', 'Формат срока действия — ММ/ГГ');
    if (!/^\d{3}$/.test(cvv))        return showError('purchase-error', 'CVV — 3 цифры');
  }

  try {
    const res = await apiPost('/connects.php', { action: 'purchase', amount: selectedAmount, method });
    currentUser.connects_balance = res.connects_balance;
    document.getElementById('current-balance').textContent = res.connects_balance;

    const ok = document.getElementById('purchase-success');
    ok.textContent = `Начислено ${res.connects_added} коннектов. Текущий баланс: ${res.connects_balance}.`;
    ok.classList.remove('d-none');

    if (method === 'card') {
      document.getElementById('card-number').value = '';
      document.getElementById('card-expiry').value = '';
      document.getElementById('card-cvv').value = '';
    }
  } catch (e) {
    showError('purchase-error', e.message);
  }
}
