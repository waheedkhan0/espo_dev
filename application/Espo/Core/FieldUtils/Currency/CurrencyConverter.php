<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\FieldUtils\Currency;

use RuntimeException;

/**
 * Converts currency values.
 */
class CurrencyConverter
{
    protected $configDataProvider;

    public function __construct(CurrencyConfigDataProvider $configDataProvider)
    {
        $this->configDataProvider = $configDataProvider;
    }

    /**
     * Convert a currency value to a specific currency.
     *
     * @throws RuntimeException
     */
    public function convert(CurrencyValue $value, string $targetCurrencyCode) : CurrencyValue
    {
        $amount = $value->getAmount();

        if (!$this->configDataProvider->hasCurrency($targetCurrencyCode)) {
            throw new RuntimeException("Can't convert currency to unknown currency '{$targetCurrencyCode}.");
        }

        $rate = $this->configDataProvider->getCurrencyRate($value->getCode());

        $targetRate = $this->configDataProvider->getCurrencyRate($targetCurrencyCode);

        $amount *= $rate;

        $amount /= $targetRate;

        return new CurrencyValue($amount, $targetCurrencyCode);
    }

    /**
     * Convert a currency value to the system default currency.
     */
    public function convertToDefault(CurrencyValue $value) : CurrencyValue
    {
        $targetCurrencyCode = $this->configDataProvider->getDefaultCurrency();

        return $this->convert($value, $targetCurrencyCode);
    }

    /**
     * Convert a currency value to a specific currency with specific rates.
     *
     * @throws RuntimeException
     */
    public function convertWithRates(
        CurrencyValue $value, string $targetCurrencyCode, CurrencyRates $rates
    ) : CurrencyValue {

        $amount = $value->getAmount();

        $currencyCode = $value->getCode();

        if (!$rates->hasRate($currencyCode)) {
            throw new RuntimeException("No rate for the currency '{$currencyCode}.");
        }

        if (!$rates->hasRate($targetCurrencyCode)) {
            throw new RuntimeException("No rate for the currency '{$targetCurrencyCode}.");
        }

        $rate = $rates->getRate($currencyCode);

        $targetRate = $rates->getRate($targetCurrencyCode);

        $amount *= $rate;

        $amount /= $targetRate;

        return new CurrencyValue($amount, $targetCurrencyCode);
    }
}
