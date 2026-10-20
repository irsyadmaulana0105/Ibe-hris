<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;

/**
 * Multi-currency operations untuk Finance + Purchase Orders.
 *
 * Base currency: IDR (Indonesia Rupiah).
 * Untuk dapet rate, query exchange_rates table dengan valid_from <= tanggal transaksi,
 * order DESC by valid_from, ambil 1.
 *
 * Kalau gak ketemu, throw exception - admin harus input rate manual.
 */
class CurrencyService
{
    public function base(): string
    {
        return Currency::query()->where('is_base', true)->value('code') ?? 'IDR';
    }

    /**
     * Get rate dari $from currency ke base (IDR) pada tanggal $asOf.
     * Return 1.0 kalau $from = base.
     */
    public function getRate(int $companyId, string $from, ?Carbon $asOf = null): float
    {
        $base = $this->base();
        if ($from === $base) return 1.0;

        $asOf = $asOf ?? Carbon::today();

        $rate = ExchangeRate::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('from_currency', $from)
            ->where('to_currency', $base)
            ->where('valid_from', '<=', $asOf->toDateString())
            ->orderByDesc('valid_from')
            ->value('rate');

        if (!$rate) {
            throw new \RuntimeException(
                "Tidak ada exchange rate {$from} → {$base} per " . $asOf->toDateString() .
                ". Input rate di Settings → Exchange Rates."
            );
        }

        return (float) $rate;
    }

    public function convertToBase(int $companyId, float $amount, string $fromCurrency, ?Carbon $asOf = null): array
    {
        $rate = $this->getRate($companyId, $fromCurrency, $asOf);
        return [
            'rate'         => $rate,
            'original'     => $amount,
            'in_base'      => $amount * $rate,
            'base_code'    => $this->base(),
            'from_code'    => $fromCurrency,
        ];
    }

    /** Format amount sesuai locale + decimals dari currency. */
    public function format(float $amount, string $code): string
    {
        $currency = Currency::find($code);
        if (!$currency) return number_format($amount, 0, ',', '.') . ' ' . $code;
        $decimals = $currency->decimals;
        $formatted = number_format($amount, $decimals, ',', '.');
        return ($currency->symbol ?? $code) . ' ' . $formatted;
    }
}
