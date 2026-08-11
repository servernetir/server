<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\CrmSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * واردکردنِ دسته‌ایِ سرنخ از CSV.
 *
 * 🔴 محورِ اصلی: **هیچ ردیفی بی‌صدا رد نمی‌شود**. واردکردنِ خاموشِ ۵۰ ردیف که
 * ۴۰تایش رد شده، دقیقاً همان الگویِ «شکست نمی‌خورد، فقط اتفاق نمی‌افتد» است که
 * این پروژه سه بار خورده.
 */
class CrmImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content): string
    {
        $path = sys_get_temp_dir().'/crm-import-'.random_int(1, 99999).'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_a_plain_file_imports(): void
    {
        $path = $this->csv(
            "company,website,city,country,vertical\n"
            ."Smile Dental,https://smile-dental-dubai.ae,Dubai,AE,dental\n"
            ."Aurora Aesthetics,aurora-aesthetics.ae,Dubai,AE,aesthetic\n"
        );

        $this->artisan('crm:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(2, CrmLead::count());

        $second = CrmLead::where('company', 'Aurora Aesthetics')->first();
        $this->assertSame('https://aurora-aesthetics.ae', $second->website, 'بدونِ پروتکل هم باید کار کند');
        $this->assertSame('AE', $second->country);
        $this->assertSame('import', $second->source);

        unlink($path);
    }

    public function test_columns_are_read_by_name_not_by_position(): void
    {
        $path = $this->csv(
            "vertical,website,company\n"
            ."dental,https://x-clinic.ae,X Clinic\n"
        );

        $this->artisan('crm:import', ['file' => $path])->assertSuccessful();

        $lead = CrmLead::first();
        $this->assertSame('X Clinic', $lead->company);
        $this->assertSame('dental', $lead->vertical);

        unlink($path);
    }

    public function test_an_excel_bom_does_not_break_the_first_column(): void
    {
        $path = $this->csv("\xEF\xBB\xBFcompany,website\nBom Clinic,https://bom-clinic.ae\n");

        $this->artisan('crm:import', ['file' => $path])->assertSuccessful();

        $this->assertSame('Bom Clinic', CrmLead::first()?->company);

        unlink($path);
    }

    public function test_duplicates_suppressed_and_broken_rows_are_all_refused_out_loud(): void
    {
        CrmLead::create([
            'domain_hash' => CrmLead::hashFor('https://already.ae'),
            'company' => 'Already', 'website' => 'https://already.ae', 'stage' => 'new',
        ]);
        CrmSuppression::add('info@nope.ae', 'unsubscribe');

        $path = $this->csv(
            "company,website,email\n"
            ."Already Again,https://www.already.ae/contact,\n"       // تکراری
            .",https://no-name.ae,\n"                                 // ناقص
            ."Bad Url,not a url at all,\n"                            // نشانیِ نامعتبر
            ."Suppressed,https://nope.ae,info@nope.ae\n"              // فهرستِ سیاه
            ."Good One,https://good-clinic.ae,\n"                     // سالم
        );

        $this->artisan('crm:import', ['file' => $path])
            ->expectsOutputToContain('تکراری')
            ->expectsOutputToContain('ناقص')
            ->expectsOutputToContain('فهرستِ سیاه')
            ->assertSuccessful();

        $this->assertSame(2, CrmLead::count(), 'فقط ردیفِ سالم اضافه می‌شود');
        $this->assertNotNull(CrmLead::where('company', 'Good One')->first());

        unlink($path);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->csv("company,website\nDry Clinic,https://dry-clinic.ae\n");

        $this->artisan('crm:import', ['file' => $path, '--dry' => true])->assertSuccessful();

        $this->assertSame(0, CrmLead::count());

        unlink($path);
    }

    public function test_a_file_without_the_required_columns_fails_loudly(): void
    {
        $path = $this->csv("name,url\nX,https://x.ae\n");

        $this->artisan('crm:import', ['file' => $path])->assertFailed();

        unlink($path);
    }

    public function test_a_missing_file_fails(): void
    {
        $this->artisan('crm:import', ['file' => '/no/such/file.csv'])->assertFailed();
    }
}
