<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Banking\Reconciliation;
use App\Domain\Customers\Exception\InvalidCustomerEmailException;
use App\Domain\Customers\Exception\InvalidCustomerNameException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Customer aggregate root (M19) — a Tenant's own record of a
 * customer they sell to. Foundation-only for Modul 7 ("Pelanggan,
 * sebut harga, invois dan bayaran"): this is pure reference data, with
 * no Journal/Posting participation of its own — Invoicing (a future
 * milestone, its own Architecture Review) is what will eventually post
 * against a Customer's receivable balance.
 *
 * **Immutable, every edit returns a new instance** — mirrors
 * {@see Reconciliation}'s own convention, since
 * unlike `BankAccount` (write-once reference data) a Customer's
 * details are expected to be edited after creation.
 */
final class Customer
{
    private const MAX_NAME_LENGTH = 255;

    private const MAX_PHONE_LENGTH = 32;

    private const MAX_ADDRESS_LENGTH = 500;

    private const MAX_TAX_ID_LENGTH = 64;

    private const MAX_NOTES_LENGTH = 1000;

    private function __construct(
        private readonly CustomerId $id,
        private readonly TenantId $tenantId,
        private readonly string $name,
        private readonly ?string $email,
        private readonly ?string $phone,
        private readonly ?string $address,
        private readonly ?string $taxIdentificationNumber,
        private readonly ?string $notes,
        private readonly bool $active,
    ) {}

    /**
     * @throws InvalidCustomerNameException if `$name` is empty or exceeds
     *                                      the defensive length bound.
     * @throws InvalidCustomerEmailException if `$email` is present but
     *                                       malformed.
     */
    public static function register(
        CustomerId $id,
        TenantId $tenantId,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $taxIdentificationNumber,
        ?string $notes,
    ): self {
        self::assertValid($name, $email, $phone, $address, $taxIdentificationNumber, $notes);

        return new self($id, $tenantId, $name, $email, $phone, $address, $taxIdentificationNumber, $notes, true);
    }

    /**
     * Reconstruct an already-registered Customer from persisted state —
     * no validation beyond each field's own bound, mirroring
     * `BankAccount::reconstitute()`'s own reasoning.
     */
    public static function reconstitute(
        CustomerId $id,
        TenantId $tenantId,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $taxIdentificationNumber,
        ?string $notes,
        bool $active,
    ): self {
        return new self($id, $tenantId, $name, $email, $phone, $address, $taxIdentificationNumber, $notes, $active);
    }

    /**
     * @throws InvalidCustomerNameException if `$name` is empty or exceeds
     *                                      the defensive length bound.
     * @throws InvalidCustomerEmailException if `$email` is present but
     *                                       malformed.
     */
    public function update(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $taxIdentificationNumber,
        ?string $notes,
    ): self {
        self::assertValid($name, $email, $phone, $address, $taxIdentificationNumber, $notes);

        return new self($this->id, $this->tenantId, $name, $email, $phone, $address, $taxIdentificationNumber, $notes, $this->active);
    }

    public function deactivate(): self
    {
        return new self($this->id, $this->tenantId, $this->name, $this->email, $this->phone, $this->address, $this->taxIdentificationNumber, $this->notes, false);
    }

    public function activate(): self
    {
        return new self($this->id, $this->tenantId, $this->name, $this->email, $this->phone, $this->address, $this->taxIdentificationNumber, $this->notes, true);
    }

    public function id(): CustomerId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function taxIdentificationNumber(): ?string
    {
        return $this->taxIdentificationNumber;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    private static function assertValid(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $taxIdentificationNumber,
        ?string $notes,
    ): void {
        if ($name === '') {
            throw InvalidCustomerNameException::forEmpty();
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidCustomerNameException::forExceedingMaxLength(self::MAX_NAME_LENGTH);
        }

        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidCustomerEmailException::forValue($email);
        }

        if ($phone !== null && strlen($phone) > self::MAX_PHONE_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A Customer phone must not exceed %d characters.', self::MAX_PHONE_LENGTH));
        }

        if ($address !== null && strlen($address) > self::MAX_ADDRESS_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A Customer address must not exceed %d characters.', self::MAX_ADDRESS_LENGTH));
        }

        if ($taxIdentificationNumber !== null && strlen($taxIdentificationNumber) > self::MAX_TAX_ID_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A Customer tax identification number must not exceed %d characters.', self::MAX_TAX_ID_LENGTH));
        }

        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Customer notes must not exceed %d characters.', self::MAX_NOTES_LENGTH));
        }
    }
}
