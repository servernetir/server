{{-- استایل مشترک فرم و رشتهٔ تیکت — یک بار، تا در سه ویو تکرار نشود --}}
@once
<style>
.tk-form{ display:flex; flex-direction:column; gap:16px; }
.tk-two{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:520px){ .tk-two{ grid-template-columns:1fr } }

.tk-field label{ display:block; font-size:12.5px; font-weight:600; margin-bottom:8px; }
.tk-field input, .tk-field select, .tk-field textarea{
  width:100%; box-sizing:border-box;
  background:var(--surface-2); border:1px solid var(--line); border-radius:12px;
  padding:12px 14px; font:inherit; font-size:14px; color:var(--text);
  transition:border-color .18s var(--ease), box-shadow .18s var(--ease);
}
.tk-field textarea{ resize:vertical; line-height:1.9; min-height:110px; }
.tk-field input:focus, .tk-field select:focus, .tk-field textarea:focus{
  outline:none; border-color:var(--line-2); box-shadow:0 0 0 3px rgba(34,211,238,.14);
}
.tk-field small{ display:block; margin-top:7px; font-size:11.5px; }

/* رشتهٔ گفتگو: پیام مشتری در سمت شروع، پشتیبانی در سمت پایان — مثل چت،
   ولی با ویژگی منطقی تا در en/tr برعکس شود */
.tk-thread{ display:flex; flex-direction:column; gap:12px; margin-bottom:var(--pnl-gap); }
.tk-msg{
  max-width:82%; border:1px solid var(--line); border-radius:16px;
  padding:13px 16px; background:var(--surface);
}
.tk-msg.me{ align-self:flex-start; border-start-start-radius:4px; }
.tk-msg.staff{
  align-self:flex-end; border-start-end-radius:4px;
  background:var(--info-bg); border-color:var(--info-line);
}
.tk-msg-h{ display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:7px; }
.tk-msg-who{ font-size:12.5px; font-weight:700; }
.tk-msg.staff .tk-msg-who{ color:var(--info); }
.tk-msg-t{ font-size:11px; color:var(--dim); font-variant-numeric:tabular-nums; }
.tk-msg-b{ font-size:13.5px; line-height:2; color:var(--text); word-break:break-word; }

/* یادداشت داخلی (فقط پنل مدیریت) */
.tk-msg.internal{ background:var(--warn-bg); border-color:var(--warn-line); align-self:stretch; max-width:100%; }
.tk-msg.internal .tk-msg-who{ color:var(--warn); }
</style>
@endonce
