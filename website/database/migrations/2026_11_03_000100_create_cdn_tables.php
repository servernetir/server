<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول‌های لبهٔ سرورنت — CDN + DNS + WAF.
 *
 * همه با پیشوندِ `cdn_` تا در بکاپ و مهاجرت از بقیه جدا بمانند.
 *
 * ⚠️ مهاجرتِ پروداکشن در این پروژه **دستی** با کلیکِ مدیر اجرا می‌شود، پس
 * همیشه پنجره‌ای هست که کد بالا رفته و جدول هنوز نیست. هر خواننده‌ای از این
 * جدول‌ها باید پشتِ `Schema::hasTable` باشد — مثلِ `/status` که همین محافظ را
 * دارد و روی سرورِ مهاجرت‌نخورده هم بالا می‌آید.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | نودها — هم لبه و هم DNS در یک جدول.
        |
        | چرا یکی: هر دو همان چرخهٔ «ثبت، توکن، ضربان، وضعیت» را دارند و
        | صفحهٔ مدیریت باید همه‌شان را کنارِ هم نشان دهد. `role` تفکیک می‌کند.
        */
        Schema::create('cdn_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();                    // sn-edge-de1
            $t->string('role')->default('edge');             // edge | dns
            $t->string('country', 2)->nullable();            // de | ir
            $t->string('label')->nullable();                 // «فرانکفورت»
            $t->string('hostname')->nullable();
            $t->string('public_ip')->nullable();
            $t->string('public_ip6')->nullable();
            $t->string('status')->default('active');         // active | drain | disabled

            /*
            | توکنِ نود **هش‌شده** ذخیره می‌شود، نه رمزنگاری‌شده.
            | ما هرگز لازم نداریم بخوانیمش — فقط باید تطبیق بدهیم. رمزنگاری
            | یعنی هر نشتِ دیتابیس + کلید، همهٔ نودها را باز می‌کند.
            */
            $t->string('token_hash')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedBigInteger('snapshot_version')->default(0);

            /*
            | 🔴 بهایِ تمام‌شدهٔ پهنای باند، میکرو-یورو بر گیگابایت.
            | بدونِ این ستون هیچ گزارشی نمی‌تواند بگوید یک zone سودده است یا نه،
            | و «ترافیکِ نامحدود» روی نودِ گران یعنی ضررِ خاموش.
            */
            $t->unsignedBigInteger('cost_per_gb_micro')->default(0);
            $t->unsignedInteger('capacity_mbps')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['role', 'status']);
        });

        Schema::create('cdn_zones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();

            /*
            | به سرویس وصل است تا چرخهٔ تعلیق/خاتمه همان مسیرِ فعلی را برود.
            | nullable چون zone می‌تواند پیش از فاکتور (حالتِ آزمایشی) ساخته شود.
            */
            $t->unsignedBigInteger('service_id')->nullable()->index();

            $t->string('domain')->unique();
            $t->string('mode')->default('ns');               // ns | cname
            $t->string('status')->default('pending');        // pending | active | suspended | disabled

            // تأییدِ مالکیت/تفویض
            $t->string('verify_token', 64)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verify_error')->nullable();
            $t->timestamp('checked_at')->nullable();         // آخرین سنجشِ تفویض، موفق یا نه

            /*
            | از کجا مالکیت ثابت شد: `registered` (دامنه نزدِ خودمان ثبت شده)،
            | `txt` (رکوردِ TXT روی DNSِ فعلی)، یا null (فقط ادعا — در انتظار).
            | zoneِ ادعاشده بی‌اثبات را می‌شود با اثبات «پس گرفت»؛ zoneِ ثابت‌شده نه.
            */
            $t->string('ownership')->nullable();

            /*
            | 🔴 وضعیتِ آینه روی PowerDNS. «ذخیره شد» در پنل ≠ «دنیا می‌بیند».
            | اگر این ستون نباشد، شکستِ همگام‌سازی فقط در یک ردیفِ رویداد دفن
            | می‌شود و مشتری رکوردی را «ثبت‌شده» می‌بیند که هیچ‌جا جواب نمی‌دهد.
            */
            $t->timestamp('synced_at')->nullable();
            $t->string('sync_error')->nullable();
            $t->boolean('dirty')->default(true);             // تغییری هست که هنوز به PowerDNS نرسیده

            /*
            | 🔴 کلیدِ پرج. کلیدِ کش با همین عدد شروع می‌شود، پس «پاک‌کردنِ کلِ
            | کش» یک `increment` است: بی‌درنگ، بدونِ fanout، و بدونِ حالتی که
            | روی یک نود موفق و روی نودِ دیگر ناموفق باشد.
            */
            $t->unsignedBigInteger('edge_epoch')->default(1);

            $t->boolean('cache_enabled')->default(true);
            $t->unsignedInteger('default_ttl')->default(3600);
            $t->string('waf_level')->default('medium');      // off|low|medium|high
            $t->string('waf_action')->default('block');      // log|challenge|block
            $t->boolean('dnssec')->default(false);
            $t->boolean('always_https')->default(true);

            $t->string('plan')->nullable();
            $t->unsignedBigInteger('quota_gb')->nullable();  // null = بی‌سهمیه (فقط برای zoneهای خودمان)

            $t->json('meta')->nullable();
            $t->timestamps();

            $t->index(['status', 'mode']);
        });

        Schema::create('cdn_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->string('type', 10);
            $t->string('name');                              // FQDN کامل، بی‌نقطهٔ پایانی
            $t->text('content');
            $t->unsignedInteger('ttl')->default(3600);
            $t->unsignedInteger('prio')->nullable();         // فقط MX/SRV

            /*
            | این پرچم تفاوتِ «DNS» با «CDN» است: proxied یعنی رکورد به IP لبه
            | پاسخ می‌دهد و مقدارِ واقعی به‌عنوان مبدأ نگه داشته می‌شود.
            */
            $t->boolean('proxied')->default(false);
            $t->boolean('disabled')->default(false);
            $t->string('comment', 120)->nullable();          // یادداشتِ مشتری؛ به DNS نمی‌رود
            $t->timestamps();

            $t->index(['cdn_zone_id', 'type', 'name']);
        });

        Schema::create('cdn_origins', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->string('host');
            $t->unsignedSmallInteger('port')->default(443);
            $t->string('scheme')->default('https');
            $t->string('host_header')->nullable();
            $t->boolean('verify_tls')->default(false);       // مبدأ معمولاً گواهیِ self-signed دارد
            $t->unsignedSmallInteger('weight')->default(1);
            $t->string('health')->default('unknown');        // unknown | up | down
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('cdn_cache_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->unsignedInteger('position')->default(0);
            $t->string('match_type')->default('path');       // path | extension | header
            $t->string('pattern');
            $t->string('action')->default('cache');          // cache | bypass
            $t->unsignedInteger('ttl')->nullable();
            $t->boolean('ignore_query')->default(false);
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->index(['cdn_zone_id', 'position']);
        });

        Schema::create('cdn_waf_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->unsignedInteger('position')->default(0);
            $t->string('kind')->default('custom');           // custom | rate_limit | geo | ip
            $t->string('label')->nullable();
            $t->text('expression')->nullable();
            $t->string('action')->default('block');          // log | challenge | block
            $t->json('params')->nullable();                  // {requests, window} برای rate_limit
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->index(['cdn_zone_id', 'position']);
        });

        /*
        | گواهی‌ها.
        |
        | PEM رمزنگاری‌شده ذخیره می‌شود (cast در مدل)، چون snapshot آن را به
        | نودها می‌دهد و نود باید کلیدِ خصوصی را داشته باشد تا TLS را ببندد.
        | نگه‌داشتنِ متنِ خام در دیتابیس یعنی هر بکاپِ لورفته، کلیدِ خصوصیِ
        | همهٔ دامنه‌های مشتریان است.
        */
        Schema::create('cdn_certificates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->string('hostname')->unique();
            $t->string('issuer')->nullable();
            $t->string('status')->default('pending');        // pending | issued | failed | expired
            $t->timestamp('not_after')->nullable();
            $t->text('last_error')->nullable();
            $t->longText('cert_pem')->nullable();
            $t->longText('key_pem')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'not_after']);
        });

        /*
        | مصرف — هم مبنای صورت‌حساب، هم نمودارِ پنل. **یک** منبع، چون دو منبع
        | یعنی روزی عددِ فاکتور با عددی که مشتری در پنل دیده نمی‌خوانَد.
        |
        | تفکیکِ نود اجباری است، نه تزئینی: بدونش نمی‌شود فهمید ترافیک از
        | نودِ ارزان رفته یا نودِ گران.
        */
        Schema::create('cdn_usage', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->index();
            $t->unsignedBigInteger('cdn_node_id')->index();
            $t->date('day');
            $t->unsignedBigInteger('bytes_out')->default(0);
            $t->unsignedBigInteger('bytes_in')->default(0);
            $t->unsignedBigInteger('requests')->default(0);
            $t->unsignedBigInteger('cache_hits')->default(0);
            $t->unsignedBigInteger('blocked')->default(0);
            $t->timestamps();

            // idempotency: نود می‌تواند یک بازه را دوباره بفرستد
            $t->unique(['cdn_zone_id', 'cdn_node_id', 'day'], 'cdn_usage_unique_day');
        });

        Schema::create('cdn_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cdn_zone_id')->nullable()->index();
            $t->unsignedBigInteger('cdn_node_id')->nullable();
            $t->string('type', 40);                          // purge | block | deploy | cert | dns
            $t->string('message')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'cdn_events', 'cdn_usage', 'cdn_certificates', 'cdn_waf_rules',
            'cdn_cache_rules', 'cdn_origins', 'cdn_records', 'cdn_zones', 'cdn_nodes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
