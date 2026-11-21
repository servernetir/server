<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Customer\IranSalesGate;
use App\Services\Customer\IranSalesVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IranSalesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_persian_guest_can_see_catalogue_but_not_purchase(): void
    {
        app()->setLocale('fa');
        $this->assertFalse(IranSalesVisibility::restricted());
        $this->assertTrue(IranSalesVisibility::allowsCountry('IR'));
        $this->assertTrue(IranSalesVisibility::allowsUrl('/vps/iran'));
        $this->assertFalse(IranSalesVisibility::allowsUrl('/en/vps/iran'));
        $this->assertTrue(IranSalesGate::blocksIranFor(null));
        Setting::put(IranSalesGate::SETTING, '1');
        $this->assertTrue(IranSalesGate::blocksIranFor(null));
    }

    public function test_foreign_locales_only_hide_iran_and_keep_other_destinations(): void
    {
        $menu = ['vps' => ['groups' => [['items' => [
            ['slug' => 'iran'],
            ['iso' => 'IR'],
            ['route' => ['catalog', ['category' => 'vps', 'slug' => 'iran']]],
            ['href' => '/cloud/ir-tehran'],
            ['slug' => 'germany'],
        ]]]]];
        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $this->assertTrue(IranSalesVisibility::restricted());
            $this->assertFalse(IranSalesVisibility::allowsCountry('ir-tehran'));
            $this->assertTrue(IranSalesVisibility::allowsCountry('DE'));
            $this->assertFalse(IranSalesVisibility::allowsUrl('/account/cloud-store?location=ir-tehran'));
            $this->assertSame([['slug' => 'germany']], IranSalesVisibility::menu($menu)['vps']['groups'][0]['items']);
        }
        app()->setLocale('fa');
        $this->assertSame($menu, IranSalesVisibility::menu($menu));
    }
}
