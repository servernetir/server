(() => {
  const tlds = [
    'ru','su','com','net','org','host','pro','city','report','care','recipes',
    'wtf','glass','guitars','graphics','christmas','services','club','cleaning'
  ];

  const qs = s => document.querySelector(s);

  const rows   = qs('#rows');
  const more   = qs('#moreZones');
  const input  = qs('#q');
  const btn    = qs('#checkBtn');

  // فرم و خلاصه
  const regForm   = qs('#regForm');
  const total     = qs('#total');

  const fDomain   = qs('#domain');
  const fTld      = qs('#tld');
  const fPrice    = qs('#price');
  const periodInp = qs('#period');

  const summaryTitle = qs('#summaryTitle');
  const totalPrice   = qs('#totalPrice');

  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  const euro = (n) => Number(n).toFixed(2) + ' €';

  function updateSummaryTitle(domain) {
    const years = Number(periodInp?.value || 1);
    const periodText = years === 1 ? '1 year' : `${years} years`;
    summaryTitle.textContent = `${domain} • ${periodText}`;
  }

  function renderUnknown() {
    rows.innerHTML = tlds.map(t => `
      <tr>
        <td>.${t}</td>
        <td><span class="muted">Unknown</span></td>
        <td><span class="muted">—</span></td>
      </tr>
    `).join('');
    more.hidden = false;
  }

  async function checkDomains() {
    const name = (input.value || '').trim().toLowerCase();
    if (!name) { renderUnknown(); return; }

    try {
      rows.innerHTML = `<tr><td colspan="3" class="muted">Checking...</td></tr>`;
      more.hidden = true;

      const res = await fetch(`{{ route('domain.check') }}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ name, tlds })
      });

      const json = await res.json();
      if (!json.ok) throw new Error();

      rows.innerHTML = json.items.map(item => {
        const statusHtml = item.status === 'available'
          ? `<span class="muted" style="color:#10b981;">Available</span>`
          : `<span class="muted" style="color:#ff9800;">Busy</span>`;

        const priceHtml = item.status === 'available'
          ? `<a href="#" class="link price" data-domain="${item.domain}" data-tld="${item.tld}" data-price="${item.price}">${euro(item.price)}</a>`
          : `<span class="muted">${euro(item.price)}</span>`;

        return `
          <tr>
            <td>${item.domain}</td>
            <td>${statusHtml}</td>
            <td>${priceHtml}</td>
          </tr>
        `;
      }).join('');
    } catch (err) {
      renderUnknown();
      alert('Could not check availability. Please try again.');
    }
  }

  rows.addEventListener('click', (e) => {
    const a = e.target.closest('.price');
    if (!a) return;
    e.preventDefault();

    const domain = a.dataset.domain;
    const tld    = a.dataset.tld;
    const price  = a.dataset.price;

    fDomain.value = domain;
    fTld.value    = tld;
    fPrice.value  = price;

    updateSummaryTitle(domain);
    totalPrice.textContent = euro(price);

    regForm.hidden = false;
    total.hidden   = false;
    regForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  regForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(regForm);

    for (const key of [
      'full_name','dob','passport_series','passport_issuer','issue_date',
      'postcode','region','country','city','address','phone'
    ]) {
      if (!formData.get(key)) { alert('Please fill all required fields.'); return; }
    }

    try {
      const res = await fetch(`{{ route('domain.order') }}`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: formData
      });
      const json = await res.json();
      if (json.ok) {
        window.location.hash = '#payment';
        alert('Order created. Continue to payment.');
      } else {
        alert(json.message || 'Order failed');
      }
    } catch (err) {
      alert('Network error while creating order.');
    }
  });

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      btn.click();
    }
  });

  btn.addEventListener('click', checkDomains);

  renderUnknown();
})();