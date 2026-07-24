<?php

namespace App\Services\Ticket;

use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پیوست تیکت — ذخیره و سرو با یک قاعدهٔ واحد.
 *
 * چرا سرویس: هم مشتری و هم کارمند فایل می‌فرستند؛ قاعدهٔ اعتبارسنجی، محلِ
 * ذخیره (بیرون webroot)، و نامِ تصادفی باید یک‌جا باشد تا یکی جا نیفتد.
 */
class AttachmentService
{
    public const MAX_FILES = 5;
    public const MAX_KB    = 5120;                 // ۵ مگابایت هر فایل
    private const DISK      = 'local';             // storage/app — بیرون webroot
    private const DIR       = 'ticket-attachments';

    /** فقط تصویر و PDF — نه اجراشدنی، نه هر چیز دیگر */
    public const MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ];

    /**
     * قواعد اعتبارسنجی برای فیلد attachments — در هر دو کنترلر یکی.
     *
     * @return array<string,mixed>
     */
    public static function rules(): array
    {
        return [
            'attachments'   => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => [
                'file',
                'mimetypes:'.implode(',', self::MIMES),
                'max:'.self::MAX_KB,
            ],
        ];
    }

    /**
     * ذخیرهٔ فایل‌های یک پیام.
     *
     * @param  array<int,UploadedFile>  $files
     */
    public function store(TicketMessage $message, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            // نام تصادفیِ ذخیره — نامِ اصلیِ کاربر هرگز مسیر نمی‌سازد
            // (جلوگیری از path traversal و برخورد نام)
            $path = $file->store(self::DIR, self::DISK);

            TicketAttachment::create([
                'ticket_id'         => $message->ticket_id,
                'ticket_message_id' => $message->id,
                'disk'              => self::DISK,
                'path'              => $path,
                'original_name'     => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime'              => $file->getClientMimeType(),
                'size'              => $file->getSize(),
            ]);
        }
    }

    /** پاسخِ استریمِ دانلود — با نامِ اصلی و نوعِ درست */
    public function download(TicketAttachment $att): StreamedResponse
    {
        abort_unless(Storage::disk($att->disk)->exists($att->path), 404);

        // تصویر/PDF درون‌خطی باز شود؛ بقیه دانلود
        $inline = $att->isImage() || $att->isPdf();

        return Storage::disk($att->disk)->response($att->path, $att->original_name, [
            'Content-Type'        => $att->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($att->original_name).'"',
        ]);
    }
}
