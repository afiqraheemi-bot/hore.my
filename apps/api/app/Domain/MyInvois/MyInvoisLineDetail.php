<?php

declare(strict_types=1);

namespace App\Domain\MyInvois;

use App\Domain\Accounting\Money\Money;
use App\Domain\MyInvois\Exception\InvalidMyInvoisLineDetailException;

/**
 * The MyInvois-specific data one `InvoiceLine` needs that the core
 * accounting `InvoiceLine` itself deliberately does not carry
 * (AETS-013 v0.1.0 §6.1) — tax type/rate/amount, classification code,
 * and unit of measure. A Value Object, positionally paired with its
 * owning `InvoiceLine` by the caller (matching `InvoiceLine`'s own
 * "carries no identity beyond its position" convention) — this
 * milestone does not yet persist it (§11 deferred decisions); a caller
 * constructs it directly for `UblInvoiceDocumentBuilder`.
 */
final class MyInvoisLineDetail
{
    private function __construct(
        private readonly MyInvoisTaxType $taxType,
        private readonly string $taxRate,
        private readonly Money $taxAmount,
        private readonly string $classificationCode,
        private readonly string $unitOfMeasure,
    ) {}

    /**
     * @throws InvalidMyInvoisLineDetailException if `$classificationCode`/`$unitOfMeasure`
     *                                            is empty, or `$taxRate` is not a non-negative decimal.
     */
    public static function of(
        MyInvoisTaxType $taxType,
        string $taxRate,
        Money $taxAmount,
        string $classificationCode,
        string $unitOfMeasure,
    ): self {
        if ($classificationCode === '') {
            throw InvalidMyInvoisLineDetailException::forEmptyClassificationCode();
        }

        if ($unitOfMeasure === '') {
            throw InvalidMyInvoisLineDetailException::forEmptyUnitOfMeasure();
        }

        if (! is_numeric($taxRate) || (float) $taxRate < 0) {
            throw InvalidMyInvoisLineDetailException::forInvalidTaxRate($taxRate);
        }

        return new self($taxType, $taxRate, $taxAmount, $classificationCode, $unitOfMeasure);
    }

    public function taxType(): MyInvoisTaxType
    {
        return $this->taxType;
    }

    public function taxRate(): string
    {
        return $this->taxRate;
    }

    public function taxAmount(): Money
    {
        return $this->taxAmount;
    }

    public function classificationCode(): string
    {
        return $this->classificationCode;
    }

    public function unitOfMeasure(): string
    {
        return $this->unitOfMeasure;
    }
}
