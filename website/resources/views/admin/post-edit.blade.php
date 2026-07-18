@extends('admin.layout')
@section('title', $post ? 'ویرایش مطلب' : 'مطلب جدید')
@section($type === 'kb' ? 'nav_kb' : 'nav_blog', 'on')

@php
    // پایگاه دانش از بخش‌های مستندات استفاده می‌کند، بلاگ از دسته‌بندی‌های بلاگ
    $cats = $type === 'kb' ? config('docs.sections') : config('blog.categories');
    $defaultCat = $type === 'kb' ? array_key_first($cats) : 'hosting';
    $covers = config('blog.covers');
    $tr = fn ($loc, $field, $default = '') => $post && ($t = $post->translations->firstWhere('locale', $loc)) ? ($field === 'tags' ? implode(', ', $t->tags ?? []) : $t->$field) : $default;
@endphp

@section('content')
<form method="post" action="{{ $post ? '/admin/posts/'.$post->id : '/admin/posts' }}" id="post-form">
  @csrf
  <input type="hidden" name="type" value="{{ $post->type ?? $type }}">
  <div class="ad-editor">

    {{-- MAIN --}}
    <div>
      <div class="ad-ed-main">
        <div class="ad-lang-tabs">
          <button type="button" class="ad-lang-tab on" data-lang="fa">🇮🇷 فارسی</button>
          <button type="button" class="ad-lang-tab" data-lang="en">🇬🇧 English</button>
          <button type="button" class="ad-lang-tab" data-lang="tr">🇹🇷 Türkçe</button>
        </div>

        @foreach(['fa' => 'فارسی', 'en' => 'English', 'tr' => 'Türkçe'] as $loc => $label)
        <div class="ad-lang-pane @if($loc==='fa') on @endif" data-pane="{{ $loc }}" dir="{{ $loc==='fa' ? 'rtl' : 'ltr' }}">
          @if($loc !== 'fa')
          <div class="ad-lang-actions">
            <button type="button" class="btn btn-ghost ai-translate" data-target="{{ $loc }}"><svg class="icon"><use href="#i-sparkles"/></svg>ترجمه‌ی خودکار از فارسی</button>
            <span class="ai-tr-status" data-for="{{ $loc }}"></span>
          </div>
          @endif
          <div class="ad-ed-field"><label>عنوان</label><input class="ad-input" name="{{ $loc }}_title" data-role="title" maxlength="200" value="{{ old($loc.'_title', $tr($loc,'title')) }}" @if($loc==='fa') required @endif></div>
          <div class="ad-ed-field"><label>خلاصه (متا)</label><textarea class="ad-input" name="{{ $loc }}_excerpt" data-role="excerpt" rows="2" maxlength="500">{{ old($loc.'_excerpt', $tr($loc,'excerpt')) }}</textarea></div>
          <div class="ad-ed-field">
            <label>محتوا</label>
            <div class="wysiwyg-tb" data-for="{{ $loc }}">
              <button type="button" data-cmd="bold" title="Bold">B</button>
              <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
              <button type="button" data-cmd="h2" title="Heading 2">H2</button>
              <button type="button" data-cmd="h3" title="Heading 3">H3</button>
              <button type="button" data-cmd="ul" title="List">••</button>
              <button type="button" data-cmd="ol" title="Numbered">1.</button>
              <button type="button" data-cmd="link" title="Link">🔗</button>
              <button type="button" data-cmd="quote" title="Quote">❝</button>
              <button type="button" data-cmd="p" title="Paragraph">¶</button>
            </div>
            <div class="wysiwyg" contenteditable="true" data-editor="{{ $loc }}">{!! old($loc.'_content', $tr($loc,'content')) !!}</div>
            <textarea name="{{ $loc }}_content" data-role="content" hidden>{{ old($loc.'_content', $tr($loc,'content')) }}</textarea>
            <input type="hidden" name="{{ $loc }}_auto" value="0" data-role="auto">
          </div>
          <div class="ad-ed-field"><label>برچسب‌ها (با کاما جدا کنید)</label><input class="ad-input" name="{{ $loc }}_tags" data-role="tags" dir="{{ $loc==='fa' ? 'rtl' : 'ltr' }}" maxlength="400" value="{{ old($loc.'_tags', $tr($loc,'tags')) }}"></div>
        </div>
        @endforeach
      </div>
    </div>

    {{-- SIDE --}}
    <div class="ad-ed-side">
      <div>
        <div class="sh"><svg class="icon"><use href="#i-settings"/></svg>تنظیمات</div>
        <div class="ad-ed-field"><label>اسلاگ (URL انگلیسی)</label><input class="ad-input" name="slug" dir="ltr" pattern="[a-z0-9\-]+" maxlength="120" required value="{{ old('slug', $post->slug ?? '') }}" placeholder="my-post-slug"></div>
        <div class="ad-mini">
          <div class="ad-ed-field"><label>دسته</label>
            <select class="ad-input" name="category">
              @foreach($cats as $k => $c)<option value="{{ $k }}" @selected(old('category', $post->category ?? $defaultCat)===$k)>{{ $c['fa']['t'] ?? $c['fa'] ?? $k }}</option>@endforeach
            </select>
          </div>
          <div class="ad-ed-field"><label>وضعیت</label>
            <select class="ad-input" name="status">
              <option value="draft" @selected(old('status', $post->status ?? 'draft')==='draft')>پیش‌نویس</option>
              <option value="published" @selected(old('status', $post->status ?? '')==='published')>منتشرشده</option>
            </select>
          </div>
        </div>
        <div class="ad-ed-field"><label>آیکن</label>
          <select class="ad-input" name="icon">
            @foreach(['book','server','cloud','shield','trend','cpu','coins','lock','globe','db','flow','rocket','key','zap'] as $ic)<option value="{{ $ic }}" @selected(old('icon', $post->icon ?? 'book')===$ic)>{{ $ic }}</option>@endforeach
          </select>
        </div>
        <div class="ad-ed-field"><label>جلد (رنگ)</label>
          <div class="ad-cover-grid">
            @foreach($covers as $k => $grad)
            <div class="ad-cover" data-cover="{{ $k }}" style="background:{{ $grad }}"></div>
            @endforeach
          </div>
          <input type="hidden" name="cover" id="cover-input" value="{{ old('cover', $post->cover ?? 'a') }}">
        </div>
      </div>

      <div>
        <div class="sh"><svg class="icon"><use href="#i-gauge"/></svg>تحلیل سئو (هوش مصنوعی)</div>
        <button type="button" class="btn btn-ghost" id="seo-btn" style="width:100%;justify-content:center"><svg class="icon"><use href="#i-sparkles"/></svg>بررسی سئوی متن فارسی</button>
        <div id="seo-result" style="margin-top:14px"></div>
      </div>
    </div>
  </div>

  <div class="ad-save-bar">
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);cursor:pointer"><input type="checkbox" id="auto-tr" checked> ترجمه‌ی خودکار زبان‌های خالی هنگام ذخیره</label>
    <a class="btn btn-ghost" href="/admin/posts?type={{ $post->type ?? $type }}">انصراف</a>
    <button class="btn btn-primary" type="submit" id="save-btn"><svg class="icon"><use href="#i-check"/></svg>ذخیره</button>
  </div>
</form>
@endsection

@section('scripts')
<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const form = document.getElementById('post-form');

  // language tabs
  document.querySelectorAll('.ad-lang-tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.ad-lang-tab').forEach(x => x.classList.toggle('on', x === t));
    document.querySelectorAll('.ad-lang-pane').forEach(p => p.classList.toggle('on', p.dataset.pane === t.dataset.lang));
  }));

  // wysiwyg toolbar
  document.querySelectorAll('.wysiwyg-tb').forEach(tb => {
    const loc = tb.dataset.for;
    const ed = document.querySelector('.wysiwyg[data-editor="'+loc+'"]');
    tb.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
      ed.focus();
      const c = b.dataset.cmd;
      if (c === 'bold') document.execCommand('bold');
      else if (c === 'italic') document.execCommand('italic');
      else if (c === 'h2') document.execCommand('formatBlock', false, 'h2');
      else if (c === 'h3') document.execCommand('formatBlock', false, 'h3');
      else if (c === 'p') document.execCommand('formatBlock', false, 'p');
      else if (c === 'quote') document.execCommand('formatBlock', false, 'blockquote');
      else if (c === 'ul') document.execCommand('insertUnorderedList');
      else if (c === 'ol') document.execCommand('insertOrderedList');
      else if (c === 'link') { const u = prompt('آدرس لینک:'); if (u) document.execCommand('createLink', false, u); }
    }));
  });

  // cover picker
  const coverInput = document.getElementById('cover-input');
  function paintCover(){ document.querySelectorAll('.ad-cover').forEach(c => c.classList.toggle('on', c.dataset.cover === coverInput.value)); }
  document.querySelectorAll('.ad-cover').forEach(c => c.addEventListener('click', () => { coverInput.value = c.dataset.cover; paintCover(); }));
  paintCover();

  // sync wysiwyg -> textarea
  function syncEditors(){ document.querySelectorAll('.wysiwyg').forEach(ed => { document.querySelector('textarea[name="'+ed.dataset.editor+'_content"]').value = ed.innerHTML.trim(); }); }

  function paneData(loc){
    const pane = document.querySelector('.ad-lang-pane[data-pane="'+loc+'"]');
    return {
      title: pane.querySelector('[data-role=title]').value,
      excerpt: pane.querySelector('[data-role=excerpt]').value,
      content: document.querySelector('.wysiwyg[data-editor="'+loc+'"]').innerHTML.trim(),
      tags: pane.querySelector('[data-role=tags]').value,
    };
  }
  function setPane(loc, d){
    const pane = document.querySelector('.ad-lang-pane[data-pane="'+loc+'"]');
    pane.querySelector('[data-role=title]').value = d.title || '';
    pane.querySelector('[data-role=excerpt]').value = d.excerpt || '';
    document.querySelector('.wysiwyg[data-editor="'+loc+'"]').innerHTML = d.content || '';
    pane.querySelector('[data-role=tags]').value = d.tags || '';
    pane.querySelector('[data-role=auto]').value = '1';
  }

  async function translate(loc, statusEl){
    const fa = paneData('fa');
    if (!fa.title || !fa.content) { alert('ابتدا عنوان و محتوای فارسی را کامل کنید.'); return false; }
    if (statusEl) statusEl.innerHTML = '<span class="ad-spin"></span>';
    try {
      const r = await fetch('/admin/ai/translate', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify({ target: loc, ...fa }) });
      const d = await r.json();
      if (d.ok) { setPane(loc, d); if (statusEl) statusEl.textContent = '✓ ترجمه شد'; return true; }
      if (statusEl) statusEl.textContent = d.error === 'not_configured' ? 'سرویس AI فعال نیست' : 'سرویس شلوغ است';
    } catch { if (statusEl) statusEl.textContent = 'خطا'; }
    return false;
  }

  document.querySelectorAll('.ai-translate').forEach(b => b.addEventListener('click', () => {
    translate(b.dataset.target, document.querySelector('.ai-tr-status[data-for="'+b.dataset.target+'"]'));
  }));

  // SEO analysis
  document.getElementById('seo-btn').addEventListener('click', async function(){
    const fa = paneData('fa');
    if (!fa.title || !fa.content) { alert('ابتدا عنوان و محتوای فارسی را کامل کنید.'); return; }
    const box = document.getElementById('seo-result');
    this.disabled = true; box.innerHTML = '<div style="text-align:center;padding:16px"><span class="ad-spin"></span></div>';
    try {
      const r = await fetch('/admin/ai/seo', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify(fa) });
      const d = await r.json();
      if (d.ok) {
        const color = d.score >= 80 ? 'var(--green)' : d.score >= 55 ? 'var(--amber)' : 'var(--red)';
        box.innerHTML = '<div class="seo-score"><b style="color:'+color+'">'+d.score+'</b><span>امتیاز سئو از ۱۰۰</span></div><ul class="seo-items">'+
          d.items.map(i => '<li class="'+i.type+'"><svg class="icon"><use href="#i-'+(i.type==='ok'?'check':i.type==='bad'?'x':'zap')+'"/></svg><span>'+i.text.replace(/</g,'&lt;')+'</span></li>').join('')+'</ul>';
      } else box.innerHTML = '<p class="ad-hint">'+(d.error==='not_configured'?'سرویس AI فعال نیست.':'تحلیل ناموفق بود؛ دوباره تلاش کنید.')+'</p>';
    } catch { box.innerHTML = '<p class="ad-hint">خطا در ارتباط.</p>'; }
    this.disabled = false;
  });

  // save: auto-translate empty langs then submit
  let submitting = false;
  form.addEventListener('submit', async function(e){
    if (submitting) return;
    e.preventDefault();
    syncEditors();
    const autoTr = document.getElementById('auto-tr').checked;
    const saveBtn = document.getElementById('save-btn');
    if (autoTr) {
      for (const loc of ['en','tr']) {
        const d = paneData(loc);
        if (!d.title.trim()) { saveBtn.innerHTML = '<span class="ad-spin"></span> ترجمه‌ی '+loc.toUpperCase()+'…'; await translate(loc, null); }
      }
      syncEditors();
    }
    submitting = true; saveBtn.innerHTML = '<span class="ad-spin"></span> ذخیره…';
    form.submit();
  });
})();
</script>
@endsection
