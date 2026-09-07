<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Customers\Exception\InvalidCustomerEmailException;
use App\Domain\Customers\Exception\InvalidCustomerNameException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Customers\CustomerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Registers, lists, and edits a Tenant's own Customers (M19, Modul 7
 * foundation) — mirrors {@see BankAccountController}'s
 * own shape exactly, since Customer is reference data with the identical needs.
 * No Posting Command pipeline, no idempotency key: registering or
 * editing a Customer never touches the ledger.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $customers = $this->customerRepository->findAllByTenant($currentTenant->id());

        return response()->json(['data' => array_map(fn (Customer $c): array => $this->toArray($c), $customers)]);
    }

    public function store(StoreCustomerRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        try {
            $customer = Customer::register(
                CustomerId::of((string) Str::uuid()),
                $currentTenant->id(),
                $request->string('name')->toString(),
                self::nullableString($request, 'email'),
                self::nullableString($request, 'phone'),
                self::nullableString($request, 'address'),
                self::nullableString($request, 'tax_identification_number'),
                self::nullableString($request, 'notes'),
            );
        } catch (InvalidCustomerNameException|InvalidCustomerEmailException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->customerRepository->save($customer);

        return response()->json($this->toArray($customer), 201);
    }

    public function update(UpdateCustomerRequest $request, CurrentTenant $currentTenant, string $customerId): JsonResponse
    {
        $existing = $this->customerRepository->findById($currentTenant->id(), CustomerId::of($customerId));

        if ($existing === null) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        try {
            $customer = $existing->update(
                $request->string('name')->toString(),
                self::nullableString($request, 'email'),
                self::nullableString($request, 'phone'),
                self::nullableString($request, 'address'),
                self::nullableString($request, 'tax_identification_number'),
                self::nullableString($request, 'notes'),
            );
        } catch (InvalidCustomerNameException|InvalidCustomerEmailException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->has('active')) {
            $customer = $request->boolean('active') ? $customer->activate() : $customer->deactivate();
        }

        $this->customerRepository->update($customer);

        return response()->json($this->toArray($customer));
    }

    private static function nullableString(StoreCustomerRequest|UpdateCustomerRequest $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Customer $customer): array
    {
        return [
            'id' => $customer->id()->toString(),
            'name' => $customer->name(),
            'email' => $customer->email(),
            'phone' => $customer->phone(),
            'address' => $customer->address(),
            'tax_identification_number' => $customer->taxIdentificationNumber(),
            'notes' => $customer->notes(),
            'active' => $customer->isActive(),
        ];
    }
}
