<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مدرک احراز هویت. فایل بیرون webroot است؛ دانلود فقط با URL امضاشده. */
class CustomerDocument extends Model
{
    protected $fillable = [
        'customer_profile_id', 'kind', 'status', 'requested_note',
        'disk_path', 'original_name', 'mime', 'size_bytes', 'sha256',
        'scan_status', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }

    /** تا اسکن ویروس تمام نشده، فایل نباید سرو شود */
    public function isDownloadable(): bool
    {
        return $this->scan_status === 'clean';
    }
}
