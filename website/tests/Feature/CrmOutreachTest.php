<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmSuppression;
use App\Models\User;
use App\Services\Crm\ContactFinder;
use App\Services\Crm\OutreachMailer;
use App\Services\Crm\RedLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * موتورِ جذبِ مشتری — چیزهایی که اگر بشکنند، **بی‌صدا** می‌شکنند.
 *
 * هر تستِ این فایل یک ریسکِ واقعی را می‌بندد، نه یک خطِ کد را:
 *   • ایمیل بعد از «no» → نقضِ CAN-SPAM/CASL
 *   • پیامِ چهارم → شکایتِ اسپم و سوختنِ دامنه
 *   • عدد در متن → ادعای اندازه‌گیری‌نشده جلوی خریدارِ صنعتی
 *   • ارسال با خلبانِ خاموش → همان چیزی که قرار بود اتفاق نیفتد
 *   • سرنخِ تکراری → یک کلینیک، دو ایمیل
 */
class CrmOutreachTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attrs = []): CrmLead
    {
        $site = $attrs['website'] ?? 'https://clinic'.random_int(1, 99999).'.ae';

        return CrmLead::create($attrs + [
            'domain_hash' => CrmLead::hashFor($site),
            'company'     => 'Clinic',
            'website'     => $site,
            'email'       => 'info@'.parse_url($site, PHP_URL_HOST),
            'observation' => 'No pricing page anywhere on the site.',
            'stage'       => 'new',
        ]);
    }

    private function queued(CrmLead $lead, int $sequence = 0): CrmMessage
    {
        return CrmMessage::create([
            'lead_id'   => $lead->id,
            'channel'   => 'email',
            'direction' => 'out',
            'subject'   => 'A note about your booking page',
            'body'      => 'Plain body.',
            'status'    => 'queued',
            'sequence'  => $sequence,
        ]);
    }

    // ───────────────────── فهرستِ سیاه ─────────────────────

    public function test_suppression_blocks_the_whole_domain_not_just_one_mailbox(): void
    {
        CrmSuppression::add('info@clinic.ae', 'unsubscribe');

        $this->assertTrue(CrmSuppression::blocks('info@clinic.ae'));
        $this->assertTrue(CrmSuppression::blocks('SALES@Clinic.AE'), 'همان شرکت است و همان آدم جواب می‌دهد');
        $this->assertFalse(CrmSuppression::blocks('info@other.ae'));
    }

    public function test_empty_address_is_treated_as_blocked(): void
    {
        $this->assertTrue(CrmSuppression::blocks(null));
        $this->assertTrue(CrmSuppression::blocks(''));
    }

    public function test_message_queued_before_the_optout_is_not_sent_after_it(): void
    {
        config(['crm.autopilot' => true]);
        Mail::fake();

        $lead = $this->lead(['email' => 'info@clinic.ae']);
        $message = $this->queued($lead);

        // بینِ صف و ارسال، همان آدم «no» می‌فرستد.
        CrmSuppression::add('info@clinic.ae', 'unsubscribe');

        $sent = app(OutreachMailer::class)->sendOne($message);

        $this->assertFalse($sent);
        $this->assertSame('skipped', $message->fresh()->status);
        Mail::assertNothingSent();
    }

    // ───────────────────── سقفِ دنباله ─────────────────────

    public function test_lead_is_closed_after_the_third_message_and_can_never_be_contacted_again(): void
    {
        config(['crm.autopilot' => true]);
        Mail::fake();

        $lead = $this->lead();
        $message = $this->queued($lead, CrmMessage::MAX_SEQUENCE);

        app(OutreachMailer::class)->sendOne($message);

        $lead->refresh();
        $this->assertSame('lost', $lead->stage);
        $this->assertNull($lead->next_action_at);
        $this->assertFalse($lead->isContactable(), 'سرنخِ بسته نباید هیچ‌وقت پیامِ چهارم بگیرد');
    }

    public function test_first_message_moves_the_lead_to_contacted_with_a_follow_up_date(): void
    {
        config(['crm.autopilot' => true]);
        Mail::fake();

        $lead = $this->lead();
        app(OutreachMailer::class)->sendOne($this->queued($lead, 0));

        $lead->refresh();
        $this->assertSame('contacted', $lead->stage);
        $this->assertSame(
            now()->addDays(CrmLead::CADENCE['contacted'])->toDateString(),
            $lead->next_action_at->toDateString(),
        );
    }

    public function test_a_lead_without_an_observation_is_never_contactable(): void
    {
        $lead = $this->lead(['observation' => null]);

        $this->assertFalse($lead->isContactable(), 'قانونِ ۶۰ ثانیه در سطحِ داده');
    }

    // ───────────────────── خطِ قرمز ─────────────────────

    public function test_redline_rejects_invented_numbers_and_urgency(): void
    {
        $r = app(RedLine::class);

        $this->assertFalse($r->clean('We increased conversions by 35% for a similar clinic.'));
        $this->assertFalse($r->clean('This will 3x your bookings.'));
        $this->assertFalse($r->clean('Guaranteed results or your money back.'));
        $this->assertFalse($r->clean('Limited time offer, act now.'));
        $this->assertFalse($r->clean('I can offer a discount because I am based in Iran.'));

        $this->assertTrue($r->clean(
            'Your booking page asks for a passport number before it shows any prices. '
            .'If you are not happy with the first design direction, you do not pay.'
        ));
    }

    // ───────────────────── خلبانِ خودکار ─────────────────────

    public function test_nothing_is_sent_while_autopilot_is_off(): void
    {
        config(['crm.autopilot' => false]);
        Mail::fake();

        $this->queued($this->lead());

        $r = app(OutreachMailer::class)->drain();

        $this->assertSame('autopilot_off', $r['halted']);
        Mail::assertNothingSent();
    }

    public function test_send_window_is_closed_on_the_gulf_weekend(): void
    {
        $mailer = app(OutreachMailer::class);

        // پیکربندی: شنبه و یک‌شنبه تعطیل، ۵ تا ۱۲ به وقتِ UTC
        $this->assertFalse($mailer->inSendWindow(Carbon::parse('2026-08-08 09:00:00')), 'شنبه');
        $this->assertFalse($mailer->inSendWindow(Carbon::parse('2026-08-09 09:00:00')), 'یک‌شنبه');
        $this->assertFalse($mailer->inSendWindow(Carbon::parse('2026-08-10 02:00:00')), 'نیمه‌شبِ دوبی');
        $this->assertTrue($mailer->inSendWindow(Carbon::parse('2026-08-10 09:00:00')), 'دوشنبه، ساعتِ کاری');
    }

    public function test_warmup_holds_the_first_day_far_below_the_configured_cap(): void
    {
        config(['crm.caps.email' => 30, 'crm.warmup.enabled' => true]);

        $cap = app(OutreachMailer::class)->dailyCap();

        $this->assertLessThan(30, $cap, 'دامنه‌ای که ناگهان ۳۰ ایمیلِ سرد بفرستد، شبیهِ اکانتِ هک‌شده است');
        $this->assertGreaterThan(0, $cap);
    }

    public function test_warmup_can_be_switched_off_deliberately(): void
    {
        config(['crm.caps.email' => 30, 'crm.warmup.enabled' => false]);

        $this->assertSame(30, app(OutreachMailer::class)->dailyCap());
    }

    // ───────────────────── ضدِ تکرار ─────────────────────

    public function test_the_same_clinic_never_enters_the_funnel_twice(): void
    {
        $this->assertSame(
            CrmLead::hashFor('https://WWW.Clinic.AE/booking?utm=x'),
            CrmLead::hashFor('clinic.ae'),
        );

        $this->assertSame(
            CrmLead::hashFor('info@clinic.ae'),
            CrmLead::hashFor('https://clinic.ae'),
        );
    }

    // ───────────────────── یافتنِ نشانی ─────────────────────

    public function test_contact_finder_reads_published_addresses_and_ignores_junk(): void
    {
        $found = app(ContactFinder::class)->extract(
            '<a href="mailto:info@clinic.ae">Email us</a> <img src="logo@2x.png">'
            .' someone@example.com <span>reception@clinic.ae</span>'
        );

        $this->assertContains('info@clinic.ae', $found);
        $this->assertContains('reception@clinic.ae', $found);
        $this->assertNotContains('someone@example.com', $found, 'نشانیِ نمونه، نشانیِ کسب‌وکار نیست');
        $this->assertNotContains('logo@2x.png', $found);
    }

    // ───────────────────── خواندنِ جواب‌ها ─────────────────────

    public function test_inbox_tells_a_real_reply_from_a_bounce_and_from_a_no(): void
    {
        $s = app(\App\Services\Crm\InboxScanner::class);

        $this->assertSame('bounce', $s->classify(
            'MAILER-DAEMON@mail.clinic.ae', 'Undeliverable: A note about your page', '550 5.1.1 unknown'
        ));
        $this->assertSame('bounce', $s->classify(
            'someone@clinic.ae', 'Delivery Status Notification (Failure)', 'not delivered'
        ));

        $this->assertSame('optout', $s->classify('info@clinic.ae', 'Re: your note', 'No.'));
        $this->assertSame('optout', $s->classify('info@clinic.ae', 'Re: your note', 'Please remove me from your list'));
        $this->assertSame('optout', $s->classify('info@clinic.ae', 'unsubscribe', ''));
        $this->assertSame('optout', $s->classify('info@clinic.ae', 'Re: your note', 'Not interested, thanks'));

        $this->assertSame('reply', $s->classify(
            'info@clinic.ae', 'Re: your note', 'Interesting. What would this cost and how long does it take?'
        ));
        $this->assertSame('reply', $s->classify(
            'info@clinic.ae', 'Re: your note', 'We know about the booking page. Can you send examples?'
        ));
    }

    // ───────────────────── پنل ─────────────────────

    public function test_panel_requires_an_admin(): void
    {
        $writer = User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'writer',
        ]);

        $this->actingAs($writer)->get('/admin/crm')->assertForbidden();
    }

    public function test_panel_pages_render_for_an_admin(): void
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'r'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $lead = $this->lead();
        $this->queued($lead);

        $this->actingAs($admin)->get('/admin/crm')
            ->assertOk()
            ->assertSee('منتظرِ تأییدِ تو', false);

        $this->actingAs($admin)->get('/admin/crm/'.$lead->id)
            ->assertOk()
            ->assertSee($lead->company, false);
    }

    public function test_admin_can_reject_a_draft_and_it_is_never_sent(): void
    {
        config(['crm.autopilot' => true]);
        Mail::fake();

        $admin = User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $message = $this->queued($this->lead());

        $this->actingAs($admin)
            ->post('/admin/crm/message/'.$message->id.'/reject')
            ->assertRedirect();

        $this->assertSame('cancelled', $message->fresh()->status);

        $r = app(OutreachMailer::class)->drain(null, true);
        $this->assertSame(0, $r['sent']);
        Mail::assertNothingSent();
    }

    public function test_duplicate_domain_is_refused_by_the_manual_form(): void
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'b'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $this->lead(['website' => 'https://smiledubai.ae']);

        $this->actingAs($admin)
            ->post('/admin/crm', [
                'company' => 'Smile Dubai',
                'website' => 'https://www.smiledubai.ae/',
            ])
            ->assertSessionHasErrors('website');

        $this->assertSame(1, CrmLead::count());
    }
}
