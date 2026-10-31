<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Services\Bale\Management\ManagerBusinessCalendarSnapshot;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\Providers\ManualEventProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagerBusinessCalendarSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-31 13:00:00', 'Asia/Tehran'));
        config()->set('calendar.layers', [
            'payment_due' => ['label' => 'سررسید پرداخت'],
            'hosting_renewal' => ['label' => 'تمدید سرویس'],
            'social_post' => ['label' => 'انتشار محتوا', 'default' => false],
            'task' => ['label' => 'یادآوری و کار', 'emits' => 'any'],
            'google' => ['label' => 'تقویم شخصی گوگل'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provider(array $items): CalendarEventProvider
    {
        return new class($items) implements CalendarEventProvider {
            public int $calls = 0;
            public function __construct(private readonly array $items) {}
            public function getEvents(Carbon $from, Carbon $to): Collection
            {
                $this->calls++;
                return collect($this->items)->filter(fn ($item) => $item->at->betweenIncluded($from, $to))->values();
            }
        };
    }

    private function calendar(array $byType = []): CalendarService
    {
        $calendar = new CalendarService();
        foreach (array_keys((array) config('calendar.layers')) as $type) {
            $calendar->register($type, $this->provider($byType[$type] ?? []));
        }
        return $calendar;
    }

    private function item(string $type, int $id, string $at, string $status = 'pending'): CalendarItem
    {
        return new CalendarItem($type, 'fixture', $id, $type.' '.$id, null, Carbon::parse($at, 'Asia/Tehran'), $status);
    }

    public function test_seven_day_business_events_are_not_hidden_by_two_day_window_or_content_default_and_content_cannot_drown_actions(): void
    {
        $byType = [];
        foreach (['payment_due' => 7, 'hosting_renewal' => 5, 'social_post' => 25] as $type => $count) {
            for ($i = 1; $i <= $count; $i++) { $byType[$type][] = $this->item($type, $i, '2026-11-02 10:00:00'); }
        }
        $calendar = $this->calendar($byType);
        $snapshot = (new ManagerBusinessCalendarSnapshot($calendar))->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertFalse($snapshot['partial']);
        $this->assertSame(0, $snapshot['count']);
        $this->assertSame(37, $snapshot['count_7d']);
        $this->assertSame(25, $snapshot['content_count']);
        $this->assertSame(['payment_due' => 7, 'hosting_renewal' => 5, 'social_post' => 25, 'task' => 0], $snapshot['type_counts']);
        $this->assertCount(10, $snapshot['items']);
        $this->assertSame(2, $snapshot['omitted_count']);
        $this->assertNotContains('social_post', array_column($snapshot['items'], 'type'));
        $this->assertSame('payment_due', $snapshot['items'][0]['type']);
        $this->assertSame(0, $calendar->providers()['google']->calls);
        $this->assertSame(1, $calendar->providers()['social_post']->calls);
        Http::assertNothingSent();
    }

    public function test_exact_tehran_calendar_bounds_and_pending_statuses_are_preserved(): void
    {
        $items = [
            $this->item('payment_due', 1, '2026-10-30T20:29:59Z'),
            $this->item('payment_due', 2, '2026-10-30T20:30:00Z'),
            $this->item('payment_due', 3, '2026-11-01 23:59:59'),
            $this->item('payment_due', 4, '2026-11-02 00:00:00'),
            $this->item('payment_due', 5, '2026-11-06 23:59:59'),
            $this->item('payment_due', 6, '2026-11-07 00:00:00'),
            $this->item('payment_due', 7, '2026-10-31 15:00:00', 'done'),
            $this->item('payment_due', 8, '2026-10-31 15:00:00', 'cancelled'),
        ];
        $snapshot = (new ManagerBusinessCalendarSnapshot($this->calendar(['payment_due' => $items])))->snapshot();
        $this->assertSame(2, $snapshot['count']);
        $this->assertSame(4, $snapshot['count_7d']);
        $this->assertSame('2026-10-31T00:00:00+03:30', $snapshot['from_at']);
        $this->assertSame('2026-11-01T23:59:59+03:30', $snapshot['to_at']);
        $this->assertSame('2026-11-06T23:59:59+03:30', $snapshot['lookahead_to_at']);
        $this->assertCount(4, $snapshot['items']);
        Http::assertNothingSent();
    }

    public function test_missing_registered_source_and_missing_real_source_table_cannot_be_reported_as_verified_zero(): void
    {
        $calendar = new CalendarService();
        $calendar->register('payment_due', $this->provider([]));
        $calendar->register('hosting_renewal', $this->provider([]));
        $calendar->register('task', new ManualEventProvider());
        $snapshot = (new ManagerBusinessCalendarSnapshot($calendar))->snapshot();
        $this->assertTrue($snapshot['partial']);
        $this->assertNull($snapshot['count']);
        $this->assertNull($snapshot['count_7d']);
        $this->assertSame(0, $snapshot['observed_count']);
        $this->assertSame(0, $snapshot['observed_count_7d']);
        $this->assertSame(['انتشار محتوا', 'یادآوری و کار'], $snapshot['missing_layers']);
        Http::assertNothingSent();
    }

    public function test_capped_layer_keeps_observations_and_marks_totals_unknown(): void
    {
        config()->set('calendar.max_events_per_layer', 1);
        $calendar = $this->calendar(['payment_due' => [
            $this->item('payment_due', 1, '2026-10-31 15:00:00'),
            $this->item('payment_due', 2, '2026-10-31 16:00:00'),
        ]]);
        $snapshot = (new ManagerBusinessCalendarSnapshot($calendar))->snapshot();
        $this->assertTrue($snapshot['partial']);
        $this->assertNull($snapshot['count']);
        $this->assertNull($snapshot['count_7d']);
        $this->assertSame(1, $snapshot['observed_count_7d']);
        $this->assertSame(['سررسید پرداخت'], $snapshot['truncated_layers']);
        $this->assertCount(1, $snapshot['items']);
        Http::assertNothingSent();
    }

    public function test_failed_layer_cannot_hide_behind_successful_layers_empty_or_partial_results(): void
    {
        $calendar = $this->calendar(['task' => [$this->item('task', 1, '2026-11-01 10:00:00')]]);
        $calendar->register('payment_due', new class implements CalendarEventProvider {
            public function getEvents(Carbon $from, Carbon $to): Collection
            {
                throw new \RuntimeException('Fixture source unavailable');
            }
        });
        $snapshot = (new ManagerBusinessCalendarSnapshot($calendar))->snapshot();
        $this->assertTrue($snapshot['partial']);
        $this->assertNull($snapshot['count']);
        $this->assertNull($snapshot['count_7d']);
        $this->assertSame(1, $snapshot['observed_count_7d']);
        $this->assertSame(['سررسید پرداخت'], $snapshot['failed_layers']);
        $this->assertSame('task', $snapshot['items'][0]['type']);
        Http::assertNothingSent();
    }

    public function test_real_recurring_manual_reminders_are_expanded_without_modifying_business_records(): void
    {
        config()->set('calendar.layers', ['task' => ['label' => 'یادآوری و کار', 'emits' => 'any', 'provider' => ManualEventProvider::class]]);
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id(); $table->string('type'); $table->string('title'); $table->text('description')->nullable();
            $table->date('event_date'); $table->string('status'); $table->string('repeat');
            $table->date('repeat_until')->nullable(); $table->bigInteger('amount')->nullable();
            $table->string('currency_code')->nullable(); $table->json('meta')->nullable(); $table->timestamps();
        });
        $base = ['type' => 'task', 'status' => 'pending', 'repeat' => 'weekly', 'event_date' => '2026-10-25'];
        CalendarEvent::create($base + ['title' => 'Fixture recurring obligation']);
        CalendarEvent::create($base + ['title' => 'Fixture completed occurrence', 'meta' => ['done' => ['2026-11-01']]]);
        CalendarEvent::create(['type' => 'task', 'status' => 'cancelled', 'repeat' => 'none', 'event_date' => '2026-11-01', 'title' => 'Fixture cancelled']);
        $before = DB::table('calendar_events')->orderBy('id')->get()->toJson();
        $snapshot = (new ManagerBusinessCalendarSnapshot(new CalendarService()))->snapshot();
        $this->assertFalse($snapshot['partial']);
        $this->assertSame(1, $snapshot['count']);
        $this->assertSame(1, $snapshot['count_7d']);
        $this->assertSame('Fixture recurring obligation', $snapshot['items'][0]['title']);
        $this->assertSame('2026-11-01T00:00:00+03:30', $snapshot['items'][0]['at']);
        $this->assertSame($before, DB::table('calendar_events')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }
}
