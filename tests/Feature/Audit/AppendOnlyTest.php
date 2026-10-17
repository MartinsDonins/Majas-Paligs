<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('refuses UPDATE in the database (trigger) even for the schema owner', function (): void {
    $id = AuditLogger::record('test.append', platform: true);

    DB::update("UPDATE audit_entries SET action = 'x.y' WHERE id = ?", [$id]);
})->throws(QueryException::class);

it('refuses DELETE for anyone but majas_retention (INVOKER guard)', function (): void {
    $id = AuditLogger::record('test.append', platform: true);

    DB::delete('DELETE FROM audit_entries WHERE id = ?', [$id]);
})->throws(QueryException::class);

it('refuses model-level updates and deletes', function (): void {
    $id = AuditLogger::record('test.append', platform: true);
    $entry = AuditEntry::query()->findOrFail($id);

    expect(fn () => $entry->forceFill(['action' => 'x.y'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('assigns id, timestamp and hashes in the trigger (client values ignored)', function (): void {
    DB::table('audit_entries')->insert([
        'chain_key' => 0, 'action' => 'test.forged', 'data' => '{}',
        'created_at' => '2000-01-01 00:00:00', 'hash' => DB::raw("decode('00', 'hex')"),
    ]);

    $row = DB::selectOne("SELECT created_at, octet_length(hash) AS len FROM audit_entries WHERE action = 'test.forged'");

    expect(str_starts_with((string) $row->created_at, '2000'))->toBeFalse()->and((int) $row->len)->toBe(32);
});
