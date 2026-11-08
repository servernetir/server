# خط‌مشی پشتیبانی سرورنت — نسخه ۱

تاریخ تصویب و انتشار: 2026-11-08 / ۱۷ آبان ۱۴۰۵، به دستور مدیریت برای انتشار سند مسئولیت.

## وضعیت انتشار

- فارسی: https://servernet.cloud/docs/support-policy
- English: https://servernet.cloud/en/docs/support-policy
- Türkçe: https://servernet.cloud/tr/docs/support-policy
- CMS: مطلب 525، نوع kb، وضعیت published، سه ترجمه واقعی و هفت سرفصل در هر زبان.
- دانش آراز: knowledge:17:v1، نوع runbook، عمومی، general، تأییدشده؛ بازیابی واژگانی واقعی برای «خط‌مشی پشتیبانی» تأیید شد.
- تطبیق عمومی: هر سه صفحه با عنوان و متن زبان مربوط، canonical همان زبان و بدون بیرون‌زدگی افقی دسکتاپ بررسی شدند.
- انتشار از CMS موجود انجام شد؛ تغییر کد وب، migration، تنظیمات مالی، وضعیت سرویس مشتری یا مجوزهای عملیاتی صورت نگرفت.
- بررسی حدود: حفظ تعهدات خرید قبلی و قرارداد مدیریت، مسئولیت رفع نقص سرویس خود سرورنت، بررسی اولیه پیش از نسبت‌دادن خطا به مشتری، توافق مستقل خدمات اختصاصی، جداسازی آزمایش محلی از تأیید مسیر عمومی و حفظ پرونده حل‌نشده.
- این ثبت، تضمین تغییر رفتار همه پاسخ‌های آینده یا آزمون انتهابه‌انتهای نویسنده تیکت آراز نیست؛ منبع دانش ثبت و بازیابی آن تأیید شده است.

## منبع و بازبینی

نسخه عمومی از سند ServerNet_Support_Policy_v1_2026-11-08 تهیه شد؛ دستور مدیریت برای انتشار در همان روز، تصویب این انتشار است. هیچ جزئیات مشتری یا شناسه خصوصی زیرساخت در متن عمومی وجود ندارد. محتوا باید با تغییر محصول، بسته آماده یا قرارداد مدیریت بازبینی شود. در مخزن دانش موعد بازبینی آینده ثبت است.

## بازگشت

در صورت لزوم فقط همین مطلب CMS به پیش‌نویس و همین منبع دانش به نیازمند بازبینی برگردد؛ سایر مطالب، دانش‌ها، قراردادها و داده‌های مشتری تغییر نکنند. این عملیات بازگشت در این انتشار اجرا نشده است.

## متن فارسی

نسخهٔ ۱ — تاریخ انتشار و اجرا: ۱۷ آبان ۱۴۰۵ (۸ نوامبر ۲۰۲۶)

این خط‌مشی، حدود پشتیبانی خدمات سرورنت و شیوهٔ رسیدگی به درخواست‌ها را روشن می‌کند. سلامت و دسترسی اجزایی که در سفارش فروخته‌ایم در محدودهٔ مسئولیت ماست. ساخت محصول، رفع باگ کد و انجام پروژهٔ اختصاصی مشتری زمانی تعهد سرورنت است که در قرارداد یا خدمت جداگانه پذیرفته شده باشد.

۱. ملاک پوشش پشتیبانی

مشخصات سفارش، سطح مدیریت، توافق ثبت‌شده، شرایط پذیرفته‌شده هنگام خرید و سند سطح خدمات همان سفارش، ملاک رسیدگی هستند. این خط‌مشی خدمات قبلاً فروخته‌شده یا تعهدات پذیرفته‌شده را کاهش نمی‌دهد و به‌تنهایی قرارداد مشتری را تغییر نمی‌دهد. در خدمات مدیریت‌شده، پشتیبانی وردپرس، انتقال یا نگهداری برنامه، مواردی که جداگانه تعهد شده‌اند طبق همان قرارداد انجام می‌شوند.

استفاده از تأمین‌کنندهٔ بیرونی، مسئولیت پیگیری بخش متعلق به سرورنت را از بین نمی‌برد. مشکل نامشخص را پیش از بررسی اولیه به برنامهٔ مشتری نسبت نمی‌دهیم. رفع نقص خود سرویس، مشروط به خرید خدمات اضافه نیست.

۲. هاست لینوکس، ویندوز و نمایندگی

ایجاد صحیح حساب، منابع و امکانات سفارش، دسترسی کنترل‌پنل، وب‌سرور و سرویس پایگاه داده در بخش مدیریت‌شده با سرورنت است. اتصال دامنه، HTTPS، ایمیل و پشتیبان‌گیری نیز در حد اقلام فروخته‌شده پشتیبانی می‌شوند. نسخه‌ها، سهمیه‌ها و دسترسی‌های یک پلن به پلن دیگر تعمیم داده نمی‌شوند.

در هاست ویندوز، عملکرد IIS، تنظیمات مجاز Application Pool، Runtime اعلام‌شده، موتور MSSQL و دسترسی وعده‌داده‌شده بررسی می‌شوند. در نمایندگی، سلامت پنل و حساب‌های زیرمجموعه در حدود قرارداد پشتیبانی می‌شود؛ پشتیبانی مستقیم مشتری نهایی تابع قرارداد نمایندگی است.

منطق و باگ برنامه، قالب و افزونهٔ انتخابی، تنظیمات اختصاصی پروژه، وابستگی‌های خارج از نسخه‌های اعلام‌شده، محتوا و بهینه‌سازی کد با مشتری یا توسعه‌دهندهٔ اوست؛ مگر قرارداد جداگانه‌ای داشته باشد. اجرای Migration، اصلاح Query یا تهیهٔ فایل انتشار برنامه جزو پشتیبانی عادی هاست نیست. خطای ۵۰۰ به‌تنهایی خرابی کد مشتری را ثابت نمی‌کند؛ ابتدا محیط و گزارش مرتبط بررسی می‌شود.

۳. GPU و برنامه‌های آماده

هر سفارش شامل کارت و برنامهٔ انتخاب‌شدهٔ همان سفارش است. خرید یکی از برنامه‌های Jupyter/PyTorch، ComfyUI یا Ollama به معنی تحویل هم‌زمان همهٔ آن‌ها نیست. دسترسی اصلی، شبکه، احراز هویت، HTTPS و درگاه یا پروکسی متعلق به سرورنت در محدودهٔ پشتیبانی ما قرار دارند.

Jupyter/PyTorch: ورود، بازشدن محیط، اجرای نمونهٔ سبک و شناسایی GPU در محیط اعلام‌شده بررسی می‌شود. نوشتن Notebook، انتخاب و نصب مدل، محیط اختصاصی و API شخصی با مشتری است.

ComfyUI: دسترسی به رابط یا API اعلام‌شده و کارکرد نمونهٔ پایهٔ سازگار با بستهٔ تحویلی پشتیبانی می‌شود. ساخت تصویر و انیمیشن، طراحی Workflow و نصب Custom Node یا مدل دلخواه، خدمت اختصاصی است.

Ollama: اتصال و احراز API و فراخوانی مدل پایهٔ اعلام‌شده، در صورت وجود، بررسی می‌شود. انتخاب مدل اختصاصی، ساخت چت‌بات، آموزش، Prompt و بهینه‌سازی پروژه با مشتری است.

نقص تصویر اولیه یا وابستگی‌ای که آماده تحویل داده‌ایم، به مشتری واگذار نمی‌شود. کیفیت خروجی، دقت OCR، سرعت تولید ویدئو و تناسب مدل با پروژه نیازمند ارزیابی جداگانه است. نصب FastAPI یا نرم‌افزار اختصاصی و نگهداری بسته‌های مشتری جزو پشتیبانی پایه نیست؛ اختلال مسیر رسمی اتصال و درگاه سرورنت با ماست.

انتشار پورت دلخواه، انتقال هدرها و سقف مؤثر حجم یا زمان پاسخ فقط بر اساس قابلیت تأییدشدهٔ همان خدمت اعلام می‌شود. موفقیت آزمایش محلی به معنی سلامت مسیر عمومی نیست. راه‌اندازی مجدد Kernel با راه‌اندازی مجدد Jupyter یکسان نیست؛ پیش از توصیهٔ تغییر، اثر آن بر فرایند اصلی، داده و هزینه بررسی می‌شود.

بستن مرورگر یا بی‌استفاده‌بودن GPU به معنی خاموشی نیست. برای توقف اختیاری، از صفحهٔ همان سرویس درخواست خاموش‌کردن بدهید و وضعیت نهایی را بررسی کنید. حفظ فایل‌ها، هزینهٔ احتمالی پس از توقف و شرایط شروع دوباره تابع همان خدمت است. درخواست نصب یا آزمایش، مجوز روشن‌کردن سرویس خاموش، دانلود حجیم یا پردازش هزینه‌دار بدون توافق نیست.

۴. سایر خدمات

VPS و سرور ابری بدون مدیریت: تخصیص مطابق سفارش، راه‌اندازی اولیهٔ تصویر عرضه‌شده، شبکه، کنسول و دسترسی اولیه با ماست. نگهداری سیستم‌عامل پس از تحویل، وب‌سرور، VPN، دیتابیس، فایروال داخل سیستم و برنامه‌ها با مشتری است؛ خطای تصویر اولیه جدا بررسی می‌شود.

سرور اختصاصی و برمتال: سخت‌افزار، منابع، شبکه و دسترسی مدیریتی فروخته‌شده و پیگیری نقص سخت‌افزار با ماست. مجازی‌سازی و نرم‌افزار مشتری تابع قرارداد مدیریت است؛ فروش قطعه طبق شرایط ضمانت همان کالا انجام می‌شود.

سرور مدیریت‌شده و DevOps: نگهداری، امنیت، پایش، نسخه و پشتیبان‌گیریِ مندرج در قرارداد با ماست. توسعهٔ قابلیت محصول یا تغییر معماری خارج از دامنه نیازمند توافق تازه است.

برنامهٔ آماده مانند n8n: دسترسی، نسخه، HTTPS، منابع، پشتیبان‌گیری و نگهداری در حد پیشنهاد پذیرفته‌شده پشتیبانی می‌شوند. طراحی و رفع منطق Workflow و اتصال‌های سفارشی تابع قرارداد جداست.

دامنه، DNS، SSL و لایسنس: تطبیق پرداخت، عملیات ثبت و تمدید، مدیریت و اعمال صحیح تنظیمات تحت اختیار ما پیگیری می‌شوند. مالکیت نام، محتوای مقصد، همکاری مالک برای صدور گواهی و مشکلات نرم‌افزار خارج از سفارش با مشتری است. زمان انتشار DNS قطعی وعده داده نمی‌شود.

ایمیل و SMTP: ورود یا اتصال، صف، خطای مسیر ما، تنظیمات دامنهٔ تحت مدیریت و سهمیهٔ سفارش پشتیبانی می‌شوند. محتوا، گیرندگان و کد مشتری با اوست؛ تضمین ورود همهٔ پیام‌ها به Inbox یا خروج از همهٔ فهرست‌های مسدود داده نمی‌شود.

درگاه هوش مصنوعی: مجوز، مسیر رسمی API، مسیریابی، خطاهای درگاه، ثبت مصرف و صورتحساب قابل تطبیق با ماست. ساخت Agent، RAG، Prompt و کد محصول و تضمین صحت خروجی جزو پشتیبانی پایه نیست.

وب‌سرویس احراز هویت: دسترسی و قرارداد API، احراز، خطا و رهگیری درخواست در مسیر ما پیگیری می‌شوند. توسعهٔ اتصال، تفسیر تجاری نتیجه، دادهٔ ورودی و مجوز استفاده با مشتری است؛ صحت منبع بیرونی بدون بررسی تضمین نمی‌شود.

پنل پیامکی، در صورت فروش: دسترسی پنل یا API، اعتبار و خط سفارش و بررسی وضعیت ثبت و تحویل پیگیری می‌شوند. محتوا، رضایت مخاطبان، گیرندگان و کد مشتری با اوست. پذیرش درخواست با تحویل قطعی یکسان نیست.

تلفن ابری و VoIP: دسترسی، شماره، داخلی و امکانات قرارداد و مسیر تماس متعلق به سرویس پشتیبانی می‌شوند. شبکه و دستگاه مشتری و اتصال اختصاصی CRM یا اپ، مگر فروخته شده باشد، با مشتری است.

ذخیره‌سازی و پشتیبان‌گیری، در صورت سفارش: دسترسی، سهمیه، پروتکل و عملیات تعهدشده بررسی می‌شوند. وجود نسخه، تاریخ، پوشش و قابلیت بازیابی باید کنترل شود؛ بازیابی یا جایگزینی داده با بررسی اثر و تأیید لازم انجام می‌شود.

ذکر نام یک خانوادهٔ خدمات به معنی فعال‌بودن فروش یا آماده‌بودن همهٔ قابلیت‌های آن نیست. فقط اقلام واقعی سفارش پشتیبانی می‌شوند.

۵. راهنمایی و خدمات فنی اضافه

راهنمایی کوتاه شامل توضیح روش اتصال رسمی، معرفی مستند معتبر یا مشخص‌کردن نوع خطاست. نصب و مدیریت مدل یا بستهٔ اختصاصی، رفع باگ، آموزش پیوسته، ساخت محتوا و اجرای پروژه در پشتیبانی پایه قرار ندارند؛ مگر در قرارداد پذیرفته شده باشند.

درخواست خدمت جداگانه ابتدا برای امکان‌سنجی ثبت می‌شود. پذیرش، دامنهٔ کار، مبلغ، زمان، مسئول اجرا و معیار تحویل باید پیش از شروع مشخص و با مشتری توافق شوند. ثبت درخواست به معنی پذیرش قطعی یا شروع کار نیست. خدمات مدیریت و تعهدات قبلی مشتری در این بررسی حفظ می‌شوند.

۶. شیوهٔ پاسخ‌گویی و پیگیری

پیش از پاسخ، مشتری، سرویس، پیام تازه، سطح مدیریت و سابقهٔ مرتبط تطبیق داده می‌شوند. پاسخ باید روشن، محترمانه، مرتبط و به زبان مشتری باشد و یافتهٔ تأییدشده را از پیشنهاد یا بخش بررسی‌نشده جدا کند. دادهٔ لازم به حداقل مرتبط محدود می‌شود و اطلاعات محرمانه در پاسخ عمومی بازنشر نمی‌شود.

تا رفع تأییدشده یا تعیین تکلیف کامل، پروندهٔ حل‌نشده برای پیگیری باز یا نگه‌داشته‌شده می‌ماند. پیش‌نویس، پاسخ ارسال‌شده محسوب نمی‌شود. اگر راهنمای قبلی سرورنت ناقص یا نادرست باشد، اصلاح و اثر احتمالی آن بررسی می‌شود. رسیدگی مالی بر اساس سوابق تحویل، وضعیت سرویس و دفتر اعتبار انجام می‌شود؛ روشن‌بودن منبع به‌تنهایی ردکنندهٔ نقص احتمالی تحویل نیست.

زمان پاسخ، زمان رفع، آپتایم و جبران هزینه از SLA و قرارداد همان خدمت گرفته می‌شوند. در نبود موعد تأییدشده، زمان قطعی اعلام نمی‌شود. پشتیبانی ۲۴ ساعته به‌تنهایی وعدهٔ رفع همهٔ مشکلات در مدت معین نیست. اختلال فراگیر، احتمال از دست‌رفتن داده یا نقص دسترسی پایه برای رسیدگی فوری ارجاع می‌شود.

۷. اجرا و بازبینی

این نسخه با تأیید مدیریت منتشر شده و مرجع عمومی حدود پشتیبانی است. پاسخ‌های تیم و دستیار آراز باید با این حدود، مشخصات واقعی محصول و مجوزهای موجود تطبیق داده شوند. این متن به‌تنهایی مجوز عملیات حساس، تغییر مالی یا تغییر وضعیت سرویس ایجاد نمی‌کند. با تغییر محصول، برنامهٔ آماده یا قرارداد مدیریت، بخش مرتبط بازبینی می‌شود.

برای پیگیری، از صفحهٔ همان سرویس در پنل کاربری تیکت ثبت کنید و مسیر، زمان خطا و پیام خطای پاک‌سازی‌شده را بنویسید. اطلاعات ورود یا دادهٔ محرمانه را در متن عمومی قرار ندهید.



## English publication text

Version 1 — published and effective: 8 November 2026

This policy explains ServerNet support responsibilities and how requests are handled. We support the availability and health of the components sold in your order. Building your product, fixing application code and completing a custom project are included only when expressly accepted in a contract or separate service.

1. What determines your coverage

Your order, management level, recorded agreements, terms accepted at purchase and the applicable service level agreement (SLA) determine coverage. This policy does not reduce previously purchased services or accepted commitments and does not, by itself, change your contract. Managed hosting, WordPress support, migration and application maintenance remain covered to the extent agreed in their respective contracts.

Using an external supplier does not remove our responsibility to investigate the ServerNet part of a problem. We perform an initial investigation before attributing an unclear issue to your application. Fixing a defect in the service itself is not conditional on buying additional support.

2. Web hosting and reseller hosting

ServerNet is responsible for correct account provisioning, ordered resources and features, control panel access, and the managed web server and database service. Domain connection, HTTPS, email and backups are supported where included in the order. Versions, quotas and permissions are specific to each plan.

For Windows hosting, we investigate IIS, permitted Application Pool settings, the advertised Runtime, MSSQL and promised access. Reseller panel health and subaccounts are supported within the reseller agreement; direct support for an end customer depends on that agreement.

Application logic, code defects, chosen themes and plugins, custom configuration, dependencies beyond advertised versions, content and code optimization are the customer's or developer's responsibility unless separately contracted. Running application migrations, rewriting queries and preparing application releases are not standard hosting support. An HTTP 500 response alone does not prove an application bug; the relevant environment and logs are investigated first.

3. GPU and ready-made applications

Each order includes its selected GPU and application. Ordering Jupyter/PyTorch, ComfyUI or Ollama does not include all three. ServerNet supports the advertised primary access, network, authentication, HTTPS and its own gateway or proxy.

Jupyter/PyTorch: login, opening the environment, an advertised lightweight baseline check and GPU detection. Custom Notebooks, models, isolated project environments and personal APIs are the customer's responsibility.

ComfyUI: the advertised interface or API and a baseline example compatible with the delivered package. Image or animation production, Workflow design, Custom Nodes and custom models are separate work.

Ollama: API connection, authentication and invocation of an advertised baseline model, if provided. Custom models, chatbots, training, prompts and project optimization are the customer's responsibility.

Defects in a base image or dependencies supplied as ready-made components remain our responsibility. Output quality, OCR accuracy, video generation speed and model suitability require project-specific evaluation. Installing FastAPI or custom software and maintaining customer packages are outside baseline support; faults in the official ServerNet connection path remain our responsibility.

Arbitrary port publishing, header forwarding and effective request size or response time limits are stated only for capabilities verified on the relevant service. A successful local test does not establish that the public path works. Restarting a Kernel is not the same as restarting Jupyter; the effect on the main process, data and charges must be considered before making changes.

Closing a browser or leaving a GPU idle does not stop it. Request shutdown from the service page and check the final state. File retention, any post-stop charges and restart conditions depend on the particular service. A request for installation or testing does not authorize starting a stopped service, large downloads or billable processing without agreement.

4. Other service families

Unmanaged VPS and cloud servers: ordered allocation, initial setup of the supplied image, network, console and promised initial access. Subsequent OS administration, web servers, VPNs, databases, guest firewalls and applications are the customer's responsibility. Initial image defects are investigated separately.

Dedicated and bare-metal servers: ordered hardware, resources, network, administrative access and hardware fault escalation. Virtualization and customer software depend on the management contract. Hardware sales follow the item's warranty terms.

Managed servers and DevOps: maintenance, security, monitoring, versions and backups expressly included in the contract. Product development and architecture changes beyond its scope need a new agreement.

Ready-made apps such as n8n: access, version, HTTPS, resources, backups and maintenance as accepted in the offer. Custom Workflow logic and integrations require the corresponding agreement.

Domains, DNS, SSL and licences: payment reconciliation, registration and renewal, management access and correct operations under our control. Name rights, destination content, owner cooperation for certificates and unrelated application issues remain with the customer. DNS propagation times are not guaranteed.

Email and SMTP: login or connection, queues, errors in our path, managed domain settings and ordered quotas. Content, recipients and customer code remain with the customer. Inbox placement and removal from every blocklist are not guaranteed.

AI Gateway: permissions, official API endpoints, routing, gateway errors, usage records and reconcilable billing. Building Agents, RAG, prompts and product code, or guaranteeing output accuracy, is outside baseline support.

Identity verification API: access, API contract, authentication, errors and request tracing in our path. Integration development, business interpretation, input data and permission to use the service remain with the customer. External source accuracy is not guaranteed without investigation.

SMS panels, where sold: panel or API access, balance, ordered messaging line and investigation of submission and delivery status. Content, audience consent, recipients and application code remain with the customer. Request acceptance is not the same as confirmed delivery.

Cloud phone and VoIP: contracted access, numbers, extensions, features and the service's call path. Customer networks, devices and custom CRM or app integrations remain with the customer unless included.

Storage and backups, where ordered: access, quota, protocol and contracted operations. Backup existence, date, coverage and recoverability must be checked. Restoring or overwriting data requires an impact review and the necessary consent.

Listing a service family here does not mean it is currently on sale or that every capability is available. Coverage applies to what was actually ordered.

5. Guidance and additional technical work

Brief guidance covers the official connection method, relevant documentation or identifying an error category. Custom package or model installation and management, debugging, ongoing training, content production and project execution are not baseline support unless contracted.

Separate work is first assessed for feasibility. Acceptance, scope, price, timing, responsible party and delivery criteria must be agreed before work begins. Submitting a request does not itself mean acceptance or commencement. Existing managed service commitments remain in force.

6. Replies and follow-up

We match the customer, service, latest message, management level and relevant history before responding. Replies should be clear, respectful, relevant and in the customer's language, distinguishing verified findings from proposals and untested areas. We request only relevant information and do not reproduce confidential information in public replies.

Unresolved cases stay open or on hold for follow-up until resolution is verified or the matter is fully settled. A draft is not a sent reply. Incorrect or incomplete ServerNet guidance must be corrected and its possible effects investigated. Billing disputes are assessed against delivery history, service state and the balance ledger; a running resource alone does not disprove a delivery defect.

Response times, resolution times, uptime and compensation come from the applicable SLA and contract. We do not give an unconfirmed deadline. Around-the-clock support alone does not promise resolution of every issue within a fixed time. Widespread outages, potential data loss and failure of basic access receive urgent escalation.

7. Implementation and review

This management-approved version is the public reference for support scope. Team and Araz assistant replies must follow these limits, actual product specifications and existing permissions. This policy does not itself authorize sensitive operations, financial changes or service state changes. Relevant sections are reviewed when a product, ready-made application or management agreement changes.

For follow-up, open a ticket from the relevant service page and provide the path, error time and a sanitized error message. Do not put login details or confidential data in public text.



## Türkçe yayın metni

Sürüm 1 — yayın ve yürürlük tarihi: 8 Kasım 2026

Bu politika, ServerNet desteğinin sorumluluklarını ve taleplerin nasıl ele alındığını açıklar. Siparişinizde satılan bileşenlerin erişimini ve sağlıklı çalışmasını destekleriz. Ürününüzü geliştirmek, uygulama kodundaki hataları düzeltmek ve özel projenizi yapmak ancak sözleşmede veya ayrı bir hizmette açıkça kabul edilmişse kapsamımıza girer.

1. Destek kapsamının dayanağı

Sipariş, yönetim seviyesi, kayıtlı anlaşmalar, satın alırken kabul edilen koşullar ve ilgili hizmet seviyesi sözleşmesi (SLA) esas alınır. Bu politika önceden satın alınmış hizmetleri veya kabul edilmiş taahhütleri azaltmaz ve tek başına müşteri sözleşmesini değiştirmez. Yönetilen hizmet, WordPress desteği, taşıma veya uygulama bakımı için kabul edilmiş işler kendi sözleşmelerine göre sürdürülür.

Dış sağlayıcı kullanılması, ServerNet'e ait bölümün takibini ortadan kaldırmaz. Belirsiz bir sorunu ilk inceleme yapılmadan müşterinin uygulamasına bağlamayız. Hizmetin kendi kusurunun giderilmesi ek destek satın alınmasına bağlanmaz.

2. Web hosting ve bayi hosting

Hesabın doğru oluşturulması, sipariş edilen kaynak ve özellikler, kontrol paneli erişimi, yönetimimizdeki web sunucusu ve veritabanı hizmeti ServerNet'in sorumluluğundadır. Alan adı bağlantısı, HTTPS, e-posta ve yedekleme de siparişte bulunduğu ölçüde desteklenir. Sürüm, kota ve yetkiler her pakete özeldir.

Windows hostingde IIS, izin verilen Application Pool ayarları, belirtilen Runtime, MSSQL ve vaat edilen erişim incelenir. Bayi paneli ve alt hesaplar bayi sözleşmesi kapsamında desteklenir; son müşteriye doğrudan destek bu sözleşmeye bağlıdır.

Uygulama mantığı, kod hataları, seçilen tema ve eklentiler, projeye özel ayarlar, belirtilen sürümler dışındaki bağımlılıklar, içerik ve kod optimizasyonu ayrı sözleşme yoksa müşteri veya geliştiricisine aittir. Uygulama Migration işlemleri, Query düzeltme ve yayın dosyası hazırlama standart hosting desteği değildir. HTTP 500 tek başına müşteri kodunun hatalı olduğunu kanıtlamaz; önce ilgili ortam ve kayıtlar incelenir.

3. GPU ve hazır uygulamalar

Her sipariş seçilen GPU ve uygulamayı kapsar. Jupyter/PyTorch, ComfyUI veya Ollama seçeneklerinden birini satın almak üçünün birlikte teslimi anlamına gelmez. Belirtilen temel erişim, ağ, kimlik doğrulama, HTTPS ve ServerNet'e ait ağ geçidi veya proxy desteğimizdedir.

Jupyter/PyTorch: giriş, ortamın açılması, belirtilmiş hafif temel kontrol ve GPU'nun tanınması incelenir. Özel Notebook, model, proje ortamı ve kişisel API müşteriye aittir.

ComfyUI: belirtilen arayüz veya API ile teslim edilen pakete uygun temel örnek desteklenir. Görsel ve animasyon üretimi, Workflow tasarımı, Custom Node ve özel model kurulumu ayrı iştir.

Ollama: API bağlantısı, kimlik doğrulama ve varsa belirtilen temel modelin çağrılması incelenir. Özel model, sohbet botu, eğitim, Prompt ve proje optimizasyonu müşteriye aittir.

Hazır teslim ettiğimiz imaj veya bağımlılık kusurları müşteriye bırakılmaz. Çıktı kalitesi, OCR doğruluğu, video üretim hızı ve modelin projeye uygunluğu ayrıca değerlendirilir. FastAPI veya özel yazılım kurulumu ve müşteri paketlerinin bakımı temel destek değildir; resmi ServerNet bağlantı yolundaki sorunları biz takip ederiz.

İstenen portun dışarı açılması, başlık aktarımı ve etkili istek boyutu veya yanıt süresi yalnızca ilgili hizmette doğrulanan özelliklere göre bildirilir. Yerel testin başarılı olması, dış erişim yolunun çalıştığını kanıtlamaz. Kernel yeniden başlatma ile Jupyter yeniden başlatma aynı değildir; değişiklikten önce ana işlem, veri ve ücret üzerindeki etki incelenir.

Tarayıcıyı kapatmak veya GPU'yu boşta bırakmak hizmeti durdurmaz. İlgili hizmet sayfasından kapatma isteği verin ve son durumu kontrol edin. Dosyaların korunması, duruş sonrası olası ücret ve yeniden başlatma koşulları ilgili hizmete bağlıdır. Kurulum veya test talebi, anlaşma olmadan kapalı hizmeti açma, büyük indirme veya ücretli işleme izni vermez.

4. Diğer hizmetler

Yönetimsiz VPS ve bulut sunucu: siparişe uygun kaynak tahsisi, sunulan imajın ilk kurulumu, ağ, konsol ve vaat edilen ilk erişim bize aittir. Sonraki işletim sistemi yönetimi, web sunucusu, VPN, veritabanı, misafir sistem güvenlik duvarı ve uygulamalar müşteriye aittir. İlk imaj kusuru ayrıca incelenir.

Dedicated ve bare-metal: sipariş edilen donanım, kaynak, ağ, yönetim erişimi ve donanım arızasının takibi bize aittir. Sanallaştırma ve müşteri yazılımı yönetim sözleşmesine bağlıdır. Parça satışı ürünün garanti koşullarına tabidir.

Yönetilen sunucu ve DevOps: sözleşmedeki bakım, güvenlik, izleme, sürüm ve yedekleme bize aittir. Kapsam dışındaki ürün geliştirme veya mimari değişikliği yeni anlaşma gerektirir.

n8n gibi hazır uygulamalar: kabul edilmiş teklifteki erişim, sürüm, HTTPS, kaynak, yedek ve bakım desteklenir. Özel Workflow mantığı ve entegrasyonlar ilgili sözleşmeye bağlıdır.

Alan adı, DNS, SSL ve lisans: ödeme eşleştirme, kayıt ve yenileme, yönetim erişimi ve kontrolümüzdeki doğru işlemler takip edilir. İsim hakları, hedef içerik, sertifika için sahibin işbirliği ve sipariş dışı uygulama sorunları müşteriye aittir. DNS yayılımı için kesin süre verilmez.

E-posta ve SMTP: giriş veya bağlantı, kuyruk, bizim yolumuzdaki hatalar, yönetilen alan adı ayarları ve sipariş kotası desteklenir. İçerik, alıcılar ve müşteri kodu müşteriye aittir. Tüm iletilerin Inbox'a düşmesi veya tüm engel listelerinden çıkış garanti edilmez.

AI Gateway: yetki, resmi API adresi, yönlendirme, ağ geçidi hataları, kullanım kaydı ve doğrulanabilir faturalama bize aittir. Agent, RAG, Prompt ve ürün kodu geliştirme veya çıktının doğruluğunu garanti etme temel destek değildir.

Kimlik doğrulama API: erişim, API sözleşmesi, kimlik doğrulama, hata ve bizim yolumuzdaki istek takibi yapılır. Entegrasyon geliştirme, ticari yorum, giriş verisi ve kullanım izni müşteriye aittir. Dış kaynağın doğruluğu inceleme olmadan garanti edilmez.

Satılıyorsa SMS paneli: panel veya API erişimi, bakiye, sipariş hattı ve gönderim/teslim durumu incelenir. İçerik, alıcı onayı, alıcılar ve uygulama kodu müşteriye aittir. İsteğin kabul edilmesi kesin teslim anlamına gelmez.

Bulut telefon ve VoIP: sözleşmedeki erişim, numara, dahili, özellikler ve hizmetin çağrı yolu desteklenir. Müşterinin ağı, cihazı ve ayrıca satılmamış özel CRM veya uygulama bağlantıları müşteriye aittir.

Sipariş edilmiş depolama ve yedekleme: erişim, kota, protokol ve taahhüt edilen işlemler incelenir. Yedeğin varlığı, tarihi, kapsamı ve geri yüklenebilirliği kontrol edilir. Veriyi geri yükleme veya üzerine yazma, etki incelemesi ve gerekli onayla yapılır.

Bir hizmet ailesinin burada bulunması, satışının açık veya tüm özelliklerinin hazır olduğu anlamına gelmez. Kapsam fiilen sipariş edilen bileşenlere uygulanır.

5. Yönlendirme ve ek teknik hizmet

Kısa yönlendirme; resmi bağlantı yöntemini açıklamak, uygun dokümanı göstermek veya hata türünü belirlemektir. Özel paket veya model kurup yönetmek, hata ayıklamak, sürekli eğitim, içerik üretimi ve proje yürütmek sözleşmede yoksa temel destek değildir.

Ayrı hizmet talebi önce yapılabilirlik açısından değerlendirilir. Kabul, kapsam, ücret, süre, sorumlu ve teslim ölçütleri işe başlamadan belirlenip müşteriyle kararlaştırılır. Talep açılması tek başına kabul veya işe başlama anlamına gelmez. Önceki yönetilen hizmet taahhütleri korunur.

6. Yanıt ve takip

Yanıttan önce müşteri, hizmet, yeni mesaj, yönetim seviyesi ve ilgili geçmiş eşleştirilir. Yanıt açık, saygılı, ilgili ve müşterinin dilinde olmalı; doğrulanan bulgular önerilerden ve test edilmemiş alanlardan ayrılmalıdır. Yalnızca gerekli bilgi istenir ve gizli bilgi genel yanıtlarda paylaşılmaz.

Çözülmemiş kayıtlar, çözüm doğrulanana veya konu tamamen sonuçlanana kadar açık ya da beklemede tutulur. Taslak, gönderilmiş yanıt değildir. ServerNet'in hatalı veya eksik yönlendirmesi düzeltilir ve olası etkisi incelenir. Ücret itirazı teslim geçmişi, hizmet durumu ve bakiye kayıtlarına göre değerlendirilir; kaynağın çalışmış olması tek başına teslim kusurunu çürütmez.

Yanıt ve çözüm süresi, çalışma oranı ve telafi ilgili SLA ve sözleşmeye göre belirlenir. Doğrulanmamış kesin süre verilmez. 7/24 destek, her sorunun belli sürede çözüleceği taahhüdü değildir. Yaygın kesinti, olası veri kaybı ve temel erişim kusuru acilen ilgili sorumluya iletilir.

7. Uygulama ve gözden geçirme

Yönetimin onayıyla yayımlanan bu sürüm, destek kapsamının genel referansıdır. Ekip ve Araz asistanının yanıtları bu kapsamla, gerçek ürün özellikleriyle ve mevcut yetkilerle uyumlu olmalıdır. Bu metin tek başına hassas işlem, mali değişiklik veya hizmet durumu değişikliği yetkisi vermez. Ürün, hazır uygulama veya yönetim anlaşması değiştiğinde ilgili bölüm gözden geçirilir.

Takip için ilgili hizmet sayfasından destek kaydı açın; yolu, hata zamanını ve gizli bilgileri çıkarılmış hata mesajını yazın. Giriş bilgilerini veya gizli verileri genel metne eklemeyin.


