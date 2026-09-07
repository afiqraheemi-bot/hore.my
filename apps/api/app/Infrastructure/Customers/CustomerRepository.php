<?php

declare(strict_types=1);

namespace App\Infrastructure\Customers;

use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankAccountRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Customer aggregate (M19), through
 * the production `customers` table — mirrors
 * {@see BankAccountRepository}'s own
 * shape, since Customer is reference data with the identical
 * persistence needs (no transaction participation required).
 */
final class CustomerRepository
{
    private const TABLE = 'customers';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function save(Customer $customer): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $customer->id()->toString(),
            'tenant_id' => $customer->tenantId()->toString(),
            'name' => $customer->name(),
            'email' => $customer->email(),
            'phone' => $customer->phone(),
            'address' => $customer->address(),
            'tax_identification_number' => $customer->taxIdentificationNumber(),
            'notes' => $customer->notes(),
            'active' => $customer->isActive(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(Customer $customer): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $customer->tenantId()->toString())
            ->where('id', $customer->id()->toString())
            ->update([
                'name' => $customer->name(),
                'email' => $customer->email(),
                'phone' => $customer->phone(),
                'address' => $customer->address(),
                'tax_identification_number' => $customer->taxIdentificationNumber(),
                'notes' => $customer->notes(),
                'active' => $customer->isActive(),
                'updated_at' => now(),
            ]);
    }

    public function findById(TenantId $tenantId, CustomerId $customerId): ?Customer
    {
        /** @var object{id: string, tenant_id: string, name: string, email: string|null, phone: string|null, address: string|null, tax_identification_number: string|null, notes: string|null, active: bool}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $customerId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<Customer>
     */
    public function findAllByTenant(TenantId $tenantId): array
    {
        /** @var list<object{id: string, tenant_id: string, name: string, email: string|null, phone: string|null, address: string|null, tax_identification_number: string|null, notes: string|null, active: bool}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderBy('name')
            ->get()
            ->all();

        return array_map(fn (object $row): Customer => $this->fromPersisted($row), $rows);
    }

    /**
     * @param  object{id: string, tenant_id: string, name: string, email: string|null, phone: string|null, address: string|null, tax_identification_number: string|null, notes: string|null, active: bool}  $row
     */
    private function fromPersisted(object $row): Customer
    {
        return Customer::reconstitute(
            CustomerId::of($row->id),
            TenantId::of($row->tenant_id),
            $row->name,
            $row->email,
            $row->phone,
            $row->address,
            $row->tax_identification_number,
            $row->notes,
            (bool) $row->active,
        );
    }
}
