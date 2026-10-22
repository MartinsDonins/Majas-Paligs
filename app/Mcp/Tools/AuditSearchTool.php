<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpPrivacy;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('audit_search')]
#[Description('Search the append-only audit log: the platform chain (default) or one building\'s chain. Filters: action prefix, actor user id, time range. Returns action, actor id / role, subject type / id, reason code and the data fields that are codes or numbers. Free-text reasons are never returned.')]
#[IsReadOnly]
final class AuditSearchTool extends McpTool
{
    public const MAX = 100;

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug for that building\'s chain; omit for the platform chain.'),
            'action' => $schema->string()->max(80)->description('Action prefix, e.g. "role." or "mcp.call" or "opening.".'),
            'actor_user_id' => $schema->integer()->min(1)->description('Only entries by this user id.'),
            'since' => $schema->string()->description('ISO 8601 date/time (default: 7 days ago).'),
            'until' => $schema->string()->description('ISO 8601 date/time (default: no upper bound).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX)->description('Max entries, newest first (default 50).'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $since = $this->time($request->get('since'), 'since') ?? now()->subDays(7);
        $until = $this->time($request->get('until'), 'until');
        $action = $request->get('action');
        $actorId = $request->get('actor_user_id');
        $limit = $this->intArg($request, 'limit', 50, 1, self::MAX);

        $filter = static function (Builder $q) use ($since, $until, $action, $actorId, $limit): Builder {
            // Full precision: a seconds-only bound would drop rows written in the current second.
            $q->where('created_at', '>=', $since->format('Y-m-d H:i:s.uP'))->orderByDesc('id')->limit($limit);
            if ($until !== null) {
                $q->where('created_at', '<=', $until->format('Y-m-d H:i:s.uP'));
            }
            if (is_string($action) && $action !== '') {
                $q->where('action', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $action).'%');
            }
            if (is_numeric($actorId)) {
                $q->where('actor_user_id', (int) $actorId);
            }

            return $q;
        };

        if ($request->get('building') === null) {
            $entries = $filter(AuditEntry::query()->platformChain())->get();
            $chain = 'platform';
        } else {
            $building = $this->building($request);
            // A building chain is house data: read it inside the audited platform context of that building.
            $entries = $this->inBuilding($building, static fn (Building $b) => $filter(AuditEntry::query()->forBuilding((int) $b->id))->get());
            $chain = $building->slug;
        }

        /** @var list<AuditEntry> $rows */
        $rows = $entries->all();

        return [
            'chain' => $chain,
            'since' => $since->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'entries' => array_map(static fn (AuditEntry $e): array => [
                'id' => (int) $e->id,
                'at' => $e->created_at->toIso8601String(),
                'action' => $e->action,
                'actor_user_id' => $e->actor_user_id,
                'actor_role' => $e->actor_role,
                'subject_type' => $e->subject_type !== null ? class_basename($e->subject_type) : null,
                'subject_id' => $e->subject_id,
                'subject_user_count' => count($e->subject_user_ids),
                'reason_code' => $e->reason_code,
                'has_reason_text' => $e->reason_text !== null,
                'data' => self::safeData($e->data),
            ], $rows),
        ];
    }

    /**
     * Keep only values that are numbers, booleans, null or short codes (and lists of those); anything else is
     * replaced by "[omitted]" — audit `data` never holds plaintext PII by contract, this keeps free text out too.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function safeData(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (McpPrivacy::isForbiddenKey($key)) {
                continue;
            }
            $out[$key] = match (true) {
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                is_string($value) && preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $value) === 1 => $value,
                is_array($value) && $depth < 2 => self::safeData($value, $depth + 1),
                default => '[omitted]',
            };
        }

        return $out;
    }

    /**
     * @throws ValidationException
     */
    private function time(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => "\"{$field}\" must be an ISO 8601 date or date-time."]);
        }
    }
}
