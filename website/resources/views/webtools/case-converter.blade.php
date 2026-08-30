<div class="wt-pane">
  <label>{{ __('ui.wt_input') }}</label>
  <textarea id="c-in" class="wt-ta" rows="4" spellcheck="false" placeholder="hello world example"></textarea>
</div>
<div class="wt-out-box" id="c-out" style="margin-top:16px"></div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const COPY = @json(__('ui.wt_copy'));
  const DONE = @json(__('ui.wt_copied'));

  /* واژه‌ها را از هر شکلی جدا می‌کند: فاصله، خط تیره، زیرخط و مرز camelCase */
  function words(s) {
    return s
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
      .replace(/[_\-.]+/g, ' ')
      .split(/\s+/)
      .filter(Boolean);
  }

  const cap = w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();

  const FORMS = [
    ['camelCase',    w => w.map((x, i) => (i ? cap(x) : x.toLowerCase())).join('')],
    ['PascalCase',   w => w.map(cap).join('')],
    ['snake_case',   w => w.map(x => x.toLowerCase()).join('_')],
    ['SCREAMING_SNAKE', w => w.map(x => x.toUpperCase()).join('_')],
    ['kebab-case',   w => w.map(x => x.toLowerCase()).join('-')],
    ['Title Case',   w => w.map(cap).join(' ')],
    ['Sentence case', w => { const j = w.join(' ').toLowerCase(); return j ? cap(j) : ''; }],
    ['lowercase',    w => w.join(' ').toLowerCase()],
    ['UPPERCASE',    w => w.join(' ').toUpperCase()],
  ];

  function run() {
    const w = words($('c-in').value.trim());
    if (!w.length) { $('c-out').innerHTML = ''; return; }

    $('c-out').innerHTML = FORMS.map(([name, fn]) => {
      const v = fn(w);
      return '<div class="wt-out-row"><span>' + name + '</span>'
           + '<b dir="ltr">' + v.replace(/</g, '&lt;') + '</b>'
           + '<button class="wt-mini" data-v="' + v.replace(/"/g, '&quot;') + '" data-done="' + DONE + '">' + COPY + '</button></div>';
    }).join('');

    $('c-out').querySelectorAll('.wt-mini').forEach(b =>
      b.onclick = () => wtCopy(b, b.dataset.v));
  }

  $('c-in').addEventListener('input', run);
})();
</script>
