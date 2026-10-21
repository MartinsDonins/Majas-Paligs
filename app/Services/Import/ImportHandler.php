<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportKind;
use App\Models\ImportRun;
use App\Models\User;

/**
 * One import kind plugged into the shared pipeline (DB-003, ROUND2-SPEC §4): its target fields and synonyms, its gate
 * (D6 — the upload itself is refused below it), its row validator, how a preview cell is masked and how one validated
 * record is written through the domain services (never raw inserts). Implementations: PermitImportHandler (permits,
 * parking L1), RosterImportHandler (roster, L-R).
 */
interface ImportHandler
{
    public function kind(): ImportKind;

    /**
     * Target fields: target => ['required' => bool, 'synonyms' => list<string> (LV / RU / EN), 'max' => ?int].
     *
     * @return array<string, array{required: bool, synonyms: list<string>, max?: int}>
     */
    public function targets(): array;

    /**
     * May this user upload / map / commit for this kind in the current building (policy incl. the gate)?
     */
    public function canImport(User $user): bool;

    /**
     * Is the kind's gate open in the current building?
     */
    public function gateOpen(): bool;

    /**
     * @throws ImportFailed gate_closed
     */
    public function assertGateOpen(): void;

    /**
     * Plain-language missing gate items (the explanation card below the gate).
     *
     * @return list<string>
     */
    public function missingGateItems(): array;

    /**
     * A fresh validator for one pass over the run's file.
     */
    public function validator(ImportRun $run): RowValidator;

    /**
     * Masked preview cell for a target (plates `AB••34`, phones `+371 2•••••45`, …).
     */
    public function mask(string $target, string $value): string;

    /**
     * Write one validated record (called inside its savepoint after re-validation). Returns created | updated |
     * skipped.
     */
    public function commitRow(User $actor, ImportRun $run, RowResult $row): string;
}
