<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use Illuminate\Database\QueryException;
use Tests\Support\DbRoles;

/*
 * AC 11: majas_app / majas_retention privileges on the append-only audit tables (MVP-SPEC A.2a).
 * Uses committed rows in a dedicated test chain (chain_key = building_id = 990001) and cleans up.
 */
const TEST_CHAIN = 990001;

afterEach(fn () => DbRoles::wipeChain(TEST_CHAIN));

function appInsertAudit(string $action = 'test.priv'): int
{
    $app = DbRoles::connect('majas_app');

    return (int) $app->table('audit_entries')->insertGetId([
        'chain_key' => TEST_CHAIN, 'building_id' => TEST_CHAIN, 'action' => $action, 'data' => '{}',
    ]);
}

it('lets majas_app insert and select audit rows', function (): void {
    $id = appInsertAudit();

    expect(DbRoles::connect('majas_app')->table('audit_entries')->where('id', $id)->exists())->toBeTrue();
});

it('forbids majas_app UPDATE, DELETE and TRUNCATE on audit_entries', function (string $sql): void {
    $id = appInsertAudit();

    expect(fn () => DbRoles::connect('majas_app')->statement(str_replace(':id', (string) $id, $sql)))
        ->toThrow(QueryException::class);
})->with([
    'update' => ["UPDATE audit_entries SET action = 'x.y' WHERE id = :id"],
    'delete' => ['DELETE FROM audit_entries WHERE id = :id'],
    'truncate' => ['TRUNCATE audit_entries'],
]);

it('forbids majas_app writes to audit_chain_heads and audit_chain_anchors', function (string $sql): void {
    appInsertAudit();

    expect(fn () => DbRoles::connect('majas_app')->statement($sql))->toThrow(QueryException::class);
})->with([
    'heads insert' => ['INSERT INTO audit_chain_heads (chain_key, last_id, last_hash) VALUES (990002, 1, decode(\'00\', \'hex\'))'],
    'heads update' => ['UPDATE audit_chain_heads SET last_id = 1 WHERE chain_key = 990001'],
    'heads delete' => ['DELETE FROM audit_chain_heads WHERE chain_key = 990001'],
    'anchors insert' => ['INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) VALUES (990001, 1, decode(\'00\', \'hex\'))'],
    'anchors update' => ['UPDATE audit_chain_anchors SET pruned_through_id = 1'],
    'anchors delete' => ['DELETE FROM audit_chain_anchors'],
]);

it('forbids majas_app from disabling the triggers (not the owner)', function (): void {
    DbRoles::connect('majas_app')->statement('ALTER TABLE audit_entries DISABLE TRIGGER ALL');
})->throws(QueryException::class);

it('forbids majas_retention UPDATE and DELETE of young rows', function (): void {
    $id = appInsertAudit();
    $retention = DbRoles::connect('majas_retention');

    expect(fn () => $retention->update("UPDATE audit_entries SET action = 'x.y' WHERE id = ?", [$id]))->toThrow(QueryException::class)
        ->and(fn () => $retention->delete('DELETE FROM audit_entries WHERE id = ?', [$id]))->toThrow(QueryException::class);
});

it('lets majas_retention prune rows older than 24 months after an anchor, and the chain verifies', function (): void {
    $old1 = appInsertAudit('test.old');
    $old2 = appInsertAudit('test.old');
    $new = appInsertAudit('test.new');

    // Backdate two rows (owner, trigger bypassed) and re-hash them so the chain stays genuinely valid.
    $owner = DbRoles::connect('majas_owner');
    $owner->statement('ALTER TABLE audit_entries DISABLE TRIGGER audit_entries_no_update');
    foreach ([$old1, $old2, $new] as $id) {
        if ($id !== $new) {
            $owner->update("UPDATE audit_entries SET created_at = now() - interval '25 months' WHERE id = ?", [$id]);
        }
    }
    $prev = AuditChainVerifier::GENESIS_HEX;
    foreach ([$old1, $old2, $new] as $id) {
        $owner->update("UPDATE audit_entries e SET prev_hash = decode(?, 'hex'), hash = sha256(decode(?, 'hex') || audit_entry_canonical(e)) WHERE id = ?", [$prev, $prev, $id]);
        $prev = (string) $owner->selectOne("SELECT encode(hash, 'hex') AS h FROM audit_entries WHERE id = ?", [$id])->h;
    }
    $owner->update("UPDATE audit_chain_heads SET last_id = ?, last_hash = decode(?, 'hex') WHERE chain_key = ?", [$new, $prev, TEST_CHAIN]);
    $owner->statement('ALTER TABLE audit_entries ENABLE TRIGGER audit_entries_no_update');

    $retention = DbRoles::connect('majas_retention');

    // Without an anchor the guard refuses even old rows.
    expect(fn () => $retention->delete('DELETE FROM audit_entries WHERE id = ?', [$old1]))->toThrow(QueryException::class);

    $retention->transaction(function () use ($retention, $old2): void {
        $retention->insert('INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) SELECT chain_key, id, hash FROM audit_entries WHERE id = ?', [$old2]);
        $retention->delete('DELETE FROM audit_entries WHERE chain_key = ? AND id <= ?', [TEST_CHAIN, $old2]);
    });

    expect($retention->table('audit_entries')->where('chain_key', TEST_CHAIN)->count())->toBe(1);

    // Anchors: retention may insert, never update or delete.
    expect(fn () => $retention->update('UPDATE audit_chain_anchors SET pruned_through_id = 1 WHERE chain_key = ?', [TEST_CHAIN]))->toThrow(QueryException::class)
        ->and(fn () => $retention->delete('DELETE FROM audit_chain_anchors WHERE chain_key = ?', [TEST_CHAIN]))->toThrow(QueryException::class);

    $result = app(AuditChainVerifier::class)->verify(TEST_CHAIN, 'probe_majas_owner');
    expect($result->valid)->toBeTrue()->and($result->checked)->toBe(1);
});
