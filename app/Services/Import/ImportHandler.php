<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Models\User;

/**
 * The contract between the shared CSV pipeline (DB-003, ROUND2-SPEC §4) and one import kind. Implementations:
 * `App\Services\Roster\RosterImportHandler` (kind `roster`) and `App\Services\Parking\PermitImportHandler` (kind
 * `permits`, PARK-012); registered in config('majas.import.handlers'), resolved by ImportRunService::handler().
 *
 * The pipeline owns parsing (CsvReader), mapping (ColumnMapper), the stages and statuses (ImportRunService), the
 * shared row checks (RowValidator: required_missing, too_long, apartment_not_found / apartment_merged,
 * duplicate_in_file), sealing (ImportVault), the commit job (CommitImport: advisory lock per chunk of 100, every row
 * re-validated inside its own savepoint) and the audit rows (counts only). A handler owns its fields, its gate, its
 * domain checks and its writes — always through the domain services, never raw inserts.
 *
 * Rules for implementations: validation results are CODES (never values); preview cells are MASKED where the value is
 * sensitive (phones, e-mails, plates); nothing a handler returns may be logged.
 */
interface ImportHandler
{
    /**
     * `roster` | `permits` (import_runs.kind).
     */
    public function kind(): string;

    /**
     * Target fields in display order, keyed by field key (`apartment_number` is resolved by the pipeline itself).
     *
     * @return array<string, ImportField>
     */
    public function fields(): array;

    /**
     * Decision D6: null when the kind's gate is open in the CURRENT building; otherwise the plain-language labels of
     * what is missing. Upload, mapping, validation and commit are all refused while it is closed.
     *
     * @return list<string>|null
     */
    public function closedGate(): ?array;

    /**
     * Role check for upload / map / dry-run / commit / discard in the current building (gate checked separately).
     */
    public function mayImport(User $user): bool;

    /**
     * Role check for the runtime CSV template (no personal data; works below the gate).
     */
    public function mayDownloadTemplate(User $user): bool;

    /**
     * 32-byte master key that seals this kind's staged CSV (ImportVault): CONTACT_ENC_KEY for the roster,
     * PLATE_ENC_KEY for permits.
     */
    public function vaultKey(): string;

    /**
     * Confirmation checkboxes required before the commit (lang keys `import.confirm.<key>`), e.g. `art14`, `endorsed`.
     *
     * @return list<string>
     */
    public function confirmations(): array;

    /**
     * The kind's own validation codes with their severity (`error` = row skipped, `warning` = row imported,
     * `skip` = row deliberately not written, e.g. `exists_skip`). Lang keys `import.code.<code>`.
     *
     * @return array<string, 'error'|'warning'|'skip'>
     */
    public function codes(): array;

    /**
     * Header row in $locale and ONE obviously synthetic example row (no phone- or plate-shaped strings: the
     * personal-data guard scans the repository), same order as fields().
     *
     * @return array{headers: list<string>, example: list<string>}
     */
    public function template(string $locale): array;

    /**
     * Load the database state the rows of one batch are checked against (existing records of the apartments in the
     * batch, plate conflicts, …) into $context->cache. Called once per dry-run / preview page and once per commit
     * chunk (inside the advisory lock), so a commit always sees the current state.
     *
     * @param  list<RowResult>  $rows  rows that passed the shared checks
     */
    public function prepare(ImportContext $context, array $rows): void;

    /**
     * Domain checks of one row: add codes, set $result->action (create / update / skip), the normalised values the
     * commit needs ($result->values) and the masked preview cells ($result->preview).
     */
    public function validate(ImportRow $row, RowResult $result, ImportContext $context): void;

    /**
     * Keys that identify the same record within ONE file (duplicate_in_file), e.g. apartment + e-mail. Called in
     * phase 1 (after the shared checks; $result->apartmentId is resolved), no database access needed.
     *
     * @return list<string>
     */
    public function dedupeKeys(ImportRow $row, RowResult $result): array;

    /**
     * Write one validated row through the domain services (inside its own savepoint, gate open, advisory lock held).
     *
     * @return 'created'|'updated'|'skipped'
     */
    public function commit(RowResult $result, ImportRun $run, User $actor): string;
}
