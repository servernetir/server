<?php

namespace App\Services\Birthday;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class BirthdayCalculator
{
    /** Cost includes supplier taxes/FX fees; output tax is calculated separately. */
    public function line(int $retail, int $landedCost, int $taxBps): array
    {
        if ($retail < 1 || $retail > 1000000000000 || $landedCost < 1 || $landedCost > 1000000000000
            || $taxBps < 0 || $taxBps > 10000) {
            throw new InvalidArgumentException('Invalid birthday quote units or bounds');
        }
        // Never raise the agreed price or invent a discount for a product already below its cost.
        $net = min($retail, $landedCost);
        $discount = max(0, $retail - $landedCost);
        $tax = BigInteger::of($net)->multipliedBy($taxBps)->plus(5000)->dividedBy(10000, RoundingMode::Down)->toInt();

        return ['regular' => $retail, 'net' => $net, 'discount' => $discount, 'tax' => $tax,
            'total' => $net + $tax, 'known_cost' => $landedCost, 'below_cost' => $retail < $landedCost];
    }
}
