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
 * A currency value.
 */
class CurrencyValue
{
    private $amount;

    private $code;

    public function __construct(float $amount, string $code)
    {
        if (strlen($code) !== 3) {
            throw new RuntimeException("Bad currency code.");
        }

        $this->amount = $amount;
        $this->code = $code;
    }

    /**
     * Get an amount.
     */
    public function getAmount() : float
    {
        return $this->amount;
    }

    /**
     * Get a currency code.
     */
    public function getCode() : string
    {
        return $this->code;
    }

    /**
     * Add a currency value.
     */
    public function add(self $value) : self
    {
        $amount = $this->getAmount();

        if ($this->getCode() !== $value->getCode()) {
            throw new RuntimeException("Can't add a currency value with a different code.");
        }

        $amount += $value->getAmount();

        return new self($amount, $this->getCode());
    }

    /**
     * Subtract a currency value.
     */
    public function subtract(self $value) : self
    {
        $amount = $this->getAmount();

        if ($this->getCode() !== $value->getCode()) {
            throw new RuntimeException("Can't substract a currency value with a different code.");
        }

        $amount -= $value->getAmount();

        return new self($amount, $this->getCode());
    }

    /**
     * Multiply by a multiplier.
     */
    public function multiply(float $multiplier) : self
    {
        $amount = $this->getAmount();

        $amount *= $multiplier;

        return new self($amount, $this->getCode());
    }

    /**
     * Divide by a divider.
     */
    public function divide(float $divider) : self
    {
        $amount = $this->getAmount();

        $amount /= $divider;

        return new self($amount, $this->getCode());
    }

    /**
     * Round with a precision.
     */
    public function round(int $precision = 0) : self
    {
        $amount = round($this->getAmount(), $precision);

        return new self($amount, $this->getCode());
    }
}
