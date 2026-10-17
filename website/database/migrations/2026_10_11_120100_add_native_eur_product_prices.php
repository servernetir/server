<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'base_price_eur')) {
                $table->decimal('base_price_eur', 24, 12)->nullable();
            }
            if (! Schema::hasColumn('products', 'base_setup_eur')) {
                $table->decimal('base_setup_eur', 24, 12)->nullable();
            }
        });

        // The retired IRT anchor is read only for this one-time conversion.
        // Runtime pricing reads native EUR plus the stored Navasan quote.
        DB::transaction(function () {
            $rows = DB::table('products')->where(function ($query) {
                $query->whereNull('base_price_eur')->orWhereNull('base_setup_eur');
            })->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return;
            }

            $baseline = DB::table('settings')->where('key', 'pricing_baseline_rate')->value('value');
            if (! is_numeric($baseline) || ! is_finite((float) $baseline) || $baseline <= 0) {
                throw new RuntimeException('A valid historical EUR anchor is required for the one-time product conversion.');
            }

            foreach ($rows as $row) {
                if (($row->currency_code ?: 'IRT') !== 'IRT' || $row->price < 0 || $row->setup_fee < 0) {
                    throw new RuntimeException('An unsupported historical product amount requires explicit conversion.');
                }

                $values = [];
                foreach (['base_price_eur' => 'price', 'base_setup_eur' => 'setup_fee'] as $native => $legacy) {
                    if ($row->{$native} === null) {
                        $values[$native] = (string) BigDecimal::of((string) $row->{$legacy})
                            ->dividedBy((string) $baseline, 12, RoundingMode::HalfUp);
                    }
                }
                DB::table('products')->where('id', $row->id)->update($values);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['base_price_eur', 'base_setup_eur'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
