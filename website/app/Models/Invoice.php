<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id', 'service_id', 'domain_id', 'number', 'kind', 'currency_code',
        'subtotal', 'tax', 'total', 'paid', 'status', 'note',
        'issued_at', 'due_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'  => 'integer',
            'tax'       => 'integer',
            'total'     => 'integer',
            'paid'      => 'integer',
            'issued_at' => 'datetime',
            'due_at'    => 'datetime',
            'paid_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            if (blank($i->number)) {
                $i->number = self::nextNumber();
            }

            /*
            | مهلتِ پرداخت — یک نویسنده برای همهٔ مسیرها.
            |
            | 🔴 چرا این‌جا و نه در تک‌تکِ کنترلرها: امروز **۹ جا** فاکتور
            | ساخته می‌شود (فروشگاه، دامنه، ابری، تمدیدِ دامنه، فروشِ مدیر،
            | فروشِ تلفنی، شارژِ اعتبار، نمایندگی…). گذاشتنِ یک خط در هر
            | ۹ جا یعنی همان تلهٔ ثبت‌شدهٔ «دورهٔ شش‌ماهه در ۷ جا»: مسیرِ
            | دهم فردا اضافه می‌شود و بی‌مهلت می‌مانَد — یعنی دقیقاً همان
            | فاکتورِ معلقی که این قابلیت برای حذفش نوشته شد.
            |
            | ⚠️ فقط وقتی خودِ فراخوان مقداری نداده باشد. مسیری که عمداً
            | مهلتِ دیگری می‌خواهد (مثلاً فاکتورِ تمدید که سررسیدِ واقعیِ
            | سرویس را دارد) دست‌نخورده می‌مانَد.
            |
            | ⚠️ صفر یا منفی یعنی «خاموش»، نه «فوراً منقضی». اگر تنظیمات
            | خراب باشد نباید هر فاکتورِ تازه در همان ثانیه لغو شود.
            */
            $hours = (int) config('billing.invoice_hold_hours', 48);

            if ($i->due_at === null && $hours > 0) {
                $i->due_at = ($i->issued_at ?: now())->copy()->addHours($hours);
            }
        });
    }

    /** چند بار شمارهٔ تازه امتحان شود پیش از آنکه خطا واقعاً بالا برود */
    private const NUMBER_TRIES = 6;

    /**
     * شمارهٔ فاکتور: INV-<تاریخ>-<۴ رقم تصادفی>.
     *
     * تصادفی و نه پیاپی — شمارهٔ پیاپی به مشتری می‌گوید امروز چند فاکتور
     * صادر کرده‌ایم.
     */
    public static function nextNumber(): string
    {
        return 'INV-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 🔴 برخوردِ شمارهٔ فاکتور = خطای ۵۰۰ وسطِ تسویهٔ مشتری.
     *
     * توضیحِ `nextNumber()` سال‌ها می‌گفت «برخورد با تلاش دوباره حل می‌شود، چون
     * ستون unique است» — ولی هیچ تلاشِ دوباره‌ای در کد نبود. `unique` برخورد را
     * **می‌گیرد**، حلش نمی‌کند: استثنا از `DB::transaction` بیرون می‌زند،
     * تراکنش برمی‌گردد، و مشتری یک صفحهٔ خطا می‌بیند بی‌آنکه سرویس یا فاکتوری
     * ساخته شده باشد.
     *
     * و فضای شماره کوچک است: چهار رقم یعنی **۱۰٬۰۰۰ شماره در روز**. طبقِ مسئلهٔ
     * تولد، با ۱۰۰ فاکتور در روز احتمالِ حداقل یک برخورد ~۳۹٪ است و با ۲۰۰ تا
     * ~۸۶٪. یعنی این باگ با رشدِ فروش **حتمی‌تر** می‌شود، نه کمیاب‌تر.
     *
     * ⚠️ چرا `save()` و نه یک متدِ تازه: ده‌ها جای پروژه `Invoice::create()`
     * می‌زنند و `create()` هم به همین `save()` می‌رسد. متدِ تازه یعنی هر
     * فراخوانِ فردا باید یادش باشد از آن استفاده کند — همان الگویی که در این
     * پروژه بارها شکسته.
     *
     * ⚠️ فقط روی **درج** و فقط روی برخوردِ خودِ ستونِ `number`. اگر یکتاییِ
     * دیگری بشکند باید بالا برود، وگرنه این حلقه یک باگِ واقعی را شش بار
     * تکرار و بعد پنهان می‌کند.
     */
    public function save(array $options = []): bool
    {
        $isInsert = ! $this->exists;

        for ($try = 1; ; $try++) {
            try {
                return parent::save($options);
            } catch (UniqueConstraintViolationException $e) {
                if (! $isInsert || $try >= self::NUMBER_TRIES || ! self::isNumberCollision($e)) {
                    throw $e;
                }

                $this->number = self::nextNumber();
            }
        }
    }

    /**
     * آیا این نقضِ یکتایی مالِ ستونِ `number` است؟
     *
     * متنِ خطا درایورمحور است ولی هر دو درایورِ ما نامِ ستون را در آن می‌آورند:
     *   SQLite : UNIQUE constraint failed: invoices.number
     *   MariaDB: Duplicate entry '…' for key 'invoices_number_unique'
     */
    private static function isNumberCollision(UniqueConstraintViolationException $e): bool
    {
        return str_contains(mb_strtolower($e->getMessage()), 'number');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['unpaid', 'draft'], true) && $this->due() > 0;
    }

    /**
     * آیا این فاکتور قابلِ حذف است؟ فقط فاکتوری که هیچ پولی رویش ننشسته و
     * پرداخت‌شده/جزئی نیست — تا سابقهٔ مالی و مالیاتی هرگز پاک نشود.
     */
    public function isDeletable(): bool
    {
        return $this->paid <= 0
            && in_array($this->status, ['draft', 'unpaid', 'overdue', 'canceled'], true);
    }

    /** مانده — هرگز منفی */
    public function due(): int
    {
        return max(0, $this->total - $this->paid);
    }
}
