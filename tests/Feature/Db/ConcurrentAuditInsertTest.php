<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use Tests\Support\DbRoles;

/*
 * Two processes insert into the same chain in parallel as majas_app; the per-chain advisory lock in the
 * BEFORE INSERT trigger must keep the chain linear (no fork), MVP-SPEC A.12.
 */
const CONCURRENT_CHAIN = 990003;

afterEach(fn () => DbRoles::wipeChain(CONCURRENT_CHAIN));

function pgDsn(): array
{
    $c = config('database.connections.pgsql');

    return ["pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", 'majas_app', (string) env('DB_APP_TEST_PASSWORD', 'app-dev-only')];
}

it('never forks a chain under concurrent inserts', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl not available');
    }

    [$dsn, $user, $pass] = pgDsn();
    $perProcess = 40;
    $pids = [];

    for ($p = 0; $p < 2; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $pdo = new PDO($dsn, $user, $pass);
            $stmt = $pdo->prepare('INSERT INTO audit_entries (chain_key, building_id, action) VALUES (?, ?, ?)');
            for ($i = 0; $i < $perProcess; $i++) {
                $pdo->beginTransaction();
                $stmt->execute([CONCURRENT_CHAIN, CONCURRENT_CHAIN, 'test.concurrent']);
                usleep(random_int(0, 2000));
                $pdo->commit();
            }
            $pdo = null;
            posix_kill(getmypid(), SIGKILL); // skip PHPUnit shutdown handlers in the child
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $owner = DbRoles::connect('majas_owner');
    expect($owner->table('audit_entries')->where('chain_key', CONCURRENT_CHAIN)->count())->toBe(2 * $perProcess);

    $result = app(AuditChainVerifier::class)->verify(CONCURRENT_CHAIN, 'probe_majas_owner');
    expect($result->valid)->toBeTrue()->and($result->checked)->toBe(2 * $perProcess);

    $distinctPrev = $owner->selectOne('SELECT count(DISTINCT prev_hash) AS c FROM audit_entries WHERE chain_key = ?', [CONCURRENT_CHAIN]);
    expect((int) $distinctPrev->c)->toBe(2 * $perProcess);
});
