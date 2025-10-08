/* public/js/license.js
 * هماهنگ با markup فعلی license.blade.php
 * - عنوان پویا را در #summaryTitle می‌نویسد (مثلاً: "CLTs-2 • 1 month")
 * - قیمت نهایی را در #totalPrice می‌نویسد
 * - ورودی تعداد: #quantity
 * - پلن‌ها: label.plan-card > input[type=radio][name=plan]
 * - دوره پرداخت: label.chip > input[type=radio][name=billing] با data-months
 */
(() => {
  const qs  = (s) => document.querySelector(s);
  const qsa = (s) => document.querySelectorAll(s);
  const euro = (n) => (Number(n).toFixed(2) + ' €');

  // عناصر صفحه
  const summaryTitle = qs('#summaryTitle');   // عنوان پویا
  const totalPriceEl = qs('#totalPrice');     // قیمت نهایی
  const qtyInput     = qs('#quantity');       // تعداد
  const planInputs   = qsa('.plan-card input[type="radio"][name="plan"]');
  const billingInputs= qsa('.chip input[type="radio"][name="billing"]');
  const planCodeEl   = qs('.qty__label');     // باکس کوچک کنار qty برای نمایش کُد پلن (مثلاً CLTs-2)

  // وضعیت
  const state = {
    planCode:  null,
    planName:  null,
    priceMo:   0,     // قیمت ماهانه €
    months:    1,     // 1 یا 12
    discount:  0,     // درصد تخفیف (0..100) - اختیاری
    qty:       1,
  };

  function readSelectedPlan() {
    const checked = qs('.plan-card input[type="radio"][name="plan"]:checked');
    if (!checked) return;
    const card = checked.closest('label.plan-card');
    const nameEl = card.querySelector('.plan-name');

    state.planCode = card?.dataset?.planCode || checked.value || '—';
    state.planName = nameEl ? nameEl.textContent.trim() : state.planCode;
    state.priceMo  = parseFloat(checked.dataset.priceMonth || '0');
  }

  function readSelectedBilling() {
    const checked = qs('.chip input[type="radio"][name="billing"]:checked');
    if (!checked) return;
    // از data-months استفاده می‌کنیم؛ اگر نبود، پیش‌فرض 1
    const months = parseInt(checked.dataset.months || '1', 10);
    state.months = isNaN(months) ? 1 : months;

    // اگر data-discount (درصد) بود، اعمال می‌کنیم؛ وگرنه صفر
    const disc = parseFloat(checked.dataset.discount || '0');
    state.discount = isNaN(disc) ? 0 : disc; // درصد
  }

  function readQty() {
    const val = parseInt((qtyInput?.value ?? '1'), 10);
    state.qty = Math.max(1, isNaN(val) ? 1 : val);
  }

  function updateTitle() {
    const periodText = (state.months === 12) ? '1 year' : `${state.months} month`;
    summaryTitle.textContent = `${state.planName} • ${periodText}`;
    if (planCodeEl) planCodeEl.textContent = state.planCode; // نشان دادن کُد پلن کنار qty
  }

  function updatePrice() {
    const base = state.priceMo * state.months * state.qty;
    const final = base * (1 - (state.discount / 100));
    totalPriceEl.textContent = euro(final);
  }

  function recompute() {
    readSelectedPlan();
    readSelectedBilling();
    readQty();
    updateTitle();
    updatePrice();
  }

  // رویدادها
  planInputs.forEach((input) => {
    input.addEventListener('change', recompute);
  });
  billingInputs.forEach((input) => {
    input.addEventListener('change', recompute);
  });
  if (qtyInput) {
    qtyInput.addEventListener('input', recompute);
    qtyInput.addEventListener('change', recompute);
  }

  // شروع
  recompute();
})();