<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\IdentityVerification;
use Illuminate\Support\Facades\Crypt;

/** Synthetic stored evidence; no identity provider is called. */
final class ShahkarFixture
{
    public static function verify(Customer $customer): IdentityVerification
    {
        $nationalId = str_pad((string) $customer->id, 10, '0', STR_PAD_LEFT);

        return IdentityVerification::updateOrCreate(['customer_id' => $customer->id], [
            'national_id_enc' => Crypt::encryptString($nationalId),
            'national_id_hash' => hash('sha256', 'synthetic-shahkar-'.$nationalId),
            'birth_date' => '1990-01-01', 'mobile' => $customer->phone ?? '',
            'shahkar_matched' => true, 'shahkar_at' => now(),
            'status' => 'verified', 'verified_at' => now(),
        ]);
    }
}
