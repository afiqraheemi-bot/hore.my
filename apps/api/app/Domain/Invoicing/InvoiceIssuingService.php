<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Invoicing\Exception\CorruptInvoiceRecordException;
use App\Domain\Invoicing\Exception\EmptyInvoiceCannotBeIssuedException;
use App\Domain\Invoicing\Exception\InvalidInvoiceStatusTransitionException;
use App\Domain\Invoicing\Exception\InvoiceNotFoundException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Infrastructure\Invoicing\InvoiceNumberGenerator;
use App\Infrastructure\Invoicing\InvoiceRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that issues a Draft Invoice (M20) —
 * assigning it a Tenant-scoped sequential number, posting its Journal
 * (Debit Receivable / Credit Revenue) through the existing Posting
 * Command pipeline, and locking it, all atomically. Mirrors
 * {@see IncomeRecordingService}'s own
 * atomicity/idempotency reasoning, adapted for an Invoice that already
 * exists as a persisted Draft resource before this service ever runs
 * (unlike Income, which has no pre-existing Draft to look up).
 *
 * **Atomicity — number assignment, Journal, and the Invoice's own
 * status transition commit or roll back together.** This service wraps
 * the entire call in one outer database transaction, the same
 * reentrant-transaction technique
 * {@see IncomeRecordingService}'s own
 * docblock establishes. A rolled-back Issue attempt does not
 * permanently burn an Invoice number in the common failure case
 * (though a genuinely concurrent race still can — accepted, see
 * {@see InvoiceNumberGenerator}'s own docblock).
 *
 * **Concurrency — `SELECT ... FOR UPDATE` on the Invoice row is the
 * very first statement inside the transaction, before the Invoice is
 * even loaded into a domain object.** An earlier version of this class
 * loaded the Invoice, ran its Draft/Issued checks, and only *then*
 * opened the transaction — two concurrent Issue attempts against the
 * same Draft Invoice (different Idempotency Keys, e.g. a genuine
 * double-click or a retry racing the original) could each observe
 * `Draft`, each proceed to post its *own* Journal, and only the last
 * writer's `markIssued()` call would "win" the Invoice row — silently
 * orphaning the other Journal in the ledger with no Invoice ever
 * pointing back to it, a real double-posting of the same economic
 * event. The lock closes this: a second concurrent caller blocks until
 * the first transaction commits, then observes `Issued` and correctly
 * takes the replay-or-reject branch below instead of posting again.
 *
 * **Idempotency — the Journal's own `JournalId` is deterministically
 * derived from the Idempotency Key by the caller** (mirroring Income's
 * `DeterministicIdempotentId` convention), so
 * `PostingCommandTransactionalExecutor::execute()`'s own already-proven
 * replay/conflict/race-recovery guarantee is the single source of
 * truth for whether this is a first Issue attempt or a replay. On a
 * genuine replay, the Invoice is expected to already be `Issued` (from
 * the original call, in the same transaction as its Journal) — this
 * service looks it up again and returns it unchanged, performing zero
 * additional writes.
 */
final class InvoiceIssuingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceNumberGenerator $numberGenerator,
        private readonly InvoiceToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
    ) {}

    /**
     * @throws InvoiceNotFoundException if no Invoice
     *                                  exists for `$invoiceId` under this Tenant.
     * @throws InvalidInvoiceStatusTransitionException if the Invoice
     *                                                 is not `Draft` and this is not a replay of its own prior Issue.
     * @throws EmptyInvoiceCannotBeIssuedException if the Invoice
     *                                             has no lines.
     */
    public function issue(
        TenantId $tenantId,
        InvoiceId $invoiceId,
        JournalId $journalId,
        IdempotencyKey $idempotencyKey,
        ActorReference $actor,
        \DateTimeImmutable $issueDate,
    ): InvoiceIssuingResult {
        return $this->connection->transaction(function () use ($tenantId, $invoiceId, $journalId, $idempotencyKey, $actor, $issueDate): InvoiceIssuingResult {
            // Lock first, before any read informs a decision — see this
            // class's own docblock ("Concurrency"). `first()`'s return
            // value is discarded; only the row lock this acquires
            // matters here, `findById()` immediately below is what
            // actually loads the typed Invoice.
            $this->connection->table('invoices')
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $invoiceId->toString())
                ->lockForUpdate()
                ->first();

            $invoice = $this->invoiceRepository->findById($tenantId, $invoiceId);

            if ($invoice === null) {
                throw InvoiceNotFoundException::forId($invoiceId);
            }

            // An empty Invoice would otherwise reach Accounting Core as
            // a degenerate zero-amount Posting Command and fail there
            // with a less specific, less actionable error.
            if ($invoice->status() === InvoiceStatus::Draft && $invoice->lines() === []) {
                throw EmptyInvoiceCannotBeIssuedException::forInvoice($invoiceId);
            }

            if ($invoice->status() === InvoiceStatus::Issued) {
                if ($invoice->journalId() !== null && $invoice->journalId()->toString() === $journalId->toString()) {
                    return InvoiceIssuingResult::replayed($invoice);
                }

                throw InvalidInvoiceStatusTransitionException::forNonDraftInvoice($invoiceId, $invoice->status());
            }

            $postingCommand = $this->translator->translate($invoice, $journalId, $idempotencyKey, $actor, $issueDate);
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $reloaded = $this->invoiceRepository->findById($invoice->tenantId(), $invoice->id());

                if ($reloaded === null || $reloaded->status() !== InvoiceStatus::Issued) {
                    throw CorruptInvoiceRecordException::forDraftInvoiceWithReplayedJournal($invoice->id(), $journalId);
                }

                return InvoiceIssuingResult::replayed($reloaded);
            }

            $invoiceNumber = $this->numberGenerator->next($invoice->tenantId());
            $issued = $invoice->issue($invoiceNumber, $issueDate, $postingResult->journal()->id());

            $this->invoiceRepository->markIssued($issued);

            return InvoiceIssuingResult::newlyIssued($issued);
        });
    }
}
