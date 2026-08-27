<?php

namespace justinholtweb\headdy\helpers;

use Craft;
use craft\commerce\Plugin as Commerce;

/**
 * Money on the wire.
 *
 * Every amount Headdy emits is an object, never a bare float:
 *
 *     {"amount": "24.99", "minorUnits": 2499, "currency": "USD", "formatted": "$24.99"}
 *
 * `amount` is a decimal *string* because JSON numbers are IEEE 754 doubles and 24.99 is not one of
 * them — a client that parses the string with a decimal library gets the exact figure. `minorUnits`
 * is the integer a payment processor actually wants. `formatted` is for display and must never be
 * parsed: it is localised and its shape is not contract.
 */
abstract class Money
{
    /**
     * Digits per currency, memoized. `subunitFor()` walks the whole ISO table on every call and a
     * serialized order asks for the same currency a few dozen times.
     *
     * @var array<string, int>
     */
    private static array $_digits = [];

    public static function format(mixed $amount, string $currency): array
    {
        $amount = (float)($amount ?? 0);
        $digits = self::minorUnitDigits($currency);
        $rounded = round($amount, $digits);

        return [
            'amount' => number_format($rounded, $digits, '.', ''),
            'minorUnits' => (int)round($rounded * (10 ** $digits)),
            'currency' => $currency,
            'formatted' => self::formatAsCurrency($rounded, $currency),
        ];
    }

    /**
     * How many decimal places this currency subdivides into. JPY has none; most have two.
     */
    public static function minorUnitDigits(string $currency): int
    {
        if (isset(self::$_digits[$currency])) {
            return self::$_digits[$currency];
        }

        $digits = 2;

        try {
            $currencies = Commerce::getInstance()->getCurrencies();

            // getSubunitFor() fatals on a currency the ISO table does not know, so resolve first.
            if ($currencies->getCurrencyByIso($currency) !== null) {
                $digits = (int)$currencies->getSubunitFor($currency);
            }
        } catch (\Throwable) {
            // Fall through to two.
        }

        return self::$_digits[$currency] = $digits;
    }

    private static function formatAsCurrency(float $amount, string $currency): string
    {
        try {
            return Craft::$app->getFormatter()->asCurrency($amount, $currency);
        } catch (\Throwable) {
            // A currency Craft's formatter rejects is still worth returning a readable string for.
            return number_format($amount, self::minorUnitDigits($currency), '.', ',') . ' ' . $currency;
        }
    }
}
