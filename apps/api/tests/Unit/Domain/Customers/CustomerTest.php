<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customers;

use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Customers\Exception\InvalidCustomerEmailException;
use App\Domain\Customers\Exception\InvalidCustomerNameException;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function test_registers_a_valid_customer(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            'aminah@example.com',
            '0123456789',
            'No. 1, Jalan Contoh',
            'C1234567890',
            'Pelanggan tetap',
        );

        $this->assertSame('Kedai Runcit Aminah', $customer->name());
        $this->assertSame('aminah@example.com', $customer->email());
        $this->assertTrue($customer->isActive());
    }

    public function test_registers_with_all_optional_fields_null(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
        );

        $this->assertNull($customer->email());
        $this->assertNull($customer->phone());
        $this->assertNull($customer->address());
        $this->assertNull($customer->taxIdentificationNumber());
        $this->assertNull($customer->notes());
    }

    public function test_rejects_an_empty_name(): void
    {
        $this->expectException(InvalidCustomerNameException::class);

        Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            '',
            null,
            null,
            null,
            null,
            null,
        );
    }

    public function test_rejects_a_name_exceeding_the_max_length(): void
    {
        $this->expectException(InvalidCustomerNameException::class);

        Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            str_repeat('a', 256),
            null,
            null,
            null,
            null,
            null,
        );
    }

    public function test_rejects_a_malformed_email(): void
    {
        $this->expectException(InvalidCustomerEmailException::class);

        Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            'not-an-email',
            null,
            null,
            null,
            null,
        );
    }

    public function test_update_returns_a_new_instance_with_changed_details(): void
    {
        $original = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            'aminah@example.com',
            null,
            null,
            null,
            null,
        );

        $updated = $original->update('Kedai Runcit Aminah Sdn Bhd', 'new@example.com', '0198765432', null, null, null);

        $this->assertSame('Kedai Runcit Aminah', $original->name());
        $this->assertSame('Kedai Runcit Aminah Sdn Bhd', $updated->name());
        $this->assertSame('new@example.com', $updated->email());
        $this->assertSame('0198765432', $updated->phone());
        $this->assertTrue($updated->id()->equals($original->id()));
    }

    public function test_update_rejects_an_empty_name(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
        );

        $this->expectException(InvalidCustomerNameException::class);

        $customer->update('', null, null, null, null, null);
    }

    public function test_deactivate_and_activate_return_new_instances(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
        );

        $deactivated = $customer->deactivate();
        $this->assertTrue($customer->isActive());
        $this->assertFalse($deactivated->isActive());

        $reactivated = $deactivated->activate();
        $this->assertTrue($reactivated->isActive());
    }

    public function test_reconstitute_performs_no_validation(): void
    {
        $customer = Customer::reconstitute(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
            false,
        );

        $this->assertFalse($customer->isActive());
    }

    public function test_equals_compares_by_identifier(): void
    {
        $a = Customer::register(CustomerId::of('customer-0001'), TenantId::of('tenant-0001'), 'A', null, null, null, null, null);
        $b = Customer::register(CustomerId::of('customer-0001'), TenantId::of('tenant-0002'), 'B', null, null, null, null, null);
        $c = Customer::register(CustomerId::of('customer-0002'), TenantId::of('tenant-0001'), 'A', null, null, null, null, null);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
