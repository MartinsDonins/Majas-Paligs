<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Keys;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * majas:keys — generate the dedicated keys (INVITE_KEY, PLATE_ENC_KEY, PLATE_INDEX_KEY). Prints them (to put into
 * Coolify) or, with --write, fills EMPTY values in a local .env. Never overwrites an existing key (rotation is a
 * documented procedure, docs/SECURITY.md).
 */
#[AsCommand(name: 'majas:keys')]
final class GenerateKeys extends Command
{
    protected $signature = 'majas:keys {--write : Fill empty keys in the local .env}';

    protected $description = 'Generate the dedicated invite / plate keys.';

    public function handle(): int
    {
        $keys = ['INVITE_KEY' => Keys::generate(), 'PLATE_ENC_KEY' => Keys::generate(), 'PLATE_INDEX_KEY' => Keys::generate()];

        if (! $this->option('write')) {
            foreach ($keys as $name => $value) {
                $this->line("{$name}={$value}");
            }

            return self::SUCCESS;
        }

        $path = base_path('.env');
        if (! is_file($path)) {
            $this->error('.env not found.');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);
        foreach ($keys as $name => $value) {
            if (preg_match('/^'.$name.'=\S+/m', $env) === 1) {
                $this->line("{$name} already set — kept.");

                continue;
            }
            $env = preg_match('/^'.$name.'=$/m', $env) === 1
                ? (string) preg_replace('/^'.$name.'=$/m', $name.'='.$value, $env)
                : rtrim($env)."\n{$name}={$value}\n";
            $this->info("{$name} written.");
        }
        file_put_contents($path, $env);

        return self::SUCCESS;
    }
}
