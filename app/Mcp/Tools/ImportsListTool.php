<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Building;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('imports_list')]
#[Description('CSV import runs of one building (resident roster, permits): id, kind, status, row count, dry-run summary (row statuses, actions, validation codes with counts), committed counts and the error code. Never a cell value or a file name.')]
#[IsReadOnly]
final class ImportsListTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
            'kind' => $schema->string()->enum(['roster', 'permits'])->description('Only this import kind.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('How many runs (default 20).'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);
        $kind = $request->get('kind');
        $limit = $this->intArg($request, 'limit', 20, 1, 50);

        return $this->inBuilding($building, static function (Building $b) use ($kind, $limit): array {
            $query = ImportRun::query()->latest('created_at')->limit($limit);
            if (is_string($kind) && in_array($kind, ['roster', 'permits'], true)) {
                $query->where('kind', $kind);
            }

            $runs = $query->get()->map(static fn (ImportRun $r): array => [
                'import_id' => $r->id,
                'kind' => $r->kind,
                'status' => $r->status,
                'rows' => (int) $r->row_count,
                'summary' => [
                    'dry_run_at' => is_string($r->summary['dry_run_at'] ?? null) ? $r->summary['dry_run_at'] : null,
                    'status' => self::counts($r->summary['status'] ?? []),
                    'actions' => self::counts($r->summary['actions'] ?? []),
                    'validation' => self::counts($r->summary['codes'] ?? []),
                ],
                'committed' => self::counts($r->committed_counts ?? []),
                'error' => $r->error_code,
                'created_by_user_id' => $r->created_by,
                'created_at' => $r->created_at->toIso8601String(),
                'expires_at' => $r->expires_at->toIso8601String(),
            ])->values()->all();

            return ['building' => $b->slug, 'imports' => $runs];
        });
    }

    /**
     * Only `code => integer` pairs survive (summaries are counts by design; this keeps it that way).
     *
     * @return array<string, int>
     */
    private static function counts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $n) {
            if (is_string($key) && preg_match('/^[a-z0-9_.:-]{1,64}$/', $key) === 1 && is_numeric($n)) {
                $out[$key] = (int) $n;
            }
        }

        return $out;
    }
}
