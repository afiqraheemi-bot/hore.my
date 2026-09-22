<?php

declare(strict_types=1);

namespace App\Domain\MyInvois;

/**
 * Which MyInvois environment a `MyInvoisCredential` belongs to
 * (AETS-013 v0.1.0 §5, MYI-006) — `HORE_MY_MASTER_CONTEXT.md` §13
 * requires these never be mixed; LHDN itself states credentials from
 * one environment are rejected by the other, so this is a closed set,
 * never inferred from context.
 */
enum MyInvoisEnvironment: string
{
    case Sandbox = 'Sandbox';
    case Production = 'Production';
}
