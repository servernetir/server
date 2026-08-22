<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use App\Models\User;
use App\Services\Shop\PartsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * پنلِ مدیریتِ قطعات — `/admin/parts`.
 *
 * ═══ چه چیزی این‌جا واقعاً می‌تواند خراب شود ═══
 *
 * نه فرمِ ذخیره — آن اگر بشکند، مدیر همان لحظه می‌بیند. آن‌چه خاموش می‌شکند
 * دو چیز است: کشی که پاک نمی‌شود (مدیر ذخیره می‌کند و ۱۰ دقیقه هیچ اثری در
 * سایت نمی‌بیند و نتیجه می‌گیرد ذخیره نشده) و آرایهٔ نسلِ خالی که به‌جای
 * `null` ذخیره می‌شود (قطعه از هر پنج صفحهٔ نسل غیب می‌شود، بی‌خطا).
 */
class AdminPartsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge([
            'slug'      => 'test-cpu',
            'category'  => 'cpu',
            'brand'     => 'Intel',
            'condition' => 'refurb',
            'sort'      => 5,
            'gens'      => ['gen9'],
            'price_eur' => 3400,
            'in_stock'  => '1',
            'active'    => '1',
            'name_fa'   => 'پردازندهٔ آزمایشی',
            'name_en'   => 'Test processor',
            'name_tr'   => 'Test işlemci',
            'attr_cores' => 14,
            'spec_label_fa' => ['هسته'],
            'spec_val_fa'   => ['۱۴'],
        ], $override);
    }

    public function test_admin_can_create_a_part_and_it_appears_on_the_public_shop(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/parts', $this->form())
            ->assertRedirect('/admin/parts');

        $part = ServerPart::where('slug', 'test-cpu')->firstOrFail();

        $this->assertSame(3400, $part->price_eur);
        $this->assertSame(['gen9'], $part->compat_gens);
        $this->assertSame(14, $part->attrs['cores']);

        $this->get('/parts/cpu')->assertOk()->assertSee('پردازندهٔ آزمایشی');
        $this->get('/parts/cpu/test-cpu')->assertOk();
    }

    /**
     * 🔴 فهرستِ نسلِ خالی باید `null` شود، نه `[]`.
     *
     * `scopeForGeneration()` می‌گوید قطعهٔ بدونِ فهرست عمومی است و همه‌جا
     * دیده می‌شود، و این را با `orWhereNull` پیاده کرده. آرایهٔ خالی نه null
     * است نه شاملِ چیزی — پس قطعه در **هیچ** نسلی نمی‌آمد. هیچ خطایی، فقط
     * نبودن.
     */
    public function test_leaving_every_generation_unticked_makes_the_part_universal(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/parts', $this->form(['slug' => 'caddy', 'category' => 'other', 'gens' => []]))
            ->assertRedirect('/admin/parts');

        $part = ServerPart::where('slug', 'caddy')->firstOrFail();
        $this->assertNull($part->compat_gens, 'فهرستِ خالی باید null شود تا قطعه عمومی بماند');

        foreach (['gen8', 'gen9', 'gen10', 'gen11', 'gen12'] as $gen) {
            $this->get('/servers/hp/'.$gen)->assertOk()->assertSee('پردازندهٔ آزمایشی');
        }
    }

    /**
     * 🔴 ذخیره باید کشِ فروشگاه را **همان لحظه** پاک کند.
     *
     * بی‌این، مدیر قطعه اضافه می‌کرد و تا ۱۰ دقیقه نه در شمارشِ سایدبار
     * می‌دیدش نه در فیلترها — و منطقاً نتیجه می‌گرفت ذخیره نشده.
     *
     * ⚠️ کش عمداً **پیش از** ذخیره گرم می‌شود، وگرنه تست تو‌خالی است: کشِ سرد
     * با پاک‌نکردن هم درست به نظر می‌رسد.
     */
    public function test_saving_a_part_busts_the_shop_cache_immediately(): void
    {
        $catalog = app(PartsCatalog::class);

        $this->assertSame(0, $catalog->categories()['cpu']['count']);
        $this->assertSame([], $catalog->facets('cpu')['gens']);

        $this->actingAs($this->admin(), 'web')->post('/admin/parts', $this->form());

        $this->assertSame(1, $catalog->categories()['cpu']['count'], 'شمارشِ سایدبار باید بلافاصله به‌روز شود');
        $this->assertSame(['gen9'], $catalog->facets('cpu')['gens'], 'فیلترها باید بلافاصله نسلِ تازه را بشناسند');
    }

    public function test_admin_can_edit_and_delete_a_part(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->post('/admin/parts', $this->form());
        $part = ServerPart::where('slug', 'test-cpu')->firstOrFail();

        $this->actingAs($admin, 'web')
            ->post('/admin/parts/'.$part->id, $this->form(['price_eur' => 9900, 'name_fa' => 'نامِ تازه']))
            ->assertRedirect('/admin/parts');

        $this->assertSame(9900, $part->fresh()->price_eur);
        $this->assertSame('نامِ تازه', $part->fresh()->name['fa']);

        $this->actingAs($admin, 'web')
            ->post('/admin/parts/'.$part->id.'/delete')
            ->assertRedirect('/admin/parts');

        $this->assertNull(ServerPart::find($part->id));
        Cache::flush();
        $this->get('/parts/cpu')->assertOk()->assertDontSee('نامِ تازه');
    }

    /**
     * ⚠️ کلیدِ ناشناخته در `attrs` ذخیره نمی‌شود.
     *
     * جدولِ مقایسه برای هر کلید به برچسب و واحد نیاز دارد؛ کلیدی که در
     * `ATTR_LABELS` نیست یا رندر نمی‌شد یا خام («cores») نشان داده می‌شد.
     */
    public function test_unknown_numeric_attributes_are_dropped(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/parts', $this->form(['attr_wat' => 99, 'attr_cores' => 8]));

        $attrs = ServerPart::where('slug', 'test-cpu')->firstOrFail()->attrs;

        $this->assertSame(['cores' => 8], $attrs);
    }

    /** نویسنده و مهمان نباید به فروشگاهِ قطعات دست بزنند. */
    public function test_only_admins_reach_the_parts_panel(): void
    {
        $this->get('/admin/parts')->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'author']), 'web')
            ->get('/admin/parts')
            ->assertForbidden();

        $this->actingAs($this->admin(), 'web')->get('/admin/parts')->assertOk();
    }

    /** فرمِ افزودن و ویرایش هر دو باید بی‌خطا رندر شوند. */
    public function test_the_create_and_edit_forms_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->get('/admin/parts/create')->assertOk();

        $this->actingAs($admin, 'web')->post('/admin/parts', $this->form());
        $id = ServerPart::where('slug', 'test-cpu')->firstOrFail()->id;

        $this->actingAs($admin, 'web')->get('/admin/parts/'.$id.'/edit')->assertOk()->assertSee('پردازندهٔ آزمایشی');
    }
}
