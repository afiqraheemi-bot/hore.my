<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workspace;

use App\Domain\Workspace\TaskService;
use PHPUnit\Framework\TestCase;

/**
 * Structural proof for ADR-0009 and WTS-002 PTC-002/PTC-009: the
 * Workspace translation boundary delegates to the five existing
 * Recording Services and has no direct Accounting persistence path.
 * Runtime financial behavior is proven separately against PostgreSQL
 * by TaskServiceIntegrationTest.
 */
final class WorkspaceTranslationBoundaryTest extends TestCase
{
    public function test_translation_delegates_to_existing_recording_services_only(): void
    {
        $source = $this->taskServiceSource();

        foreach ([
            'ExpenseRecordingService',
            'IncomeRecordingService',
            'TransferRecordingService',
            'OwnerEquityTransactionRecordingService',
        ] as $service) {
            $this->assertStringContainsString($service, $source);
        }

        $this->assertStringNotContainsString('InvoiceIssuingService', $source);
        $this->assertStringNotContainsString('PaymentRecordingService', $source);
    }

    public function test_workspace_has_no_direct_accounting_persistence_dependency(): void
    {
        $source = $this->taskServiceSource();

        foreach ([
            'use App\\Infrastructure\\Accounting',
            'JournalRepository',
            'AuditEventRepository',
            'PostingIdempotencyRepository',
            'DB::',
            '->table(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    private function taskServiceSource(): string
    {
        $reflection = new \ReflectionClass(TaskService::class);
        $filename = $reflection->getFileName();
        $this->assertIsString($filename);

        $source = file_get_contents($filename);
        $this->assertIsString($source);

        return $source;
    }
}
