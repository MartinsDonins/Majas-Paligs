<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('keeps one hash chain per building plus the platform chain', function (): void {
    $a = makeBuilding('chain-a', 1, 1);
    $b = makeBuilding('chain-b', 1, 1);

    AuditLogger::record('test.platform', platform: true);
    inBuilding($a, fn () => AuditLogger::record('test.a_one'));
    inBuilding($b, fn () => AuditLogger::record('test.b_one'));
    inBuilding($a, fn () => AuditLogger::record('test.a_two'));

    $verifier = app(AuditChainVerifier::class);

    expect(AuditEntry::query()->forBuilding($a->id)->count())->toBe(2)
        ->and(AuditEntry::query()->forBuilding($b->id)->count())->toBe(1)
        ->and($verifier->verify($a->id)->valid)->toBeTrue()
        ->and($verifier->verify($b->id)->valid)->toBeTrue()
        ->and($verifier->verify(0)->valid)->toBeTrue();

    $rows = DB::select("SELECT id, encode(prev_hash,'hex') p, encode(hash,'hex') h FROM audit_entries WHERE chain_key = ? ORDER BY id", [$a->id]);
    expect($rows[1]->p)->toBe($rows[0]->h)
        ->and($rows[0]->p)->toBe(AuditChainVerifier::GENESIS_HEX);
});

it('refuses plaintext PII keys in audit data', function (): void {
    AuditLogger::record('test.pii', data: ['email' => 'x@example.test'], platform: true);
})->throws(InvalidArgumentException::class);

it('detects tampering (owner bypassing the update trigger)', function (): void {
    AuditLogger::record('test.one', platform: true);
    $id = AuditLogger::record('test.two', platform: true);
    AuditLogger::record('test.three', platform: true);

    // Only the schema owner can disable the trigger — simulating a compromised owner.
    DB::statement('ALTER TABLE audit_entries DISABLE TRIGGER audit_entries_no_update');
    DB::update("UPDATE audit_entries SET data = '{\"forged\":true}'::jsonb WHERE id = ?", [$id]);
    DB::statement('ALTER TABLE audit_entries ENABLE TRIGGER audit_entries_no_update');

    $result = app(AuditChainVerifier::class)->verify(0);

    expect($result->valid)->toBeFalse()
        ->and($result->failedAtId)->toBe($id)
        ->and($result->reason)->toBe('hash_mismatch');
});

it('detects a deleted row in the middle of a chain', function (): void {
    AuditLogger::record('test.one', platform: true);
    $id = AuditLogger::record('test.two', platform: true);
    AuditLogger::record('test.three', platform: true);

    DB::statement('ALTER TABLE audit_entries DISABLE TRIGGER audit_entries_delete');
    DB::delete('DELETE FROM audit_entries WHERE id = ?', [$id]);
    DB::statement('ALTER TABLE audit_entries ENABLE TRIGGER audit_entries_delete');

    expect(app(AuditChainVerifier::class)->verify(0)->reason)->toBe('broken_link');
});

it('verifies from an anchor after pruning and rejects a forged anchor', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = AuditLogger::record('test.row', platform: true);
    }

    // Simulate a prune of the first three rows: anchor (trigger-checked) then delete.
    DB::insert('INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) SELECT chain_key, id, hash FROM audit_entries WHERE id = ?', [$ids[2]]);
    DB::statement('ALTER TABLE audit_entries DISABLE TRIGGER audit_entries_delete');
    DB::delete('DELETE FROM audit_entries WHERE id <= ?', [$ids[2]]);
    DB::statement('ALTER TABLE audit_entries ENABLE TRIGGER audit_entries_delete');

    $ok = app(AuditChainVerifier::class)->verify(0);
    expect($ok->valid)->toBeTrue()->and($ok->checked)->toBe(2);

    // A forged anchor that claims history is pruned although rows still exist is rejected.
    DB::statement('ALTER TABLE audit_chain_anchors DISABLE TRIGGER audit_chain_anchors_insert');
    DB::insert("INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) VALUES (0, ?, decode(repeat('ab', 32), 'hex'))", [$ids[4]]);
    DB::statement('ALTER TABLE audit_chain_anchors ENABLE TRIGGER audit_chain_anchors_insert');

    $forged = app(AuditChainVerifier::class)->verify(0);
    expect($forged->valid)->toBeFalse()->and($forged->reason)->toBe('forged_anchor');
});

it('refuses an anchor that does not match the anchored row (insert trigger)', function (): void {
    $id = AuditLogger::record('test.row', platform: true);

    DB::insert("INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) VALUES (0, ?, decode(repeat('cd', 32), 'hex'))", [$id]);
})->throws(QueryException::class);
