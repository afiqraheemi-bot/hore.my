<?php

declare(strict_types=1);

namespace App\Domain\MyInvois;

/**
 * LHDN MyInvois's own Tax Type code table (AETS-013 v0.1.0 §8),
 * transcribed from `sdk.myinvois.hasil.gov.my/codes/tax-types/`
 * (reviewed 2026-09-22, per this module's own requirement to check
 * current official documentation before implementation). A closed set
 * this codebase does not extend or reinterpret.
 */
enum MyInvoisTaxType: string
{
    case SalesTax = '01';
    case ServiceTax = '02';
    case TourismTax = '03';
    case HighValueGoodsTax = '04';
    case SalesTaxOnLowValueGoods = '05';
    case NotApplicable = '06';
    case Exempt = 'E';
}
