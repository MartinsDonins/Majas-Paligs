<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

/*
 * Arch/VaultCallersTest (ROUND2-SPEC §5.2): roster plaintext is opened only by the sanctioned read paths —
 * RosterViewer, RosterExporter, GdprExporter, ContactRekeyService (and ImportVault for staged files, which uses its
 * own construction). Implemented as a source scan like Arch/TenancyArchTest (comments stripped).
 */
it('opens ContactVault ciphertext only in the allow-listed classes', function (): void {
    $allowed = [
        'app/Services/Roster/ContactVault.php',
        'app/Services/Roster/RosterViewer.php',
        'app/Services/Roster/RosterExporter.php',
        'app/Services/Roster/ContactRekeyService.php',
        'app/Gdpr/GdprExporter.php',
        'app/Services/Import/ImportVault.php',
    ];

    $offenders = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

        if (str_contains($code, 'ContactVault') && preg_match('/->\s*(open|openField)\s*\(/', $code) === 1 && ! in_array($path, $allowed, true)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('never stores roster plaintext columns', function (): void {
    $columns = collect(DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'resident_contacts'"))->pluck('column_name')->all();

    expect(array_intersect($columns, ['name', 'phone', 'phones', 'email', 'comment', 'notes']))->toBe([]);
});
