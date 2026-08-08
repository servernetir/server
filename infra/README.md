# ServerNet — infra/ (زیرساختِ اکسیت + Proxmox)

این پوشه **بخشِ زیرساختیِ** پلتفرم است و از اپِ لاراول (`website/`) جداست. اپ
مغزِ فروش/صورتحساب/پنل است؛ این‌جا **کدِ عملیاتِ سرورهای فیزیکی/شبکه** است که
VMها رویشان ساخته و مسیریابیِ کشوری‌شان اعمال می‌شود. هیچ‌کدام روی `website/`
اثر نمی‌گذارد (فقط فایل‌های تازه در یک پوشهٔ جدا).

## `infra/ansible/` — بازتولیدِ کاملِ زیرساختِ ایران
یک مخزنِ Ansible (۱۲ نقش) که کلِ سرورِ Proxmoxِ ایران + موتورِ اکسیتِ چندکشوری را
از صفر می‌سازد و **بازتولیدپذیر** می‌کند:
- `proxmox_host`, `cloudinit_snippet`, `linux_templates`, `windows_template` — پایهٔ میزبان و قالب‌ها.
- `exit_relay` — رله‌ی تعویض‌پذیرِ خود-ترمیم (آپلینکِ تمیزِ ضدِ DPI).
- `exit_multicountry` — استخرِ mihomo برای هر کشور (dial از راهِ رله).
- `mihomo_killswitch` — کیل‌سوییچِ شبکه‌ای (بدونِ نشتِ آی‌پیِ ایران).
- `exit_country_routing` — مسیریابیِ per-country با connmark split (ورودی از IPِ ایران حفظ، خروجی کشوری) + یونیتِ boot برای پایداریِ ریبوت.
- `exit_dedicated` — ثبتِ سرورِ خارجیِ خودمان به‌عنوانِ اکسیتِ تضمینیِ یک کشور.
- `control_panel`, `pf_agent` — عاملِ port-forward + عاملِ country که **desired-state را از پنل pull می‌کنند**.

**اجرا** (روی خودِ هاستِ Proxmox، همه پیش‌فرض stage — تا فلگِ apply ندهی چیزی زنده نمی‌شود):
```bash
apt install -y ansible-core   # یا pipx install ansible-core
cd infra/ansible
ansible-playbook -i localhost, -c local site.yml --check
# سپس مرحله‌به‌مرحله با فلگ‌ها: relay_apply / mc_apply / killswitch_apply / country_routing_apply / dedicated_apply
```
جزئیات در `infra/ansible/README.md`.

## نسبتِ زیرساخت با اپ (`website/`)
```
مشتری در پنل «Exit VPS» می‌خرد
   → CloudProvisioner  →  App\Services\Cloud\ProxmoxClient   (VM را روی هاستِ ایران می‌سازد)
هاستِ ایران:  pf-agent + country-agent  →  desired-state را از endpointهای /agent/* پنل pull می‌کنند
   → port-forwardِ دسترسیِ ورودی + مسیریابیِ کشورِ خروجی را اعمال می‌کنند
Ansible (این پوشه) همان هاست + موتورِ اکسیت را می‌سازد/بازتولید می‌کند.
```

## نقشهٔ ادغام (روی برنچِ `feature/exit-platform`، به‌صورتِ چند تکهٔ کوچکِ قابلِ‌مرج)
- **D1 ✅ `ProxmoxClient`** — Proxmoxِ خودمان به‌عنوانِ یک زیرساختِ ابریِ سفیدبرچسب در `app/Services/Cloud/` (ثبت در `CloudManager`، تنظیماتِ ادمین، کاتالوگ، تست). ساختِ VM از راهِ پایپ‌لاینِ موجود.
- **D2 (بعدی) — سیم‌کشیِ اکسیتِ کشوری:** محصولِ «Exit VPS» با انتخابِ کشورِ خروجی؛ ذخیرهٔ `exit_country` روی `cloud_instances`؛ endpointهای توکن‌دارِ `/agent/countryroutes` و `/agent/portforwards` که عامل‌های هاستِ ایران pull می‌کنند.
- **D3 — بخشِ ادمینِ «زیرساخت/اکسیت»:** مدیریتِ رله‌ها/اکسیت‌های کشوری/کیل‌سوییچ + سلامتِ عامل‌ها از داخلِ پنلِ مدیریت.
- **D4 ✅ Ansible** — همین پوشه (`infra/ansible/`).

> این پوشه عمداً بیرونِ `website/` است تا ابزارها/CI/گرافِ اپ را تحتِ‌تأثیر نگذارد.
