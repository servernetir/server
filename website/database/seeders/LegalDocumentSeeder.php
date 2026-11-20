<?php

namespace Database\Seeders;

use App\Services\Legal\LegalDocuments;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('legal_documents')) {
            return;
        }
        foreach (['fa', 'en', 'tr'] as $locale) {
            app(LegalDocuments::class)->publishCurrent($locale);
        }
    }
}
