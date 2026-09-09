<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workspace;

use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\ProposalProducerType;
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

    public function test_translation_command_type_set_is_explicitly_closed(): void
    {
        $this->assertSame([
            'Expense',
            'Income',
            'Transfer',
            'CapitalContribution',
            'OwnerDrawing',
        ], array_map(static fn (CommandType $type): string => $type->name, CommandType::cases()));
    }

    public function test_current_translation_has_no_ai_or_source_fingerprint_authority(): void
    {
        $source = $this->taskServiceSource();

        $this->assertStringContainsString('ProposalProducerType::Human', $source);
        $this->assertStringNotContainsString('ProposalProducerType::AI,', $source);
        $this->assertStringNotContainsString('SourceFingerprint', $source);
        $this->assertContains(ProposalProducerType::AI, ProposalProducerType::cases());
    }

    public function test_accounting_core_has_no_dependency_on_workspace_audit_records(): void
    {
        $root = dirname((new \ReflectionClass(TaskService::class))->getFileName(), 2);

        foreach (['Accounting'] as $module) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$module));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                $this->assertIsString($source);
                $this->assertStringNotContainsString('App\\Domain\\Workspace', $source, $file->getPathname());
                $this->assertStringNotContainsString('task_transitions', $source, $file->getPathname());
            }
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
