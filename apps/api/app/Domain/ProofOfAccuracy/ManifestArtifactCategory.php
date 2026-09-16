<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * Which part of AETS-012 §5.1's manifest distinction one artifact
 * belongs to — original sources, manually approved canonical facts, or
 * expected accounting outputs. `Command` (deterministic command
 * inputs) is its own category since AETS-012 §5.1 lists it separately
 * from canonical facts.
 */
enum ManifestArtifactCategory
{
    case Source;
    case Canonical;
    case Command;
    case Expected;
}
