<?php

namespace Tests\Feature;

use App\Models\PhysicalServer;
use App\Models\User;
use Database\Seeders\PhysicalServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فروشگاهِ سرورِ فیزیکی — منبعِ DB، مدیریتِ CRUD، و fallbackِ config.
 *
 * PhysicalServerSeeder مدل‌های config را (insert-missing) پر می‌کند — همان چیزی
 * که روتِ دیپلوی بعد از migrate می‌زند. این‌جا می‌سنجیم که صفحاتِ عمومی از DB
 * می‌خوانند و مدیر می‌تواند اضافه/ویرایش/حذف کند.
 */
class PhysicalServerShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhysicalServerSeeder::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'slug' => 'test-server-x', 'brand' => 'hp', 'condition' => 'new',
            'sort' => 5, 'active' => '1', 'price_contact' => '1',
            'name_fa' => 'سرور آزمایشی', 'name_en' => 'Test Server', 'name_tr' => 'Test Sunucu',
            'tag_fa' => 'شعار', 'tag_en' => 'tag', 'tag_tr' => 'etiket',
            'hero_d_fa' => 'توضیح فا', 'hero_d_en' => 'hero en', 'hero_d_tr' => 'hero tr',
            'desc_fa' => 'توضیح بلند', 'desc_en' => 'long', 'desc_tr' => 'uzun',
            'spec_label_fa' => ['پردازنده'], 'spec_label_en' => ['Processor'], 'spec_label_tr' => ['İşlemci'],
            'spec_val_fa' => ['۲× Xeon'], 'spec_val_en' => ['2× Xeon'], 'spec_val_tr' => ['2× Xeon'],
        ], $over);
    }

    // ═══════════════ منبعِ داده ═══════════════

    public function test_seed_populated_from_config(): void
    {
        $this->assertGreaterThanOrEqual(6, PhysicalServer::count());
        $this->assertNotNull(PhysicalServer::where('slug', 'hpe-proliant-dl380-gen10')->first());
    }

    public function test_public_index_renders_from_db(): void
    {
        PhysicalServer::query()->delete();
        PhysicalServer::create($this->payload() + [
            'name' => ['fa' => 'سرورِ یکتا‌نام', 'en' => 'UniqueServer', 'tr' => 'Uniq'],
            'tag' => ['fa' => 't', 'en' => 't', 'tr' => 't'],
            'hero_d' => ['fa' => 'h', 'en' => 'h', 'tr' => 'h'],
            'description' => ['fa' => 'd', 'en' => 'd', 'tr' => 'd'],
            'specs' => [], 'gallery' => [],
        ]);

        $this->get('/servers')->assertOk()->assertSee('سرورِ یکتا‌نام');
    }

    public function test_public_show_renders_a_model(): void
    {
        $this->get('/servers/hpe-proliant-dl380-gen10')
            ->assertOk()
            ->assertSee('HPE ProLiant DL380 Gen10');
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get('/servers/no-such-server')->assertNotFound();
    }

    /** ویرایشِ DB باید صفحهٔ عمومی را عوض کند (یعنی منبع DB است نه config) */
    public function test_editing_db_changes_public_page(): void
    {
        $m = PhysicalServer::where('slug', 'hpe-proliant-dl380-gen10')->first();
        $m->update(['name' => ['fa' => 'نامِ تازهٔ آزمایشی', 'en' => 'X', 'tr' => 'X']]);

        $this->get('/servers/hpe-proliant-dl380-gen10')->assertOk()->assertSee('نامِ تازهٔ آزمایشی');
    }

    /** جدولِ خالی ⇒ برگشت به config تا فروشگاه خالی نشود */
    public function test_falls_back_to_config_when_table_empty(): void
    {
        PhysicalServer::query()->delete();

        $this->get('/servers')->assertOk()->assertSee('HPE ProLiant DL380 Gen10');
    }

    // ═══════════════ مدیریت ═══════════════

    public function test_admin_can_create(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/server-shop', $this->payload(['slug' => 'new-one']))
            ->assertRedirect('/admin/server-shop');

        $m = PhysicalServer::where('slug', 'new-one')->first();
        $this->assertNotNull($m);
        $this->assertSame('سرور آزمایشی', $m->name['fa']);
        $this->assertCount(1, $m->specs);
        $this->assertSame('پردازنده', $m->specs[0]['label']['fa']);
        $this->assertSame('۲× Xeon', $m->specs[0]['fa']);

        // در سایت هم دیده شود
        $this->get('/servers/new-one')->assertOk()->assertSee('سرور آزمایشی');
    }

    public function test_empty_spec_rows_are_dropped(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/server-shop', $this->payload([
            'slug' => 'spec-test',
            'spec_label_fa' => ['پردازنده', '', ''], 'spec_label_en' => ['CPU', '', ''], 'spec_label_tr' => ['CPU', '', ''],
            'spec_val_fa' => ['x', '', ''], 'spec_val_en' => ['x', '', ''], 'spec_val_tr' => ['x', '', ''],
        ]));

        $this->assertCount(1, PhysicalServer::where('slug', 'spec-test')->first()->specs);
    }

    public function test_admin_can_update(): void
    {
        $m = PhysicalServer::where('slug', 'hpe-proliant-dl360-gen10')->first();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/server-shop/'.$m->id, $this->payload([
                'slug' => 'hpe-proliant-dl360-gen10',
                'name_fa' => 'نامِ ویرایش‌شده',
            ]))
            ->assertRedirect('/admin/server-shop');

        $this->assertSame('نامِ ویرایش‌شده', $m->fresh()->name['fa']);
    }

    public function test_admin_can_delete(): void
    {
        $m = PhysicalServer::where('slug', 'supermicro-superserver')->first();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/server-shop/'.$m->id.'/delete')
            ->assertRedirect('/admin/server-shop');

        $this->assertNull(PhysicalServer::find($m->id));
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/server-shop', $this->payload(['slug' => 'hpe-proliant-dl380-gen10']))
            ->assertSessionHasErrors('slug');
    }

    public function test_invalid_brand_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/server-shop', $this->payload(['slug' => 'bad-brand', 'brand' => 'acme']))
            ->assertSessionHasErrors('brand');
    }

    public function test_admin_index_lists_servers(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/server-shop')
            ->assertOk()
            ->assertSee('فروشگاهِ سرورِ فیزیکی');
    }

    public function test_edit_form_renders(): void
    {
        $m = PhysicalServer::first();

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/server-shop/'.$m->id.'/edit')
            ->assertOk()
            ->assertSee('مشخصاتِ فنی');
    }

    public function test_guest_cannot_manage(): void
    {
        $this->post('/admin/server-shop', $this->payload(['slug' => 'x']))
            ->assertRedirect();  // به لاگین

        $this->assertNull(PhysicalServer::where('slug', 'x')->first());
    }
}
