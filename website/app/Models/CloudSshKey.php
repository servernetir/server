<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * کلیدِ عمومیِ SSH مشتری.
 *
 * چرا مالِ مشتری است و نه سرویس: یک مشتری چند سرور دارد و یک کلید. اگر کلید
 * روی سرویس بنشیند، هر خرید یک بار چسباندنِ دوباره می‌خواهد و تغییرِ کلید باید
 * چند جا تکرار شود.
 *
 * ⚠️ کلیدِ **عمومی** راز نیست (برای همین است که «عمومی» است)، پس رمزنگاری
 * نمی‌شود. آنچه هرگز از مشتری نمی‌گیریم کلیدِ **خصوصی** است؛ اگر کسی خواست
 * بفرستدش، باید رد شود.
 */
class CloudSshKey extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'public_key', 'fingerprint', 'key_type',
        'provider_refs', 'last_used_at',
    ];

    /** شناسه‌های نزدِ زیرساخت‌ها داخلی‌اند و به مشتری مربوط نیستند */
    protected $hidden = ['provider_refs'];

    protected $casts = [
        'provider_refs' => 'array',
        'last_used_at'  => 'datetime',
    ];

    /** انواعی که هر دو زیرساخت و OpenSSH مدرن می‌شناسند */
    public const TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * اعتبارسنجیِ کلیدِ عمومی.
     *
     * چرا فقط «شکلش درست است» کافی نیست: اگر کلیدِ بی‌معنا را به زیرساخت
     * بفرستیم، خطای خامش به مشتری می‌رسد (و شناسه‌های بومی را لو می‌دهد). پس
     * همین‌جا رد می‌کنیم، با پیامی که بگوید مشکل چیست.
     *
     * @return array{ok:bool,message:string,type:?string,fingerprint:?string,normalized:?string}
     */
    public static function inspect(string $raw): array
    {
        $bad = ['ok' => false, 'type' => null, 'fingerprint' => null, 'normalized' => null];

        // 🔴 مهم‌ترین بررسی: کسی نباید کلیدِ **خصوصی**‌اش را این‌جا بچسباند.
        // اگر بچسباند و ما ذخیره کنیم، رازش در دیتابیسِ ما نشسته است.
        if (preg_match('/BEGIN [A-Z ]*PRIVATE KEY/i', $raw)) {
            return $bad + ['message' => 'این کلیدِ **خصوصی** است و هرگز نباید جایی فرستاده شود. کلیدِ عمومی (فایلِ ‎.pub‎) را بچسبانید.'];
        }

        // فضاهای اضافه و شکستِ خطِ کپی‌شده از ترمینال را تمیز کن
        $key = trim(preg_replace('/\s+/', ' ', $raw) ?? '');

        if ($key === '') {
            return $bad + ['message' => 'کلید خالی است.'];
        }

        $parts = explode(' ', $key);

        if (count($parts) < 2) {
            return $bad + ['message' => 'قالبِ کلید درست نیست. باید با ‎ssh-ed25519‎ یا ‎ssh-rsa‎ شروع شود.'];
        }

        [$type, $body] = [$parts[0], $parts[1]];
        $comment = $parts[2] ?? '';

        if (! in_array($type, self::TYPES, true)) {
            return $bad + ['message' => 'نوعِ کلید («'.mb_substr($type, 0, 30).'») پشتیبانی نمی‌شود.'];
        }

        $decoded = base64_decode($body, true);

        if ($decoded === false || strlen($decoded) < 16) {
            return $bad + ['message' => 'بدنهٔ کلید خوانا نیست؛ احتمالاً ناقص کپی شده.'];
        }

        // بدنه با نامِ نوع شروع می‌شود؛ اگر نخواند، کلید دست‌کاری شده است
        $declared = substr($decoded, 4, strlen($type));

        if ($declared !== $type) {
            return $bad + ['message' => 'کلید با نوعِ اعلام‌شده‌اش نمی‌خواند.'];
        }

        return [
            'ok'          => true,
            'message'     => '',
            'type'        => $type,
            'fingerprint' => self::fingerprintOf($decoded),
            'normalized'  => trim($type.' '.$body.' '.$comment),
        ];
    }

    /** اثرِ انگشتِ MD5 به شکلِ استانداردِ SSH — همان چیزی که زیرساخت‌ها نشان می‌دهند */
    private static function fingerprintOf(string $decodedBody): string
    {
        return implode(':', str_split(md5($decodedBody), 2));
    }

    /** شناسهٔ این کلید نزدِ یک زیرساخت (اگر قبلاً بارگذاری شده) */
    public function refFor(string $provider): ?string
    {
        $refs = (array) ($this->provider_refs ?? []);

        return filled($refs[$provider] ?? null) ? (string) $refs[$provider] : null;
    }

    /** ثبتِ شناسهٔ بارگذاری‌شده، بی‌پاک‌کردنِ شناسهٔ زیرساخت‌های دیگر */
    public function rememberRef(string $provider, string $ref): void
    {
        $refs = (array) ($this->provider_refs ?? []);
        $refs[$provider] = $ref;

        $this->update(['provider_refs' => $refs]);
    }

    /** نامِ کوتاهِ خوانا برای فهرست: «کلیدِ لپ‌تاپ (ed25519)» */
    public function label(): string
    {
        $type = str_replace(['ssh-', '@openssh.com'], '', (string) $this->key_type);

        return $this->name.($type !== '' ? ' ('.$type.')' : '');
    }
}
