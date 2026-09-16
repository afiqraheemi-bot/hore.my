<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Customers\Customer;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\QuotationController;
use App\Models\BusinessProfile;

/**
 * Shapes the "seller" (Business Profile) and "buyer" (Customer) party
 * data `resources/views/pdf/document.blade.php` renders (AETS-017) —
 * shared between {@see InvoiceController::pdf()}
 * and {@see QuotationController::pdf()} so
 * the identical formatting is never duplicated across the two.
 */
final class DocumentPartyFormatter
{
    /**
     * @return array{legal_name: string, address_lines: list<string>, registration_number: string|null, tin: string|null}
     */
    public static function sellerFromBusinessProfile(?BusinessProfile $profile): array
    {
        if ($profile === null) {
            return ['legal_name' => 'Unknown Business', 'address_lines' => [], 'registration_number' => null, 'tin' => null];
        }

        $addressLines = array_values(array_filter([
            $profile->address_line1,
            $profile->address_line2,
            trim(sprintf('%s, %s %s', $profile->postcode, $profile->city, $profile->state)),
        ], static fn (?string $line): bool => $line !== null && $line !== ''));

        return [
            'legal_name' => $profile->legal_name,
            'address_lines' => $addressLines,
            'registration_number' => $profile->registration_number,
            'tin' => $profile->tin,
        ];
    }

    /**
     * @return array{name: string, address: string|null, email: string|null, phone: string|null, tax_id: string|null}
     */
    public static function buyerFromCustomer(?Customer $customer): array
    {
        if ($customer === null) {
            return ['name' => 'Unknown Customer', 'address' => null, 'email' => null, 'phone' => null, 'tax_id' => null];
        }

        return [
            'name' => $customer->name(),
            'address' => $customer->address(),
            'email' => $customer->email(),
            'phone' => $customer->phone(),
            'tax_id' => $customer->taxIdentificationNumber(),
        ];
    }
}
