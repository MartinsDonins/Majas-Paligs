<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Documents\TemplateLinter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('templates_status')]
#[Description('Document masters (DPA, DPA annex, privacy notice, DPIA-lite, parking decision) per kind and language: latest published version, unpublished drafts, LV/RU parity. With "building": that house\'s documents per kind (status, version, master version, whether a newer master exists, linter error codes of the current draft).')]
#[IsReadOnly]
final class TemplatesStatusTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Optional building slug: also report that house\'s documents.'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $masters = [];
        foreach (DocumentKind::cases() as $kind) {
            $row = ['kind' => $kind->value];
            foreach ([Locale::Lv, Locale::Ru] as $locale) {
                $latest = DocumentTemplate::latest($kind, $locale);
                $row[$locale->value] = [
                    'published_version' => $latest?->version,
                    'published_at' => $latest?->published_at?->toIso8601String(),
                    'drafts' => DocumentTemplate::query()->where('kind', $kind->value)->where('locale', $locale->value)->whereNull('published_at')->count(),
                ];
            }
            $row['lv_ru_parity'] = $row['lv']['published_version'] === $row['ru']['published_version'];
            $masters[] = $row;
        }

        $result = ['masters' => $masters];

        if ($request->get('building') !== null) {
            $building = $this->building($request);
            $result['building'] = $this->inBuilding($building, static function (Building $b): array {
                $linter = app(TemplateLinter::class);
                $docs = [];
                foreach (DocumentKind::cases() as $kind) {
                    $doc = BuildingDocument::latestOf($kind);
                    if ($doc === null) {
                        $docs[] = ['kind' => $kind->value, 'status' => 'missing'];

                        continue;
                    }

                    $newest = DocumentTemplate::latest($kind, Locale::Lv);
                    $docs[] = [
                        'kind' => $kind->value,
                        'document_id' => $doc->id,
                        'status' => $doc->status->value,
                        'version' => (int) $doc->version,
                        'master_version' => (int) $doc->template_version,
                        'newer_master' => $newest !== null && (int) $newest->version > (int) $doc->template_version,
                        'finalized_at' => $doc->finalized_at?->toIso8601String(),
                        'sent_at' => $doc->sent_at?->toIso8601String(),
                        'recorded_at' => $doc->recorded_at?->toIso8601String(),
                        'lint' => $doc->isDraft()
                            ? array_values(array_unique(array_map(static fn (array $e): string => $e['locale'].':'.$e['block'].':'.$e['code'], $linter->check($doc))))
                            : [],
                    ];
                }

                return ['slug' => $b->slug, 'documents' => $docs];
            });
        }

        return $result;
    }
}
