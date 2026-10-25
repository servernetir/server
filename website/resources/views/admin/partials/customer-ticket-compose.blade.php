<details id="customer-ticket-compose" class="ad-panel" @if(old('_customer_ticket_form') == $c->id) open @endif>
  <summary class="ad-panel-h" style="cursor:pointer"><h3>ثبت تیکت برای این مشتری</h3></summary>
  <form method="post" action="/admin/tickets/create" class="cust-ticket-form"
        data-confirm="برای این مشتری تیکت ثبت و اعلان ارسال شود؟"
        data-confirm-title="ثبت تیکت پشتیبانی" data-confirm-ok="ثبت و اطلاع‌رسانی">
    @csrf
    <input type="hidden" name="_customer_ticket_form" value="{{ $c->id }}">
    <input type="hidden" name="customer_ids[]" value="{{ $c->id }}">
    <p style="margin:0;color:var(--muted)">گیرنده: <b>{{ $c->displayName() }}</b> · <bdi dir="ltr">{{ $c->code }}</bdi></p>
    @if(old('_customer_ticket_form') == $c->id && $errors->any())
      <div class="ad-note err" role="alert">{{ $errors->first() }}</div>
    @endif
    <label for="customer-ticket-subject">موضوع
      <input id="customer-ticket-subject" name="subject" class="ad-input" required maxlength="200"
             value="{{ old('_customer_ticket_form') == $c->id ? old('subject') : '' }}">
    </label>
    <div class="cust-ticket-options">
      <label for="customer-ticket-department">بخش
        <select id="customer-ticket-department" name="department" class="ad-input">
          <option value="technical">فنی</option>
          <option value="billing" @selected(old('department') === 'billing')>مالی</option>
          <option value="sales" @selected(old('department') === 'sales')>فروش</option>
        </select>
      </label>
      <label for="customer-ticket-priority">اولویت
        <select id="customer-ticket-priority" name="priority" class="ad-input">
          <option value="normal">عادی</option>
          <option value="low" @selected(old('priority') === 'low')>کم</option>
          <option value="high" @selected(old('priority') === 'high')>زیاد</option>
          <option value="urgent" @selected(old('priority') === 'urgent')>فوری</option>
        </select>
      </label>
    </div>
    <label for="customer-ticket-body">پیام پشتیبانی
      <textarea id="customer-ticket-body" name="body" class="ad-input" required maxlength="5000" rows="5"
                style="resize:vertical;line-height:1.9">{{ old('_customer_ticket_form') == $c->id ? old('body') : '' }}</textarea>
    </label>
    <div><button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-send"/></svg>ثبت تیکت و اطلاع‌رسانی</button></div>
  </form>
</details>
