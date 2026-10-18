# ماتریس پوشش قرارداد — ۱۷ اکتبر ۲۰۲۶

وضعیت پسین۱۸اکتبر08:53:43UTC: [۳۰خدمت روی تولید فعال و قیمت‌گذاری شدند](IDENTITY-API-EXPANSION-2026-10-18.md)؛ مالک توکن را به۳۴خدمت عمومی گسترش داد و حساب۳۶خدمت فعال نشان می‌دهد.۳۱schema JSON با OpenAPI تازه بدون drift تطبیق یافتند؛ خدمت۴۸ به‌علت قیمت صفر تأییدنشده و چهار مانع فنی قبلی هنوز بسته‌اند. محصول حسابی۳۵ «پرداخت قبض» تازه کشف شد، فاقد schema/تعرفهٔ عمومی است و ردیف اجرایی حدسی نگرفت. ستون دسترسی و «فروش۰» جدول۱۷اکتبر زیر تاریخی‌اند؛ تست واقعی همه سرویس‌ها ادعا نمی‌شود.

تعداد: ۳۵ محصول؛ ۳۱ پیاده‌سازی JSON و آزمون مصنوعی قرارداد/ارسال/تسویه؛ فروش فعال ۰. آزمون مصنوعی به معنی قبولی زنده نیست. وضعیت حساب از پنل همان روز است؛ نبود در فهرست فعال به معنی منع قراردادی قطعی نیست.

| ServerNet slug | شناسهٔ رسمی | گروه | دسترسی مشاهده‌شدهٔ حساب | پیاده‌سازی/آزمون | مانع فعال‌سازی |
|---|---|---|---|---|---|
| `shahkar-inquiry` | `ShahkarMatchPhoneNumberNationalId` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `convert-card-to-iban` | `CardToIban` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `iban-inquiry` | `IBAN` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `ocr-for-id-card` | `NationalCardOCR` | authentication | active_in_dashboard_2026-10-17 | blocked | media_contract_conflict |
| `company-inquiry` | `CompanyInquery` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `postal-code-inquiry` | `PostalCodeInquery` | utility | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `vehicle-inquiry-total-violations` | `VehicleTotalViolations` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `vehicle-inquiry-violations-details` | `VehicleViolationsDetails` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `match-payment-card-number-and-id-number` | `CheckCardWithNationalCode` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `match-iban-and-id-number` | `CheckIBANWithNationalCode` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `enamad-inquiry` | `EnamadInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `company-inquiry-board-members` | `CompanyBoardMembers` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `company-inquiry-board-members-history` | `CompanyBoardMembersHistory` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `card-inquiry` | `CardInquery` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `convert-account-number-to-iban` | `AccountToIBAN` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `match-name-and-payment-card` | `CheckCardWithName` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `match-iban-and-name` | `CheckIBANWithName` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `convert-card-to-account-number` | `CardToAccount` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `convert-persian-name-to-english` | `PersianToEnglish` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `id-inquiry` | `NationalIdentity` | authentication | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `liveness-check` | `BiometricCheckLiveNess` | authentication | active_in_dashboard_2026-10-17 | blocked | media_and_stage_billing_unverified |
| `check-sayad-inquiry` | `CheckSayadInquiry` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `bounced-cheque-inquiry` | `BouncedCheque` | bank | active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `voice-otp` | `VoiceOTP` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `credit-inquiry` | `CreditInquiry` | bank | active_in_dashboard_2026-10-17 | blocked | missing_official_method_payload_and_stage_billing |
| `water-bill-inquiry` | `WaterBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `electricity-bill-inquiry` | `ElectricityBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `rightel-bill-inquiry` | `RightelBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `irancell-bill-inquiry` | `IrancellBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `mci-bill-inquiry` | `MCIBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `fixed-line-bill-inquiry` | `FixedLineBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `gas-bill-inquiry` | `GasBillInquiry` | utility | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `sayad-transfers-chain-inquiry` | `SayadTransfersChainInquiry` | bank | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `cheque-submitted-inquiry` | `ChequeSubmittedInquiry` | bank | not_listed_active_in_dashboard_2026-10-17 | implemented / synthetic tested | مجوز بازفروش، تعرفه و دسترسی معتبر، egress و funding |
| `iban-detailed-inquiry` | `ندارد؛ حساب 44` | bank | active_in_dashboard_2026-10-17 | blocked | account_only_product_no_public_schema |

موانع مستقل: OCR تعارض multipart با JSON/URI؛ ویدیو محدودیت media و صورتحساب مراحل؛ اعتبارسنجی فقدان method/payload معتبر OTP و result؛ شبا با جزئیات حساب ۴۴ فاقد schema عمومی. هیچ‌کدام با مسیر یا تعرفهٔ حدسی اجرا نمی‌شوند.

مرجع روش و سیاست: [IDENTITY-API](IDENTITY-API.md).
