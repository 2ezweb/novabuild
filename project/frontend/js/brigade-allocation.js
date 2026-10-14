// ─────────────────────────────────────────────────────────────────────────────
// «Розподіл оплати» — how the бригадир splits a brigade contract/stage's pay
// among the members (api/brigades.php allocations / set_allocations). The rest
// goes to the бригадир. At payout (contract_payouts.php pay_freelancer) the
// escrow is split by these amounts, proportionally if not fully deposited.
//
// BrigadeAllocation.render(container, contractId, { onSaved }) — draws the
// editor into any element: the brigade page's modal or the contract dialog.
// ─────────────────────────────────────────────────────────────────────────────
const BrigadeAllocation = (() => {
  let seq = 0;

  async function render(container, contractId, opts = {}) {
    const uid = `ba${++seq}`;
    container.innerHTML = '<p class="text-muted small mb-0">Завантаження розподілу...</p>';
    let data;
    try {
      data = await apiFetch(`/brigades.php?action=allocations&contract_id=${contractId}`);
    } catch (e) {
      container.innerHTML = `<div class="alert alert-warning small mb-0">${esc(e.message)}</div>`;
      return;
    }

    const rows = data.members.map(m => `
      <tr>
        <td class="small">${esc(m.name)}${m.role ? ` <span class="text-muted">· ${esc(m.role)}</span>` : ''}</td>
        <td style="width:140px">
          <input type="number" min="0" step="0.01" class="form-control form-control-sm ${uid}-amount" data-user-id="${m.id}"
                 value="${m.amount ? esc(m.amount) : ''}" placeholder="0" ${data.editable ? '' : 'disabled'}>
        </td>
      </tr>`).join('');

    container.innerHTML = `
      <div class="small text-muted mb-2">Ціна: <b>${esc(formatUAH(data.price))}</b>. Вкажіть, скільки отримає кожен учасник — решта ваша. Якщо депозит внесено не повністю, усі частки зменшаться пропорційно.</div>
      ${data.members.length ? `
        <table class="table table-sm align-middle mb-2"><tbody>${rows}</tbody></table>
        <div class="d-flex justify-content-between small mb-2">
          <span>Учасникам: <b id="${uid}-allocated">₴ 0</b></span>
          <span>Вам (бригадиру): <b id="${uid}-owner">₴ 0</b></span>
        </div>
        <div id="${uid}-error" class="alert alert-danger small py-1 d-none"></div>
        ${data.editable ? `<button type="button" class="btn btn-sm btn-primary" id="${uid}-save">Зберегти розподіл</button>` : '<div class="small text-muted">Контракт закрито — розподіл зафіксовано.</div>'}`
        : '<p class="small text-muted mb-0">У бригаді поки немає учасників — уся оплата ваша. Запросіть людей на сторінці «Бригада».</p>'}`;

    if (!data.members.length) return;

    const inputs = () => Array.from(container.querySelectorAll(`.${uid}-amount`));
    const recalc = () => {
      const sum = inputs().reduce((s, i) => s + (Number(i.value) || 0), 0);
      document.getElementById(`${uid}-allocated`).textContent = formatUAH(sum);
      const owner = document.getElementById(`${uid}-owner`);
      owner.textContent = formatUAH(data.price - sum);
      owner.classList.toggle('text-danger', sum > data.price);
    };
    inputs().forEach(i => i.addEventListener('input', recalc));
    recalc();

    const saveBtn = document.getElementById(`${uid}-save`);
    if (!saveBtn) return;
    saveBtn.addEventListener('click', async () => {
      const errId = `${uid}-error`;
      hideError(errId);
      const allocations = inputs().map(i => ({ user_id: Number(i.dataset.userId), amount: Number(i.value) || 0 }));
      const sum = allocations.reduce((s, a) => s + a.amount, 0);
      if (allocations.some(a => a.amount < 0)) return showError(errId, 'Сума не може бути від\'ємною');
      if (sum > data.price + 0.001) return showError(errId, 'Сума розподілу більша за ціну контракту');
      saveBtn.disabled = true;
      try {
        await apiPost('/brigades.php', { action: 'set_allocations', contract_id: contractId, allocations });
        saveBtn.textContent = '✓ Збережено';
        setTimeout(() => { saveBtn.textContent = 'Зберегти розподіл'; saveBtn.disabled = false; }, 1500);
        if (opts.onSaved) opts.onSaved();
      } catch (e) {
        showError(errId, e.message);
        saveBtn.disabled = false;
      }
    });
  }

  return { render };
})();
