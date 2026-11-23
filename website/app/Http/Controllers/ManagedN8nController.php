<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Account\AccountController;
use App\Services\Cloud\ManagedN8nCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManagedN8nController extends Controller
{
    public function landing(): View
    {
        $data = $this->data();
        return view('pages.n8n', $data + ['n8nSeo' => app(\App\Services\Cloud\ManagedN8nSeo::class)
            ->present($data['n8nCards'], $data['n8nEnabled'], $data['n8nCountries'])]);
    }

    public function store(Request $request): View
    {
        return view('account.n8n-store', AccountController::shell('store') + $this->data());
    }

    private function data(): array
    {
        $catalog = app(ManagedN8nCatalog::class);
        $cards = $catalog->cards();
        // Public specifications are discoverable in Persian before sign-in.
        // IranSalesGate still validates identity at the actual purchase boundary.
        $iranRestricted = app()->getLocale() !== 'fa';
        if ($iranRestricted) {
            $cards = array_values(array_filter($cards, fn ($p) => $p['country'] !== 'IR'));
        }
        return ['n8nCards' => $cards, 'n8nEnabled' => $catalog->enabled(),
            'n8nCountries' => app()->getLocale() === 'fa' ? ['IR', 'DE', 'TR'] : ['DE', 'TR'],
            'n8nIranRestricted' => $iranRestricted,
            'n8nTax' => \App\Http\Controllers\Account\CloudStoreController::taxPercent()];
    }
}
