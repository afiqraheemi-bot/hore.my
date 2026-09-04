<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountNameException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers AccountName-scoped behavior supported by AETS-005 §9 and
 * ATS-005. ATS-005 has no dedicated construction-level test ID for
 * AccountName itself — `COA-T003` ("An Account cannot be constructed
 * with an empty Name") is an Account-level test this suite only
 * partially proxies (the empty-value rejection), and `COA-T029`–
 * `COA-T031` (§11, Account Name Tests) are about the rename
 * *operation* on Account, not this Value Object's own construction —
 * see M2-T5.1B's report for the full traceability-gap list. `Account`
 * itself is not modified by this task.
 */
final class AccountNameTest extends TestCase
{
    /**
     * A valid, human-readable name is accepted.
     */
    public function test_valid_name_is_accepted(): void
    {
        $name = AccountName::of('Cash / Bank');

        $this->assertInstanceOf(AccountName::class, $name);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $name = AccountName::of('Accounts Receivable');

        $this->assertSame('Accounts Receivable', $name->toString());
    }

    /**
     * Value equality: two AccountName instances constructed from the
     * same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = AccountName::of('Cash / Bank');
        $b = AccountName::of('Cash / Bank');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = AccountName::of('Cash / Bank');
        $b = AccountName::of('Accounts Receivable');

        $this->assertFalse($a->equals($b));
    }

    /**
     * COA-T003 (partial — Account-level test, proxied here at the
     * Value Object's own construction): empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of(' Cash / Bank');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of('Cash / Bank ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of("Cash / Bank\0");
    }

    /**
     * A newline embedded in an otherwise plausible name is rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of("Cash / Bank\n");
    }

    /**
     * An adversarially long name is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        AccountName::of(str_repeat('A', 1000));
    }

    /**
     * AccountName is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_account_name_is_immutable(): void
    {
        $reflection = new ReflectionClass(AccountName::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                sprintf('Public method "%s" must not be a mutator.', $method->getName()),
            );
        }
    }

    /**
     * No database semantics: AccountName does not extend, implement,
     * or otherwise depend on any Eloquent or database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(AccountName::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(AccountName::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * AccountName exposes no localization/i18n-related method — only
     * construction, exact-string output, and equality. No such policy
     * is invented here (AETS-005 §9).
     */
    public function test_exposes_only_construction_output_and_equality(): void
    {
        $reflection = new ReflectionClass(AccountName::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }
}
