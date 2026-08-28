<?php

namespace App\Support;

/**
 * مولّدِ اسکریپتِ ایجنتِ RouterOS — فایلِ `.rsc`ی که مشتری روی روترش import می‌کند.
 *
 * ═══ چرا اصلاً ایجنت، و چرا «کششی» ═══
 *
 * روترِ مشتری از سمتِ ما قابلِ دسترسی نیست: `/ip service ssh` با
 * `available-from` بسته است و API خاموش. تا امروز نتیجه‌اش این بود که پنل و
 * API فقط یک **متنِ دستور** می‌دادند و انسانی باید آن را پیست می‌کرد — یعنی
 * «۲۰۱ Created» دربارهٔ چیزی حرف می‌زد که هنوز نشده بود.
 *
 * راهِ درست همان راهی است که موتورِ هاستِ ایران رفت: طرفِ غیرقابلِ‌دسترس
 * **می‌پرسد**. روتر هر ۳۰ ثانیه صف را می‌خواند، کارها را اجرا می‌کند و نتیجه
 * را برمی‌گردانَد.
 *
 * ═══ 🔴 چرا سرور «دستور» نمی‌فرستد و «داده» می‌فرستد ═══
 *
 * وسوسه‌کننده‌ترین طراحی این بود که پاسخ یک متنِ RouterOS باشد و اسکریپت
 * `[:parse $body]` بزند. آن یک خط، **کنترلِ کاملِ روترِ مشتری** را به هر کسی
 * می‌دهد که بتواند پاسخِ ما را جعل کند یا سرورِ ما را در دست بگیرد — و روترِ
 * اکسیت دقیقاً همان جایی است که ترافیکِ همهٔ کاربرانش از آن می‌گذرد.
 *
 * پس پروتکل عمداً **بی‌دستور** است: هر خط فقط سه یا چهار **مقدار** دارد
 * (`نام`، `آدرس`، `کلیدِ عمومی`)، اسکریپت هرکدام را جداگانه با فهرستِ سفید
 * می‌سنجد، و **خودش** دستور را می‌سازد. بدترین کاری که یک پاسخِ جعلی می‌تواند
 * بکند افزودنِ یک peer در همان ساب‌نت است — نه اجرای دلخواه.
 *
 * ═══ ⚠️ تلهٔ گیومه که این کلاس دورش می‌زند ═══
 *
 * `/system script add source="…"` یعنی کلِ بدنه باید escape شود، و بدتر:
 * RouterOS داخلِ رشتهٔ دوگیومه‌ای `$` را **درون‌یابی می‌کند**، پس هر `$1` و
 * `$body` در لحظهٔ افزودن جایگزین می‌شد و اسکریپتِ ذخیره‌شده چیزِ دیگری از آب
 * درمی‌آمد. با بدنهٔ داخلِ `{ }` هیچ escapeای لازم نیست و `$` دست‌نخورده
 * می‌مانَد — تنها قیدش این است که آکولادها متوازن باشند و هیچ آکولادی داخلِ
 * رشته‌های اسکریپت نیاید. `guardBraces()` همین را می‌سنجد.
 */
class TunnelAgentScript
{
    /** فاصلهٔ پیمایش. ۳۰ ثانیه یعنی مشتری تقریباً بی‌درنگ نتیجه می‌بیند. */
    public const INTERVAL = '30s';

    /** نامِ مشترکِ اسکریپت و زمان‌بند — نصبِ دوباره همین‌ها را جایگزین می‌کند. */
    public const NAME = 'snet-tunnel-agent';

    /**
     * فایلِ کاملِ `.rsc`.
     *
     * @param  string  $token   توکنِ خامِ ایجنت (فقط همین‌جا و همین یک بار)
     * @param  string  $base    ریشهٔ مسیرهای ایجنت، بی‌اسلشِ پایانی
     * @param  string  $iface   نامِ اینترفیسِ WireGuard روی روتر
     * @param  string  $prefix  سه اکتتِ ساب‌نت با نقطهٔ پایانی، مثلِ `10.10.10.`
     */
    public static function build(string $token, string $base, string $iface, string $prefix): string
    {
        $body = self::agentSource($token, $base, $iface, $prefix);

        self::guardBraces($body);

        $lines = [];
        $lines[] = '# ═══ ServerNet — ایجنتِ تونلِ روتر ═══';
        $lines[] = '# این فایل یک اسکریپت و یک زمان‌بند می‌سازد که هر '.self::INTERVAL;
        $lines[] = '# صفِ اکانت‌های پنل را می‌خوانَد و peerها را روی همین روتر اعمال می‌کند.';
        $lines[] = '#';
        $lines[] = '# اجرا:  /import file-name=snet-agent.rsc';
        $lines[] = '';

        /*
        | گواهیِ ریشه — بی‌آن `check-certificate=yes-without-crl` همیشه شکست
        | می‌خورد و ایجنت بی‌صدا هیچ‌وقت وصل نمی‌شود. RouterOS با انبارِ خالی
        | می‌آید، پس این‌جا یک بار پرش می‌کنیم.
        |
        | 🔴 همین یک fetch عمداً `check-certificate=no` است: مرغ و تخم‌مرغ —
        | برای راستی‌آزماییِ اولین دانلود هنوز گواهی‌ای نداریم. یک عملِ نصبِ
        | یک‌بارهٔ آگاهانه است، در برابرِ هر پیمایشِ بعدی که راستی‌آزمایی‌شده است.
        | اگر شکست بخورد، `import` همان‌جا پیام می‌دهد — نه بعداً و نه بی‌صدا.
        */
        $lines[] = ':if ([:len [/certificate/find]] < 5) do={';
        $lines[] = '  :put "ServerNet: importing CA bundle (one time)...";';
        $lines[] = '  :do {';
        $lines[] = '    /tool fetch url="https://curl.se/ca/cacert.pem" dst-path="cacert.pem" check-certificate=no;';
        $lines[] = '    :delay 2s;';
        $lines[] = '    /certificate import file-name="cacert.pem" passphrase="";';
        $lines[] = '  } on-error={ :put "ServerNet: CA import FAILED - agent cannot verify TLS"; };';
        $lines[] = '}';
        $lines[] = '';

        // نصبِ دوباره باید بی‌خطر باشد: ردیفِ قبلی می‌رود، وگرنه دو زمان‌بند
        // هم‌زمان می‌دوند و هر کار دو بار برداشته می‌شود.
        $lines[] = '/system scheduler remove [find name="'.self::NAME.'"];';
        $lines[] = '/system script remove [find name="'.self::NAME.'"];';
        $lines[] = '';
        $lines[] = '/system script add name="'.self::NAME.'" policy=read,write,test dont-require-permissions=no source={'.$body.'};';
        $lines[] = '';

        /*
        | `start-time=startup` تزئینی نیست: در همین پروژه ثابت شد که
        | `autorun.scr` فقط **یک بار** اجرا می‌شود و نه در هر بوت — و همان
        | تفاوت، ساعت‌ها عیب‌یابیِ اشتباه ساخت. زمان‌بند با startup تنها شکلی
        | است که بعد از خاموش‌روشنِ روتر خودش برمی‌گردد.
        */
        $lines[] = '/system scheduler add name="'.self::NAME.'" interval='.self::INTERVAL.' start-time=startup policy=read,write,test on-event="/system script run '.self::NAME.'";';
        $lines[] = '';
        /*
        | 🔴 اثرِ انگشت به‌جای شمارهٔ نسخهٔ دستی.
        |
        | نسخهٔ قبلی رشتهٔ `v2` را دستی در متن داشت و در اولین اصلاحِ بعدی
        | به‌روز نشد. نتیجه: روترِ مشتری اسکریپتِ **درست** را گرفته بود ولی
        | بنر می‌گفت `v2`، و ما یک دورِ کاملِ عیب‌یابی را صرفِ چیزی کردیم که
        | از اول سالم بود. شماره‌ای که آدم نگهش می‌دارد، روزی دروغ می‌گوید.
        |
        | این هش از خودِ بدنه ساخته می‌شود، پس هر تغییرِ واقعی خودبه‌خود
        | عددِ تازه می‌دهد و هیچ‌وقت کهنه نمی‌مانَد. مقایسهٔ آنچه روتر چاپ
        | می‌کند با خروجیِ `TunnelAgentScript::fingerprint()` یک جوابِ قطعی
        | می‌دهد: «همین نسخه است» یا «نیست».
        */
        $lines[] = ':put "ServerNet: agent installed ['.self::fingerprint($token, $base, $iface, $prefix).']. First run within '.self::INTERVAL.'.";';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * اثرِ انگشتِ همین اسکریپت — همان چیزی که روتر موقعِ نصب چاپ می‌کند.
     *
     * ⚠️ توکن عمداً **در محاسبه نیست**: دو روتر با کدِ یکسان و توکنِ متفاوت
     * باید عددِ یکسان بدهند، وگرنه مقایسه بی‌معنی می‌شود.
     */
    public static function fingerprint(string $token, string $base, string $iface, string $prefix): string
    {
        return substr(sha1(str_replace($token, '', self::agentSource($token, $base, $iface, $prefix))), 0, 8);
    }

    /**
     * بدنهٔ اسکریپت.
     *
     * ⚠️ هیچ رشته‌ای در این متن نباید `{` یا `}` داشته باشد — پارسرِ RouterOS
     * آکولادها را پیش از رشته‌ها می‌شمارد و یک آکولادِ داخلِ گیومه بدنه را
     * وسط قطع می‌کند. `guardBraces()` این را در زمانِ ساخت می‌سنجد تا خرابی
     * این‌جا پیدا شود، نه روی روترِ مشتری.
     */
    private static function agentSource(string $token, string $base, string $iface, string $prefix): string
    {
        $s = [];

        $s[] = '';
        $s[] = ':local tag "SNET-agent";';
        $s[] = ':local base "'.$base.'";';
        $s[] = ':local token "'.$token.'";';
        $s[] = ':local iface "'.$iface.'";';
        $s[] = ':local prefix "'.$prefix.'";';
        $s[] = '';

        // ── کمک‌تابع‌ها ───────────────────────────────────────────────
        // ⚠️ بدنهٔ do={} یک scopeِ تازه است و locals بیرونی را **نمی‌بیند**؛
        //    هر چیزی که لازم دارند باید آرگومان باشد. بلوک‌های :if/:while
        //    برعکس‌اند و scope را به ارث می‌برند.

        $s[] = ':local fld do={';
        $s[] = '  :local s $1; :local want $2; :local p 0; :local n [:len $s]; :local i 0;';
        $s[] = '  :while ($i <= $want) do={';
        $s[] = '    :local e [:find $s "|" $p];';
        $s[] = '    :if ([:typeof $e] != "num") do={ :set e $n; }';
        $s[] = '    :if ($i = $want) do={ :return [:pick $s $p $e]; }';
        $s[] = '    :set p ($e + 1); :set i ($i + 1);';
        $s[] = '    :if ($p > $n) do={ :return ""; }';
        $s[] = '  }';
        $s[] = '  :return "";';
        $s[] = '}';
        $s[] = '';

        /*
        | 🔴 چرا اعتبارسنجی **تابع نیست** — یک باگِ واقعیِ همین فیچر
        |
        | نسخهٔ اول یک کمک‌تابعِ عمومی داشت که بازهٔ طول را به‌عنوانِ آرگومان
        | می‌گرفت: `[$inset $nm "a-z0-9-_" 2 24]`. روی روترِ واقعی **هر نامی
        | با طولِ دو رقمی رد می‌شد**: RouterOS آرگومانِ عددیِ برهنه را رشته
        | تحویل می‌دهد، پس `[:len $s] < $lo` می‌شد `11 < "2"` و مقایسهٔ
        | رشته‌ای `"11" < "2"` **درست** است. نه خطایی، نه لاگی — فقط یک
        | «rejected» که شبیهِ ردِ امنیتیِ عمدی به‌نظر می‌رسید.
        |
        | پس هیچ مقداری دیگر از مرزِ تابع رد نمی‌شود: همه‌چیز درون‌خطی و در
        | همان scope است، با `:set` به‌جای `:return`. عددها هم دیگر آرگومان
        | نیستند بلکه در همین متن ثابت‌اند.
        |
        | ⚠️ و هر ردِ اعتبارسنجی **علتِ خودش** را لاگ می‌کند، همراهِ طولِ
        | رشته. «rejected»ِ بی‌جزئیات یعنی دورِ بعدیِ عیب‌یابی هم حدس‌زدن باشد.
        |
        | ═══ 🔴 آرگومانِ سومِ `:find` شمولی **نیست** ═══
        |
        | `[:find $charset $char 0]` از **بعدِ** اندیسِ صفر می‌گردد، پس نویسه‌ای
        | که دقیقاً در ابتدای فهرست نشسته هرگز پیدا نمی‌شود. مثالِ خودِ
        | مستنداتِ میکروتیک `[:find "abc" "a" -1]` است و همان `-1` تنها نشانهٔ
        | این رفتار بود.
        |
        | اثرش روی روترِ واقعی: فهرستِ نام با `a` شروع می‌شد و اولین اکانتِ
        | آزمایشی `agent-smoke` نام داشت ⇒ «نامِ نامعتبر». فهرستِ کلید هم با
        | `A` شروع می‌شد ⇒ هر کلیدی که یک `A` داشت رد می‌شد. یعنی خرابی هم
        | قطعی به‌نظر می‌رسید هم تصادفی، و هیچ خطایی تولید نمی‌کرد.
        |
        | پس شکلِ **دوآرگومانی** استفاده می‌شود که همیشه از ابتدا و شمولی
        | می‌گردد. `fld` عمداً دست‌نخورده ماند: آن‌جا آغازِ جست‌وجو همیشه بعد
        | از جداکنندهٔ مصرف‌شده است و رفتارِ انحصاری دقیقاً همان چیزی است که
        | لازم دارد.
        */
        // ── ۱) گرفتنِ صف ────────────────────────────────────────────
        $s[] = ':local body ""; :local ok true;';
        $s[] = ':do {';
        $s[] = '  :set body ([/tool fetch url=($base . "/pending") http-method=get http-header-field=("X-Agent-Token: " . $token) check-certificate=yes-without-crl output=user as-value]->"data");';
        $s[] = '} on-error={ :log warning ($tag . ": fetch failed"); :set ok false; }';
        $s[] = '';

        /*
        | 🔴 هر پاسخی که با امضای پروتکل شروع نشود **دور ریخته می‌شود**.
        | صفحهٔ خطای Cloudflare، صفحهٔ نگه‌داری، یا یک ریدایرکتِ HTML همگی با
        | کدِ ۲۰۰ می‌آیند؛ بی‌این سنجه، پارسر خطوطشان را «کار» می‌خواند.
        */
        $s[] = ':if ($ok) do={';
        $s[] = '  :if ([:pick $body 0 7] != "SNET|1|") do={ :log warning ($tag . ": unexpected reply"); :set ok false; }';
        $s[] = '}';
        $s[] = '';

        // ── ۲) اجرای کارها ───────────────────────────────────────────
        /*
        | ⚠️ همهٔ `:local`ها **بیرونِ** حلقه‌اند و داخلش فقط `:set` است.
        | اعلامِ دوبارهٔ یک نام در همان scope روی RouterOS می‌تواند خطا بدهد،
        | و آن خطا وسطِ حلقه کلِ اسکریپت را می‌کُشد — یعنی خرابی فقط وقتی
        | ظاهر می‌شود که بیش از یک کار در یک نوبت باشد. دقیقاً همان جنسِ
        | باگی که آزمونِ تک‌کاره هرگز نمی‌بیندش.
        */
        $s[] = ':if ($ok) do={';
        $s[] = '  :local okids ""; :local badids ""; :local pos 0; :local blen [:len $body]; :local first true;';
        $s[] = '  :local nl 0; :local line ""; :local op ""; :local jid ""; :local nm ""; :local good false;';
        $s[] = '  :local named true; :local keyed true; :local iped true;';
        $s[] = '  :local adr ""; :local pk ""; :local tail ""; :local ex "";';
        $s[] = '  :local plen [:len $prefix];';
        $s[] = '  :while ($pos < $blen) do={';
        $s[] = '    :set nl [:find $body "\n" $pos];';
        $s[] = '    :if ([:typeof $nl] != "num") do={ :set nl $blen; }';
        $s[] = '    :set line [:pick $body $pos $nl];';
        $s[] = '    :set pos ($nl + 1);';
        $s[] = '    :if ($first) do={ :set first false; } else={';
        $s[] = '      :if ([:len $line] > 3) do={';
        $s[] = '        :set op [$fld $line 0];';
        $s[] = '        :set jid [$fld $line 1];';
        $s[] = '        :set nm [$fld $line 2];';
        $s[] = '        :set good false;';
        // نامِ مجاز: ۲ تا ۲۴ نویسه از فهرستِ سفید.
        $s[] = '        :set named true;';
        $s[] = '        :if ([:len $nm] < 2) do={ :set named false; }';
        $s[] = '        :if ([:len $nm] > 24) do={ :set named false; }';
        $s[] = '        :if ([:len $nm] > 0) do={';
        $s[] = '          :for k from=0 to=([:len $nm] - 1) do={';
        $s[] = '            :if ([:typeof [:find "abcdefghijklmnopqrstuvwxyz0123456789-_" [:pick $nm $k ($k + 1)]]] != "num") do={ :set named false; }';
        $s[] = '          }';
        $s[] = '        }';
        $s[] = '        :if (!$named) do={ :log warning ($tag . ": bad name " . $nm . " len=" . [:len $nm]); }';
        $s[] = '        :if ($op = "ADD") do={';
        $s[] = '          :set adr [$fld $line 3];';
        $s[] = '          :set pk [$fld $line 4];';
        // کلیدِ عمومیِ WireGuard دقیقاً ۴۴ نویسهٔ base64 است.
        $s[] = '          :set keyed true;';
        $s[] = '          :if ([:len $pk] != 44) do={ :set keyed false; }';
        $s[] = '          :if ([:len $pk] > 0) do={';
        $s[] = '            :for k from=0 to=([:len $pk] - 1) do={';
        $s[] = '              :if ([:typeof [:find "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=" [:pick $pk $k ($k + 1)]]] != "num") do={ :set keyed false; }';
        $s[] = '            }';
        $s[] = '          }';
        $s[] = '          :if (!$keyed) do={ :log warning ($tag . ": bad key " . $nm . " len=" . [:len $pk]); }';
        /*
        | آدرس باید در همان /24ِ همین سرویس و در بازهٔ میزبانِ معتبر باشد.
        | بی‌این سنجه، یک پاسخِ جعلی می‌توانست peerی با `allowed-address`
        | برابرِ 0.0.0.0/0 بسازد و کلِ ترافیکِ روتر را به خودش بکشد.
        */
        $s[] = '          :set iped true;';
        $s[] = '          :if ([:pick $adr 0 $plen] != $prefix) do={ :set iped false; }';
        $s[] = '          :set tail [:pick $adr $plen [:len $adr]];';
        $s[] = '          :if ([:len $tail] < 1) do={ :set iped false; }';
        $s[] = '          :if ([:len $tail] > 3) do={ :set iped false; }';
        $s[] = '          :if ([:len $tail] > 0) do={';
        $s[] = '            :for k from=0 to=([:len $tail] - 1) do={';
        $s[] = '              :if ([:typeof [:find "0123456789" [:pick $tail $k ($k + 1)]]] != "num") do={ :set iped false; }';
        $s[] = '            }';
        $s[] = '          }';
        $s[] = '          :if ($iped) do={';
        $s[] = '            :if ([:tonum $tail] < 2) do={ :set iped false; }';
        $s[] = '            :if ([:tonum $tail] > 254) do={ :set iped false; }';
        $s[] = '          }';
        $s[] = '          :if (!$iped) do={ :log warning ($tag . ": bad ip " . $adr); }';
        $s[] = '          :if ($named && $keyed && $iped) do={';
        $s[] = '            :do {';
        /*
        | 🔴 دامنهٔ اثر با `comment="snet-api"` بسته می‌شود.
        |
        | روترِ مشتری فقط مالِ ما نیست: او سامانهٔ خودش را هم دارد که روی همان
        | اینترفیس peer می‌سازد. بی‌این قید، یک **تصادفِ نام** کافی بود تا
        | `set` کلیدِ عمومیِ peerِ او را با کلیدِ ما بازنویسی کند — یعنی کاربرِ
        | او بی‌هیچ خطایی قطع شود و هیچ‌کس نفهمد چرا.
        |
        | حالا اگر نامی تصادم کند، `find` چیزی برنمی‌گرداند، شاخهٔ `add` اجرا
        | می‌شود و RouterOS «نامِ تکراری» خطا می‌دهد ⇒ کار **با صدا** شکست
        | می‌خورد و در پنل `failed` دیده می‌شود. شکستِ پرصدا از بازنویسیِ
        | خاموش بی‌نهایت بهتر است.
        |
        | ⚠️ همین قید روی حذف هم هست: این ایجنت هرگز نمی‌تواند peerی را که
        | خودش نساخته پاک کند.
        |
        | و idempotency دست‌نخورده می‌مانَد: تلاشِ دوباره پس از ackِ گم‌شده
        | همان peerِ «snet-api» را پیدا و `set` می‌کند.
        */
        $s[] = '              :set ex [/interface/wireguard/peers/find name=$nm comment="snet-api"];';
        $s[] = '              :if ([:len $ex] > 0) do={';
        $s[] = '                /interface/wireguard/peers/set $ex interface=$iface public-key=$pk allowed-address=($adr . "/32") disabled=no;';
        $s[] = '              } else={';
        $s[] = '                /interface/wireguard/peers/add interface=$iface name=$nm public-key=$pk allowed-address=($adr . "/32") comment="snet-api";';
        $s[] = '              }';
        $s[] = '              :set good true;';
        $s[] = '            } on-error={ :log warning ($tag . ": add failed " . $nm); }';
        $s[] = '          }';
        $s[] = '        }';
        $s[] = '        :if ($op = "DEL") do={';
        $s[] = '          :if ($named) do={';
        $s[] = '            :do {';
        // peerِ ازقبل‌نبود = موفق. همان قاعدهٔ `terminate()`ِ پروژه: حذفِ چیزی
        // که نیست، هدفِ کار را برآورده کرده است.
        $s[] = '              :set ex [/interface/wireguard/peers/find name=$nm comment="snet-api"];';
        $s[] = '              :if ([:len $ex] > 0) do={ /interface/wireguard/peers/remove $ex; }';
        $s[] = '              :set good true;';
        $s[] = '            } on-error={ :log warning ($tag . ": del failed " . $nm); }';
        $s[] = '          }';
        $s[] = '        }';
        $s[] = '        :if ($good) do={';
        $s[] = '          :if ([:len $okids] > 0) do={ :set okids ($okids . ","); }';
        $s[] = '          :set okids ($okids . $jid);';
        $s[] = '        } else={';
        $s[] = '          :if ([:len $badids] > 0) do={ :set badids ($badids . ","); }';
        $s[] = '          :set badids ($badids . $jid);';
        $s[] = '        }';
        $s[] = '      }';
        $s[] = '    }';
        $s[] = '  }';
        $s[] = '';
        // ── ۳) گزارش ─────────────────────────────────────────────────
        // ⚠️ گزارش فقط وقتی می‌رود که کاری انجام شده باشد. پیمایشِ خالی نباید
        //    درخواستِ دوم بسازد — با ۳۰ ثانیه فاصله، آن یعنی دو برابرِ ترافیک
        //    برای هیچ.
        $s[] = '  :if (([:len $okids] > 0) || ([:len $badids] > 0)) do={';
        $s[] = '    :do {';
        $s[] = '      /tool fetch url=($base . "/ack") http-method=post http-header-field=("X-Agent-Token: " . $token . ",Content-Type: application/x-www-form-urlencoded") http-data=("ok=" . $okids . "&fail=" . $badids) check-certificate=yes-without-crl output=user as-value;';
        $s[] = '    } on-error={ :log warning ($tag . ": ack failed"); }';
        $s[] = '  }';
        $s[] = '}';
        $s[] = '';

        return implode("\n", $s);
    }

    /**
     * 🔴 آکولادها باید متوازن باشند و هیچ‌کدام داخلِ رشته نباشند.
     *
     * اگر این شرط بشکند، فایل روی روترِ مشتری import می‌شود، نیمی از بدنه
     * به‌عنوانِ اسکریپت ذخیره می‌شود و نیمِ دیگر به‌عنوانِ **فرمانِ سطحِ بالا**
     * اجرا می‌گردد. یعنی خرابیِ اینجا روی روترِ کسِ دیگری ظاهر می‌شود — پس
     * همین‌جا باید بترکد، نه آن‌جا.
     */
    private static function guardBraces(string $body): void
    {
        $depth = 0;
        $inStr = false;
        $len = strlen($body);

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($ch === '\\') {
                $i++;                       // نویسهٔ فرارشده هرگز ساختاری نیست

                continue;
            }

            if ($ch === '"') {
                $inStr = ! $inStr;

                continue;
            }

            if ($inStr) {
                if ($ch === '{' || $ch === '}') {
                    throw new \LogicException('TunnelAgentScript: brace inside a string literal at offset '.$i);
                }

                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;

                if ($depth < 0) {
                    throw new \LogicException('TunnelAgentScript: unbalanced closing brace at offset '.$i);
                }
            }
        }

        if ($depth !== 0) {
            throw new \LogicException('TunnelAgentScript: '.$depth.' unclosed brace(s)');
        }

        if ($inStr) {
            throw new \LogicException('TunnelAgentScript: unterminated string literal');
        }
    }
}