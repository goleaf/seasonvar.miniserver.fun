<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;
use NumberFormatter;

final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency,
    ) {
        if ($minor < 0 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('Некорректная сумма или код валюты.');
        }
    }

    public static function from(int $minor, string $currency): self
    {
        return new self($minor, mb_strtoupper(trim($currency)));
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function format(string $locale): string
    {
        $currencyFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $currencyFormatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $this->currency);
        $fractionDigits = $currencyFormatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        if ($fractionDigits < 0 || $fractionDigits > 4) {
            throw new InvalidArgumentException('Не удалось определить разрядность валюты.');
        }

        $minor = (string) $this->minor;
        $whole = $fractionDigits === 0
            ? $minor
            : (strlen($minor) > $fractionDigits ? substr($minor, 0, -$fractionDigits) : '0');
        $fraction = $fractionDigits === 0
            ? ''
            : str_pad(substr($minor, -$fractionDigits), $fractionDigits, '0', STR_PAD_LEFT);
        $numberFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $numberFormatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $numberFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $formattedWhole = $numberFormatter->format((int) $whole, NumberFormatter::TYPE_INT64);
        $formattedZero = $numberFormatter->format(0, NumberFormatter::TYPE_INT64);

        if (! is_string($formattedWhole) || ! is_string($formattedZero)) {
            throw new InvalidArgumentException('Не удалось отформатировать сумму.');
        }

        $formattedNumber = $formattedWhole;

        if ($fraction !== '') {
            $formattedNumber .= $numberFormatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)
                .$this->localizeDigits($fraction, $numberFormatter);
        }

        $currencyFormatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $currencyFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $currencyZero = $currencyFormatter->formatCurrency(0, $this->currency);

        if (! is_string($currencyZero)) {
            throw new InvalidArgumentException('Не удалось отформатировать валюту.');
        }

        $zeroPosition = mb_strpos($currencyZero, $formattedZero);

        if ($zeroPosition === false) {
            throw new InvalidArgumentException('Не удалось определить расположение валюты.');
        }

        return mb_substr($currencyZero, 0, $zeroPosition)
            .$formattedNumber
            .mb_substr($currencyZero, $zeroPosition + mb_strlen($formattedZero));
    }

    private function localizeDigits(string $digits, NumberFormatter $formatter): string
    {
        $localized = [];

        foreach (range(0, 9) as $digit) {
            $value = $formatter->format($digit, NumberFormatter::TYPE_INT64);

            if (! is_string($value)) {
                throw new InvalidArgumentException('Не удалось локализовать цифры суммы.');
            }

            $localized[(string) $digit] = $value;
        }

        return strtr($digits, $localized);
    }
}
