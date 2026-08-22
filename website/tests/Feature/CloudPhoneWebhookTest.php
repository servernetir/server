<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\PhoneCallEvent;
use App\Services\CloudPhone\CallIngestor;
use App\Services\CloudPhone\WebhookPayload;
use App\Support\IranianMobile;
use App\Support\IranianPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تلفن ابری — پارسِ payload، ثبتِ رویداد، جمع‌بندیِ مکالمه، و روتِ وبهوک.
 *
 * ⚠️ fixtureها **رونوشتِ دقیقِ** ۱۰ رویدادِ واقعیِ ثبت‌شده در ۱۸ آگوست ۲۰۲۶
 * هستند، نه دادهٔ ساختگی. تستی که روی دادهٔ خیالی سبز باشد فقط ثابت می‌کند کد
 * با تصورِ ما می‌خوانَد — نه با آنچه تأمین‌کننده واقعاً می‌فرستد.
 */
class CloudPhoneWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cp-7830dc7305a11bd736f1f5ad412bd470';

    private const PROVIDER_IP = '93.118.115.48';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloud_phone.webhook_token', self::TOKEN);
        config()->set('services.cloud_phone.webhook_ips', [self::PROVIDER_IP]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // fixtureهای واقعی
    // ══════════════════════════════════════════════════════════════════════

    /** مکالمهٔ اول — IVR، انتقالِ موفق، **دو** رویدادِ Ended (یکی به ازای هر پا) */
    private function conversationOne(): array
    {
        $ref = 'SBC27462693380cd96b24eb23aa464f6deb@10.102.166.68:5060';

        return [
            [
                'EventType' => 'CallIncomingStarted',
                'CallId' => $ref,
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '2026-08-18T11:40:17.1243237Z',
                'ClientReferenceId' => '093838c2-84fc-42af-896a-5efe071bc6c8',
                'CallEntryType' => 'Ivr',
                'StartDateTime' => '2026-08-18T11:40:16.7512574Z',
                'MenuName' => '', 'MenuInput' => '',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingTransferStarted',
                'CallId' => '1a193119-4f84-4ce3-8607-9c5dcb42d219',
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '08/18/2026 11:40:40',          // 🔴 فرمتِ دوم
                'TransferredToNumber' => '09142223343',
                'Result' => true,
                'ClientReferenceId' => 'c0cc238e-315e-4720-986a-80cd95308e40',
                'MenuName' => 'منوی اصلی', 'MenuInput' => '1',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingTransferCompleted',
                'CallId' => '1a193119-4f84-4ce3-8607-9c5dcb42d219',
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '08/18/2026 11:40:48',
                'TransferredToNumber' => '09142223343',
                'Result' => true,
                'ClientReferenceId' => '081fa5d8-48cd-4427-afee-dcdcf042a72d',
                'MenuName' => 'منوی اصلی', 'MenuInput' => '1',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingEnded',
                'CallId' => '1a193119-4f84-4ce3-8607-9c5dcb42d219',
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '2026-08-18T11:41:03.5466749Z',
                'TransferredToNumber' => '09142223343',
                'Result' => true,
                'ClientReferenceId' => '32fe5cc8-ce0f-4135-af54-745b880fbaeb',
                'CallEntryType' => 'Ivr',
                'StartDateTime' => '2026-08-18T11:40:16.7512574Z',
                'EndDateTime' => '2026-08-18T11:41:03.5466751Z',
                'FinalHandler' => 'IVR',
                'MenuName' => 'منوی اصلی', 'MenuInput' => '1',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingEnded',
                'CallId' => $ref,
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '2026-08-18T11:41:06.7098941Z',
                'TransferredToNumber' => '09142223343',
                'Result' => true,
                'ClientReferenceId' => '750cf95e-26c3-4d6f-9a0a-480665c60eef',
                'CallEntryType' => 'Ivr',
                'StartDateTime' => '2026-08-18T11:40:16.7512574Z',
                'EndDateTime' => '2026-08-18T11:41:06.7098943Z',
                'FinalHandler' => 'IVR',
                'MenuName' => 'منوی اصلی', 'MenuInput' => '1',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
        ];
    }

    /** مکالمهٔ دوم — انتقال شروع شد ولی کسی جواب نداد (`Result` نهایی false) */
    private function conversationTwo(): array
    {
        $ref = 'SBC4d133b1731bbd4fb5f9c7c5e077052e1@10.102.166.68:5060';

        return [
            [
                'EventType' => 'CallIncomingStarted',
                'CallId' => $ref,
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '2026-08-18T11:41:10.1746124Z',
                'ClientReferenceId' => 'd1f449bb-7aa5-4fe0-b5cb-c1fa6005216d',
                'CallEntryType' => 'Ivr',
                'StartDateTime' => '2026-08-18T11:41:09.7999532Z',
                'MenuName' => '', 'MenuInput' => '',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingTransferStarted',
                'CallId' => '0f00ae2f-3e54-469a-9a94-b74f120c6b91',
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '08/18/2026 11:42:08',
                'TransferredToNumber' => '09142223343',
                'Result' => true,
                'ClientReferenceId' => 'bc0e701f-5f66-4f4e-9a32-4151fdb27573',
                'MenuName' => 'منوی اصلی', 'MenuInput' => 'عدم ورودی',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingTransferCompleted',
                'CallId' => '0f00ae2f-3e54-469a-9a94-b74f120c6b91',
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '08/18/2026 11:42:48',
                'TransferredToNumber' => '09142223343',
                'Result' => false,
                'ClientReferenceId' => '2925bb9a-76ed-4a6e-9029-885bc4c9cecc',
                'MenuName' => 'منوی اصلی', 'MenuInput' => 'عدم ورودی',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
            [
                'EventType' => 'CallIncomingEnded',
                'CallId' => $ref,
                'CallerNumber' => '34261000',
                'CalleeExtension' => '71057757',
                'DateTime' => '2026-08-18T11:42:56.1548331Z',
                'TransferredToNumber' => '09142223343',
                'Result' => false,
                'ClientReferenceId' => '9faf8ac6-abaf-4878-89b3-851f6401229d',
                'CallEntryType' => 'Ivr',
                'StartDateTime' => '2026-08-18T11:41:09.7999532Z',
                'EndDateTime' => '2026-08-18T11:42:56.1548333Z',
                'FinalHandler' => 'IVR',
                'MenuName' => 'منوی اصلی', 'MenuInput' => '',
                'CallReferenceId' => $ref, 'Recipients' => [], 'Parameters' => [],
            ],
        ];
    }

    private function outgoingEvent(): array
    {
        return [
            'EventType' => 'CallOutgoingEnded',
            'CallId' => 'd149f9c4-349d-4078-a0f1-e59dd890c887',
            'CallerNumber' => '09142223343',
            'CalleeExtension' => '71057757',
            'DateTime' => '2026-08-18T11:44:16.7931026Z',
            'TransferredToNumber' => '0914222334',
            'Result' => false,
            'ClientReferenceId' => 'ce77bfd9-e92c-4e91-afe2-996c9b24e392',
            'StartDateTime' => '2026-08-18T11:44:15.1634649Z',
            'EndDateTime' => '2026-08-18T11:44:16.7931027Z',
            'MenuName' => '', 'MenuInput' => '',
            'DurationInSeconds' => 2,
            'CallInitiationSource' => 'Portal',
            'CallReferenceId' => '9787ff63-656a-4f2f-8765-76d1627de71e',
            'Recipients' => [], 'Parameters' => [],
        ];
    }

    private function hook(array $body, string $ip = self::PROVIDER_IP, ?string $token = null)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/cloud-phone/webhook/'.($token ?? self::TOKEN), $body);
    }

    private function ingestAll(array $events): void
    {
        $ingestor = app(CallIngestor::class);

        foreach ($events as $e) {
            $ingestor->ingest($e);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // پارسِ تاریخ — 🔴 دو فرمت در یک API
    // ══════════════════════════════════════════════════════════════════════

    public function test_iso_datetime_with_seven_fractional_digits_parses(): void
    {
        // ⚠️ ۷ رقمِ کسرِ ثانیه (فرمتِ .NET) — createFromFormat با 'u' این را نمی‌خوانَد
        $d = WebhookPayload::dateTime('2026-08-18T11:41:10.1746124Z');

        $this->assertNotNull($d);
        $this->assertSame('2026-08-18 11:41:10', $d->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $d->timezoneName);
    }

    public function test_american_slash_datetime_is_month_first_not_day_first(): void
    {
        /*
        | 🔴 حیاتی‌ترین ادعای این فایل.
        | «08/18/2026» یعنی ۱۸ اوت. اگر روز-اول خوانده شود، ماهِ ۱۸ می‌شود و
        | یا خطا می‌دهد یا (بدتر) تاریخِ بی‌ربطی می‌سازد.
        */
        $d = WebhookPayload::dateTime('08/18/2026 11:42:08');

        $this->assertNotNull($d);
        $this->assertSame(8, $d->month, 'باید ماهِ اوت باشد، نه روزِ ۸');
        $this->assertSame(18, $d->day);
        $this->assertSame('2026-08-18 11:42:08', $d->format('Y-m-d H:i:s'));
    }

    public function test_transfer_timestamps_land_between_the_iso_ones(): void
    {
        /*
        | رویدادهای انتقال منطقهٔ زمانی ندارند. اینکه UTC فرض شوند از همین
        | می‌آید: باید **بینِ** دو رویدادِ ISO بنشینند. اگر روزی به تهران تعبیر
        | شوند، این تست قرمز می‌شود — و همان‌جا می‌فهمیم، نه در گزارشِ عملکرد.
        */
        $started = WebhookPayload::dateTime('2026-08-18T11:40:17.1243237Z');
        $transfer = WebhookPayload::dateTime('08/18/2026 11:40:40');
        $ended = WebhookPayload::dateTime('2026-08-18T11:41:03.5466749Z');

        $this->assertTrue($transfer->greaterThan($started));
        $this->assertTrue($transfer->lessThan($ended));
    }

    public function test_malformed_datetime_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(WebhookPayload::dateTime('روز خوبی بود'));
        $this->assertNull(WebhookPayload::dateTime(''));
        $this->assertNull(WebhookPayload::dateTime(null));
    }

    // ══════════════════════════════════════════════════════════════════════
    // نامِ رویدادها
    // ══════════════════════════════════════════════════════════════════════

    public function test_event_names_are_pascal_case_not_the_dotted_names_in_the_panel(): void
    {
        // پنل می‌گوید Call.incoming.started — ولی هرگز چنین چیزی نمی‌آید
        $p = WebhookPayload::fromArray(['EventType' => 'Call.incoming.started', 'CallId' => 'x', 'CallReferenceId' => 'x']);
        $this->assertFalse($p->isKnownEvent(), 'نامِ نقطه‌دارِ مستندات نباید شناخته‌شده باشد');

        $p = WebhookPayload::fromArray($this->conversationOne()[0]);
        $this->assertTrue($p->isKnownEvent());
        $this->assertSame('incoming', $p->direction());
    }

    public function test_unknown_event_is_stored_not_dropped(): void
    {
        // مثلاً اگر روزی CallOutgoingStarted اضافه کنند
        $res = app(CallIngestor::class)->ingest([
            'EventType' => 'CallOutgoingStarted',
            'CallId' => 'new-1', 'CallReferenceId' => 'new-1',
            'DateTime' => '2026-08-18T12:00:00Z',
            'ClientReferenceId' => 'evt-new-1',
        ]);

        $this->assertSame(CallIngestor::UNKNOWN_EVENT, $res['status']);
        $this->assertDatabaseHas('phone_call_events', ['event_type' => 'CallOutgoingStarted']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ثبت و idempotency
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_same_event_twice_is_stored_once(): void
    {
        $event = $this->conversationOne()[0];

        $first = app(CallIngestor::class)->ingest($event);
        $second = app(CallIngestor::class)->ingest($event);

        $this->assertSame(CallIngestor::STORED, $first['status']);
        $this->assertSame(CallIngestor::DUPLICATE, $second['status']);
        $this->assertSame(1, PhoneCallEvent::count());
    }

    public function test_events_without_client_reference_id_still_deduplicate(): void
    {
        $event = $this->conversationOne()[0];
        unset($event['ClientReferenceId']);

        app(CallIngestor::class)->ingest($event);
        $second = app(CallIngestor::class)->ingest($event);

        $this->assertSame(CallIngestor::DUPLICATE, $second['status']);
        $this->assertSame(1, PhoneCallEvent::count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 🔴 جمع‌بندیِ چندپایی
    // ══════════════════════════════════════════════════════════════════════

    public function test_one_conversation_with_five_events_becomes_one_call(): void
    {
        $this->ingestAll($this->conversationOne());

        $this->assertSame(5, PhoneCallEvent::count());
        $this->assertSame(1, PhoneCall::count(), 'پنج رویداد = یک مکالمه، نه پنج تا');

        $call = PhoneCall::first();

        $this->assertSame('incoming', $call->direction);
        $this->assertSame(5, $call->event_count);
        $this->assertSame(2, $call->legs, 'دو CallId متمایز = دو پا');
        $this->assertTrue($call->was_transferred);
        $this->assertTrue($call->answered);
    }

    public function test_duration_spans_the_whole_conversation_not_the_last_leg(): void
    {
        $this->ingestAll($this->conversationOne());

        $call = PhoneCall::first();

        // 11:40:16.75 → 11:41:06.70  ≈ ۵۰ ثانیه
        $this->assertSame('2026-08-18 11:40:16', $call->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 11:41:06', $call->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(50, $call->duration_seconds);
    }

    public function test_out_of_order_delivery_produces_the_same_result(): void
    {
        /*
        | 🔴 ترتیبِ رسیدن تضمین‌شده نیست — در دادهٔ واقعی `Ended`ِ پایِ انتقال
        | پیش از `Ended`ِ پایِ اصلی رسید. جمع‌بندی باید به ترتیبِ **زمانِ رویداد**
        | تکیه کند، نه ترتیبِ درج.
        */
        $ordered = $this->conversationOne();
        $shuffled = array_reverse($ordered);

        $this->ingestAll($shuffled);
        $call = PhoneCall::first();

        $this->assertSame('2026-08-18 11:40:16', $call->started_at->format('Y-m-d H:i:s'));
        $this->assertSame(50, $call->duration_seconds);
        $this->assertTrue($call->answered);
        $this->assertSame(2, $call->legs);
    }

    public function test_unanswered_transfer_is_recorded_as_not_answered(): void
    {
        $this->ingestAll($this->conversationTwo());

        $call = PhoneCall::first();

        $this->assertFalse($call->answered);
        $this->assertTrue($call->was_transferred);
        $this->assertSame(1, PhoneCall::missed()->count());
    }

    public function test_a_call_still_in_progress_is_not_counted_as_missed(): void
    {
        /*
        | 🔴 فقط رویدادِ شروع رسیده. این تماس **از‌دست‌رفته نیست** — فقط تمام
        | نشده. اگر `answered` را false بگذاریم، هر تماسِ در جریان یک تیکتِ
        | الکی می‌سازد و کارشناس به کسی زنگ می‌زند که همین حالا پشتِ خط است.
        */
        app(CallIngestor::class)->ingest($this->conversationOne()[0]);

        $call = PhoneCall::first();

        $this->assertNull($call->answered, 'بدون Ended یعنی «نمی‌دانیم»، نه «نه»');
        $this->assertSame(0, PhoneCall::missed()->count());
    }

    public function test_outgoing_call_uses_the_providers_duration_field(): void
    {
        app(CallIngestor::class)->ingest($this->outgoingEvent());

        $call = PhoneCall::first();

        $this->assertSame('outgoing', $call->direction);
        $this->assertSame(2, $call->duration_seconds);
        $this->assertSame('Portal', $call->initiation_source);
        $this->assertFalse($call->answered);
    }

    public function test_two_conversations_do_not_bleed_into_each_other(): void
    {
        $this->ingestAll($this->conversationOne());
        $this->ingestAll($this->conversationTwo());
        app(CallIngestor::class)->ingest($this->outgoingEvent());

        $this->assertSame(3, PhoneCall::count());
        $this->assertSame(10, PhoneCallEvent::count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // نرمال‌سازی شماره
    // ══════════════════════════════════════════════════════════════════════

    public function test_mobile_normalisation_agrees_with_the_existing_sms_helper(): void
    {
        /*
        | ⚠️ دو مسیرِ نرمال‌سازی نباید هرگز واگرا شوند. اگر روزی یکی ارقامِ
        | فارسی را جا بیندازد، تماس‌ها به پروندهٔ اشتباه می‌چسبند در حالی که
        | پیامک درست می‌رود — و کسی ربطشان را نمی‌فهمد.
        */
        foreach (['09142223343', '+989142223343', '00989142223343', '989142223343', '۰۹۱۴۲۲۲۳۳۴۳'] as $input) {
            $this->assertSame(
                IranianMobile::national($input),
                IranianPhone::normalize($input),
                "واگرایی روی: $input",
            );
        }
    }

    public function test_our_own_number_loses_its_area_code_in_the_payload(): void
    {
        // شمارهٔ واقعی ما 02171057757 است و در payload به‌صورت 71057757 می‌آید
        $this->assertSame('2171057757', IranianPhone::normalize('02171057757'));
        $this->assertSame(IranianPhone::KIND_LANDLINE, IranianPhone::kind('02171057757'));

        $this->assertSame('71057757', IranianPhone::normalize('71057757'));
        $this->assertSame(IranianPhone::KIND_LOCAL, IranianPhone::kind('71057757'));

        $this->assertTrue(IranianPhone::couldMatch('02171057757', '71057757'));
    }

    public function test_a_local_number_that_looks_like_a_country_code_is_not_truncated(): void
    {
        // 🔴 «98123456» یک شمارهٔ محلیِ معتبر است، نه کدِ کشور + چیزی
        $this->assertSame('98123456', IranianPhone::normalize('98123456'));
    }

    public function test_short_extensions_never_match_a_customer_number(): void
    {
        $this->assertFalse(IranianPhone::couldMatch('09142223343', '343'));
        $this->assertSame(IranianPhone::KIND_EXTENSION, IranianPhone::kind('201'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // تطبیقِ مشتری
    // ══════════════════════════════════════════════════════════════════════

    public function test_mobile_caller_is_matched_exactly(): void
    {
        $customer = Customer::create(['email' => 'mobile@example.com', 'password' => 'x', 'phone' => '+989142223343']);

        app(CallIngestor::class)->ingest([
            'EventType' => 'CallIncomingStarted',
            'CallId' => 'm-1', 'CallReferenceId' => 'm-1',
            'CallerNumber' => '09142223343',
            'CalleeExtension' => '71057757',
            'DateTime' => '2026-08-18T12:00:00Z',
            'ClientReferenceId' => 'evt-m-1',
        ]);

        $call = PhoneCall::first();

        $this->assertSame($customer->id, $call->customer_id);
        $this->assertSame(PhoneCall::MATCH_EXACT, $call->match_confidence);
        $this->assertTrue($call->isConfidentMatch());
    }

    public function test_landline_without_area_code_matches_but_is_flagged_as_uncertain(): void
    {
        $customer = Customer::create(['email' => 'urmia@example.com', 'password' => 'x', 'phone' => '+984434261000']);

        app(CallIngestor::class)->ingest([
            'EventType' => 'CallIncomingStarted',
            'CallId' => 'l-1', 'CallReferenceId' => 'l-1',
            'CallerNumber' => '34261000',          // 🔴 بدون پیش‌شماره
            'CalleeExtension' => '71057757',
            'DateTime' => '2026-08-18T12:00:00Z',
            'ClientReferenceId' => 'evt-l-1',
        ]);

        $call = PhoneCall::first();

        $this->assertSame($customer->id, $call->customer_id);
        $this->assertSame(PhoneCall::MATCH_LOCAL, $call->match_confidence);
        $this->assertFalse($call->isConfidentMatch(), 'رابط کاربری باید تردید را نشان دهد');
    }

    public function test_an_ambiguous_local_number_is_attached_to_nobody(): void
    {
        /*
        | 🔴 مهم‌ترین ادعای امنیتیِ این فایل.
        |
        | همان ۸ رقم در ارومیه و تهران دو مشتریِ متفاوت است. تطبیقِ غلط یعنی
        | کارشناس پروندهٔ اشتباه را باز می‌کند و ممکن است اطلاعاتِ حسابِ یک نفر
        | را به نفرِ دیگری بگوید — نشتِ اطلاعات، نه یک باگِ ظاهری.
        */
        Customer::create(['email' => 'urmia@example.com', 'password' => 'x', 'phone' => '+984434261000']);   // ارومیه
        Customer::create(['email' => 'tehran@example.com', 'password' => 'x', 'phone' => '+982134261000']);  // تهران

        app(CallIngestor::class)->ingest([
            'EventType' => 'CallIncomingStarted',
            'CallId' => 'a-1', 'CallReferenceId' => 'a-1',
            'CallerNumber' => '34261000',
            'CalleeExtension' => '71057757',
            'DateTime' => '2026-08-18T12:00:00Z',
            'ClientReferenceId' => 'evt-a-1',
        ]);

        $call = PhoneCall::first();

        $this->assertNull($call->customer_id, 'مبهم بود ⇒ به هیچ‌کس وصل نشود');
        $this->assertSame(PhoneCall::MATCH_MANY, $call->match_confidence);
    }

    // ══════════════════════════════════════════════════════════════════════
    // روتِ وبهوک
    // ══════════════════════════════════════════════════════════════════════

    public function test_valid_request_is_accepted_and_stored(): void
    {
        $this->hook($this->conversationOne()[0])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => CallIngestor::STORED]);

        $this->assertSame(1, PhoneCallEvent::count());
    }

    public function test_wrong_token_is_rejected_and_stores_nothing(): void
    {
        $this->hook($this->conversationOne()[0], token: 'cp-0000000000000000000000000000000')
            ->assertNotFound();

        $this->assertSame(0, PhoneCallEvent::count());
    }

    public function test_an_empty_configured_token_closes_the_route_instead_of_opening_it(): void
    {
        /*
        | 🔴 پیکربندیِ جاافتاده باید **ببندد**. اگر توکنِ خالی به معنیِ «بررسی
        | نکن» بود، فراموش‌کردنِ یک خطِ `.env` وبهوک را برای همهٔ اینترنت باز
        | می‌کرد — و چون همه‌چیز کار می‌کرد، هیچ‌کس نمی‌فهمید.
        */
        config()->set('services.cloud_phone.webhook_token', '');

        $this->hook($this->conversationOne()[0])->assertNotFound();
        $this->assertSame(0, PhoneCallEvent::count());
    }

    public function test_request_from_an_unlisted_ip_is_rejected(): void
    {
        $this->hook($this->conversationOne()[0], ip: '203.0.113.9')->assertForbidden();

        $this->assertSame(0, PhoneCallEvent::count());
    }

    public function test_empty_ip_allowlist_disables_the_check_but_keeps_the_token(): void
    {
        config()->set('services.cloud_phone.webhook_ips', []);

        $this->hook($this->conversationOne()[0], ip: '203.0.113.9')->assertOk();
        $this->hook($this->conversationOne()[1], ip: '203.0.113.9', token: 'cp-wrongwrongwrongwrong')->assertNotFound();
    }

    public function test_garbage_body_still_returns_200_so_the_hook_is_not_disabled(): void
    {
        /*
        | وبهوکی که خطا برگرداند از سمتِ فرستنده retry و بعد غیرفعال می‌شود.
        | پس حتی بدنهٔ بی‌ربط هم ۲۰۰ می‌گیرد — ولی «invalid» علامت می‌خورد.
        */
        $this->hook(['salam' => 'donya'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => CallIngestor::INVALID]);

        $this->assertSame(0, PhoneCallEvent::count());
    }

    public function test_the_route_is_exempt_from_csrf(): void
    {
        /*
        | تماس‌گیرنده یک سرور است: نه نشست دارد نه توکن. بدونِ استثنا، middleware
        | پیش از کنترلر رد می‌کند و حتی در لاگ هم نمی‌افتد — «وبهوک نمی‌آید».
        */
        $this->assertNotSame(419, $this->hook($this->conversationOne()[0])->status());
    }
}
