<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Verifies one audit hash chain (SEC-003). Recomputes every hash in SQL with the same canonical function the
 * insert trigger uses, checks the prev_hash linkage in id order, starts from the newest anchor below the first
 * remaining row, rejects anchors that do not match the row they claim to anchor (forged anchors cannot make the
 * verifier skip history) and compares the tail with audit_chain_heads.
 */
final class AuditChainVerifier
{
    public const GENESIS_HEX = '0000000000000000000000000000000000000000000000000000000000000000';

    public function verify(int $chainKey, ?string $connection = null): AuditVerification
    {
        $db = DB::connection($connection);

        $first = $db->selectOne('SELECT min(id) AS id FROM audit_entries WHERE chain_key = ?', [$chainKey]);
        $firstId = $first?->id !== null ? (int) $first->id : null;

        $anchors = $db->select(
            "SELECT pruned_through_id, encode(anchor_hash, 'hex') AS anchor_hash FROM audit_chain_anchors WHERE chain_key = ? ORDER BY pruned_through_id",
            [$chainKey],
        );

        $expectedPrev = self::GENESIS_HEX;

        foreach ($anchors as $anchor) {
            $through = (int) $anchor->pruned_through_id;

            if ($firstId !== null && $through >= $firstId) {
                // The anchored row still exists → it must match exactly (prune in the same transaction).
                $row = $db->selectOne("SELECT encode(hash, 'hex') AS hash FROM audit_entries WHERE chain_key = ? AND id = ?", [$chainKey, $through]);

                if ($row === null || $row->hash !== $anchor->anchor_hash) {
                    return AuditVerification::fail($through, 'forged_anchor', 0);
                }

                continue;
            }

            $expectedPrev = (string) $anchor->anchor_hash;
        }

        if ($firstId === null) {
            return AuditVerification::ok(0);
        }

        $checked = 0;
        $lastId = null;
        $lastHash = null;

        $cursor = $db->cursor(
            "SELECT e.id, encode(e.prev_hash, 'hex') AS prev_hash, encode(e.hash, 'hex') AS hash,
                    encode(sha256(e.prev_hash || audit_entry_canonical(e)), 'hex') AS recomputed
               FROM audit_entries e
              WHERE e.chain_key = ?
              ORDER BY e.id",
            [$chainKey],
        );

        foreach ($cursor as $row) {
            if ($row->prev_hash !== $expectedPrev) {
                return AuditVerification::fail((int) $row->id, 'broken_link', $checked);
            }

            if ($row->recomputed !== $row->hash) {
                return AuditVerification::fail((int) $row->id, 'hash_mismatch', $checked);
            }

            $expectedPrev = (string) $row->hash;
            $lastId = (int) $row->id;
            $lastHash = (string) $row->hash;
            $checked++;
        }

        $head = $db->selectOne("SELECT last_id, encode(last_hash, 'hex') AS last_hash FROM audit_chain_heads WHERE chain_key = ?", [$chainKey]);

        if ($head === null || (int) $head->last_id !== $lastId || $head->last_hash !== $lastHash) {
            return AuditVerification::fail($lastId, 'head_mismatch', $checked);
        }

        return AuditVerification::ok($checked);
    }
}
