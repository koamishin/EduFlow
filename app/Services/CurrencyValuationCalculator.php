<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Exact USDC reference valuation; not an executable FX quote or payment instruction. */
final class CurrencyValuationCalculator
{
    /** @return array{source_minor_units: string, valuation_base_units: string, source_per_usdc: string, rounding: string} */
    public function calculate(string $amount, CurrencyCode $currency, string $sourcePerUsdc, string $rounding): array
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D', $sourcePerUsdc)) {
            throw new InvalidArgumentException('Valuation requires an explicit positive decimal reference rate.');
        }
        $rate = BigDecimal::of($sourcePerUsdc)->strippedOfTrailingZeros();
        if ($rate->isLessThanOrEqualTo(0) || ($currency === CurrencyCode::USDC && ! $rate->isEqualTo(1))) {
            throw new InvalidArgumentException('Reference rate must be positive; USDC source requires identity rate 1.');
        }
        $mode = match ($rounding) {
            'down' => RoundingMode::Down,
            'half_up' => RoundingMode::HalfUp,
            'up' => RoundingMode::Up,
            default => throw new InvalidArgumentException('An explicit supported valuation rounding rule is required.'),
        };
        $source = Money::fromDecimal($amount, $currency);
        if ($source->minorUnits <= 0) {
            throw new InvalidArgumentException('Invoice source amount must be positive.');
        }

        $numerator = BigInteger::of($source->minorUnits)->multipliedBy(CurrencyCode::USDC->multiplier())
            ->multipliedBy(BigInteger::of(10)->power($rate->getScale()));
        $denominator = $rate->getUnscaledValue()->multipliedBy($currency->multiplier());
        $valuation = $numerator->dividedBy($denominator, $mode)->toInt();
        if ($valuation <= 0) {
            throw new InvalidArgumentException('Reference mapping produces no positive USDC reference valuation.');
        }

        return [
            'source_minor_units' => (string) $source->minorUnits,
            'valuation_base_units' => (string) $valuation,
            'source_per_usdc' => (string) $rate,
            'rounding' => $rounding,
        ];
    }
}
