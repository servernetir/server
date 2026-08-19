<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| تلفن ابری «دفتر شما» — دو جدول، نه یکی
|==============================================================================
|
| 🔴 چرا دو جدول و نه یکی: در یک تماس فیزیکی، `CallId` بین رویدادها **عوض
| می‌شود**. با ۱۰ رویداد واقعی (۱۸ آگوست) ثابت شد:
|
|   CallReferenceId = SBC2746…@10.102.166.68:5060      ← ثابت در هر ۵ رویداد
|     CallIncomingStarted            CallId = SBC2746…
|     CallIncomingTransferStarted    CallId = 1a193119-…   ← پایِ تازه
|     CallIncomingTransferCompleted  CallId = 1a193119-…
|     CallIncomingEnded              CallId = 1a193119-…   ← پایانِ پایِ انتقال
|     CallIncomingEnded              CallId = SBC2746…     ← پایانِ پایِ اصلی
|
| یعنی یک تماس **دو تا** `Ended` می‌دهد. اگر روی یک جدول upsert می‌کردیم،
| رویداد دوم اولی را رونویسی می‌کرد و «مدت تماس» بی‌صدا غلط می‌شد.
|
|   phone_call_events  یک ردیف به ازای هر وبهوک   (تاریخِ خام، هرگز بازنویسی نمی‌شود)
|   phone_calls        یک ردیف به ازای هر مکالمه  (نتیجهٔ جمع‌بندی، بازساختنی)
|
| `phone_calls` همیشه از `phone_call_events` **بازساخته** می‌شود. اگر منطقِ
| جمع‌بندی عوض شد، جدولِ دوم دور ریختنی است و داده از دست نمی‌رود.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_call_events', function (Blueprint $table) {
            $table->id();

            /*
            | کلیدِ idempotency. در هر ۱۰ رویدادِ نمونه `ClientReferenceId`
            | **یکتا** بود — یعنی شناسهٔ رویداد است، نه شناسهٔ تماس.
            |
            | ⚠️ اگر روزی نیامد، ingestor یک هشِ قطعی از payload می‌سازد؛ پس این
            | ستون هرگز null نیست و unique امن است.
            */
            $table->string('event_id', 64)->unique();

            $table->string('call_reference_id', 191)->index();

            // 🔴 uuid نگیر: گاهی UUID است و گاهی رشتهٔ SIP با @ و پورت (۵۴ کاراکتر)
            $table->string('call_id', 191)->index();

            $table->string('event_type', 48)->index();

            // ⚠️ دو فرمتِ متفاوت در یک API — WebhookPayload هر دو را می‌خوانَد
            $table->dateTime('occurred_at')->index();

            $table->string('caller_number', 32)->nullable();
            $table->string('callee_extension', 32)->nullable();
            $table->string('transferred_to_number', 32)->nullable();

            // شکلِ نرمال‌شده برای تطبیقِ مشتری — روی همین ایندکس می‌زنیم، نه خام
            $table->string('caller_number_norm', 24)->nullable()->index();

            $table->boolean('result')->nullable();

            $table->string('call_entry_type', 32)->nullable();
            $table->string('final_handler', 32)->nullable();

            // ⚠️ MenuInput گاهی رقم است («1») و گاهی جملهٔ فارسی («عدم ورودی») —
            //    هرگز int نگیر
            $table->string('menu_name', 120)->nullable();
            $table->string('menu_input', 120)->nullable();

            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Portal | Api | … — احتمالاً کلیدِ تفکیکِ تماس‌های خودمان
            $table->string('initiation_source', 32)->nullable();

            // payload خام و دست‌نخورده. اگر فردا فیلدی اضافه کنند، از دست نمی‌رود.
            $table->json('payload');

            $table->dateTime('received_at');
            $table->timestamps();

            // «رویدادهای این تماس، به ترتیب زمان» — پرتکرارترین پرس‌وجوی بازسازی
            $table->index(['call_reference_id', 'occurred_at'], 'pce_ref_time_idx');
        });

        Schema::create('phone_calls', function (Blueprint $table) {
            $table->id();

            $table->string('call_reference_id', 191)->unique();

            $table->string('direction', 12)->index();          // incoming | outgoing | unknown

            $table->string('caller_number', 32)->nullable();
            $table->string('callee_extension', 32)->nullable();
            $table->string('transferred_to_number', 32)->nullable();
            $table->string('caller_number_norm', 24)->nullable()->index();

            /*
            | تطبیقِ مشتری.
            |
            | 🔴 `match_confidence` عمداً ذخیره می‌شود و از روی وجودِ customer_id
            | استنتاج نمی‌شود. شمارهٔ ثابت **بدون پیش‌شماره** می‌آید
            | (`34261000` در حالی که شمارهٔ کاملِ خودمان `02171057757` است و
            | `71057757` تحویل داده می‌شود) — پس یک تطبیقِ ثابت هیچ‌وقت به
            | قطعیتِ تطبیقِ موبایل نیست و رابط کاربری باید این را نشان دهد.
            |
            |   exact  موبایل، دقیقاً یک مشتری
            |   local  ثابتِ بی‌پیش‌شماره، دقیقاً یک مشتری  ← با احتیاط نشان بده
            |   none   پیدا نشد
            |   many   بیش از یکی خورد ⇒ عمداً هیچ‌کدام وصل نمی‌شود
            */
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_confidence', 12)->default('none')->index();

            $table->dateTime('started_at')->nullable()->index();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            /*
            | 🟡 `answered` از فیلدِ `Result` استنتاج می‌شود و **مستند نشده**.
            | شواهدِ سه نمونه: انتقالِ موفق ⇒ true/true/true، انتقالِ بی‌پاسخ ⇒
            | true/false/false، خروجیِ ۲ ثانیه‌ای ⇒ false.
            |
            | ⚠️ nullable است تا «نمی‌دانیم» با «پاسخ داده نشد» یکی نشود —
            | وگرنه تماسی که هنوز `Ended` نگرفته، تماسِ از‌دست‌رفته شمرده می‌شود
            | و تیکتِ الکی می‌سازد.
            */
            $table->boolean('answered')->nullable()->index();

            $table->boolean('was_transferred')->default(false);
            $table->unsignedTinyInteger('legs')->default(1);

            $table->string('entry_type', 32)->nullable();
            $table->string('final_handler', 32)->nullable();
            $table->string('menu_name', 120)->nullable();
            $table->string('menu_input', 120)->nullable();
            $table->string('initiation_source', 32)->nullable();

            // از CDR پر می‌شود، نه از وبهوک — payloadِ وبهوک شناسهٔ ضبط ندارد
            $table->uuid('recording_id')->nullable();
            $table->dateTime('recording_checked_at')->nullable();

            $table->dateTime('last_event_at')->nullable();
            $table->unsignedSmallInteger('event_count')->default(0);
            $table->timestamps();

            // «تماس‌های از‌دست‌رفتهٔ اخیر» — صفحهٔ اولِ پنل پشتیبانی
            $table->index(['answered', 'started_at'], 'pc_missed_idx');
            $table->index(['customer_id', 'started_at'], 'pc_customer_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_calls');
        Schema::dropIfExists('phone_call_events');
    }
};
