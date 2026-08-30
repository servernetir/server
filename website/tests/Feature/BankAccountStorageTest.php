<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Support\IranianBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * نگهداری شمارهٔ کارت و تشخیص بانک.
 *
 * محور: PAN کامل به درخواست کارفرما ذخیره می‌شود، ولی هرگز به شکل خام —
 * نه در دیتابیس، نه در خروجی مدل، نه روی صفحه.
 */
class BankAccountStorageTest extends TestCase
{
    use RefreshDatabase;

    private const CARD = '6037991234567893';

    private function account(): BankAccount
    {
        $c = Customer::create([
            'email' => 'b'.random_int(1, 99999).'@example.com',
            'password' => 'secret1234', 'status' => 'active',
        ]);

        return BankAccount::create([
            'customer_id'     => $c->id,
            'card_bin'        => substr(self::CARD, 0, 6),
            'card_last4'      => substr(self::CARD, -4),
            'card_number_enc' => self::CARD,
            'bank_slug'       => IranianBank::fromBin(self::CARD)['slug'] ?? null,
            'iban'            => 'IR060540105180021273113007',
            'status'          => 'verified',
            'name_matched'    => true,
            'verified_at'     => now(),
        ]);
    }

    /** ⚠ مهم‌ترین ادعا: دامپ دیتابیس نباید شمارهٔ کارت را لو بدهد */
    public function test_the_card_number_is_never_stored_in_plain_text(): void
    {
        $a = $this->account();

        $rawRow = DB::table('bank_accounts')->where('id', $a->id)->first();
        $stored = (string) $rawRow->card_number_enc;

        $this->assertNotSame(self::CARD, $stored);
        $this->assertStringNotContainsString(self::CARD, $stored);
        $this->assertNotEmpty($stored);

        // ولی خواندنش از طریق مدل باید همان کارت را بدهد
        $this->assertSame(self::CARD, $a->fresh()->card_number_enc);
    }

    public function test_the_card_number_is_hidden_from_model_output(): void
    {
        $a = $this->account();

        $this->assertArrayNotHasKey('card_number_enc', $a->toArray());
        $this->assertStringNotContainsString(self::CARD, $a->toJson());
    }

    public function test_the_bank_is_detected_from_the_card_prefix(): void
    {
        $a = $this->account();

        $this->assertSame('melli', $a->bank()['slug']);
        $this->assertSame('ملی ایران', $a->bankLabel());
        $this->assertNotEmpty($a->bank()['color']);
        $this->assertNotEmpty($a->bank()['short']);
    }

    /** بانک ناشناخته نباید چیزی را بشکند */
    public function test_an_unknown_prefix_degrades_gracefully(): void
    {
        $a = $this->account();
        $a->forceFill(['card_bin' => '999999', 'bank_slug' => null, 'bank_name' => 'بانک نامعلوم'])->save();

        $this->assertNull($a->fresh()->bank());
        // به نامی که سرویس داده برمی‌گردد، نه خطا
        $this->assertSame('بانک نامعلوم', $a->fresh()->bankLabel());
    }

    public function test_only_the_masked_card_is_shown(): void
    {
        $a = $this->account();

        $this->assertStringContainsString('603799', $a->maskedCard());
        $this->assertStringContainsString('7893', $a->maskedCard());
        $this->assertStringNotContainsString(self::CARD, $a->maskedCard());
    }

    /** هر BIN در جدول باید به بانکی اشاره کند که واقعاً تعریف شده */
    public function test_every_bin_maps_to_a_defined_bank(): void
    {
        foreach ((array) config('banks.bins') as $bin => $slug) {
            $this->assertNotNull(
                IranianBank::bySlug($slug),
                "BIN {$bin} به بانک تعریف‌نشدهٔ «{$slug}» اشاره می‌کند",
            );
            $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $bin, "BIN «{$bin}» شش‌رقمی نیست");
        }
    }
}
