(() => {
  const qs = s => document.querySelector(s);
  const qsa = s => document.querySelectorAll(s);

  // state
  let plan = { id:'medium', name:'Medium', price:5 };
  let months = 3;
  let discount = 0.05; // 3 months default
  let qty = 1;

  // els
  const totalTitle = qs('#totalTitle');
  const oldPrice   = qs('#oldPrice');
  const newPrice   = qs('#newPrice');
  const qtyInput   = qs('#qty');

  const euro = n => n.toFixed(2) + ' €';

  function updateTitle() {
    const periodText = months === 12 ? 'Year' : `${months} months`;
    totalTitle.textContent = `${plan.name} • ${periodText}`;
  }

  function updatePrices() {
    const base = plan.price * months * qty;
    const discounted = base * (1 - (discount || 0));
    oldPrice.textContent = discount ? euro(base) : '';
    newPrice.textContent = euro(discounted);
  }

  function redraw() { updateTitle(); updatePrices(); }

  // choose plan
  qsa('.choose-plan').forEach(btn => {
    btn.addEventListener('click', () => {
      qsa('.choose-plan').forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      btn.textContent = 'Selected';
      qsa('.choose-plan').forEach(b => { if (b !== btn) b.textContent = 'Choose'; });

      plan = {
        id: btn.dataset.id,
        name: btn.dataset.name,
        price: parseFloat(btn.dataset.price)
      };
      redraw();
    });
  });

  // choose period
  qsa('.period').forEach(tab => {
    tab.addEventListener('click', () => {
      qsa('.period').forEach(t => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      months = parseInt(tab.dataset.months, 10);
      discount = parseFloat(tab.dataset.discount || 0);
      redraw();
    });
  });

  // qty
  qtyInput.addEventListener('input', () => {
    qty = Math.max(1, parseInt(qtyInput.value || '1', 10));
    qs('.qty__label').textContent = qty;
    redraw();
  });

  // first paint
  redraw();
})();