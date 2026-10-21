<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Models\DocumentTemplate;
use App\Services\Documents\TemplateMarkup;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Document masters (ROUND2-SPEC §3.3, GOV-011) from database/templates/{kind}.{locale}.md — tracked files with
 * synthetic placeholders only. Version 1 of every kind × locale is seeded PUBLISHED, so a house can start the opening
 * wizard at once; every card says "Paraugs, nevis juridiska konsultācija" (MANUAL-TODO: legal + native RU review).
 * Idempotent: an existing kind × version × locale is never touched (published versions are immutable — a change is
 * a new version in the platform DocumentTemplateResource).
 */
final class DocumentTemplateSeeder extends Seeder
{
    public const VERSION = 1;

    public function run(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            foreach (Locale::cases() as $locale) {
                $path = database_path("templates/{$kind->value}.{$locale->value}.md");
                if (! is_file($path)) {
                    throw new RuntimeException("Missing document template {$path}.");
                }

                $exists = DocumentTemplate::query()->where('kind', $kind->value)->where('version', self::VERSION)
                    ->where('locale', $locale->value)->exists();
                if ($exists) {
                    continue;
                }

                DocumentTemplate::query()->create(self::attributes($kind, $locale, (string) file_get_contents($path)) + [
                    'version' => self::VERSION,
                    'published_at' => now(),
                ]);
            }
        }
    }

    /**
     * Title, required markers, placeholders and covered modules derived from a master body.
     *
     * @return array{kind: DocumentKind, locale: Locale, title: string, body: string, required_markers: list<string>, placeholders: list<string>, covers_modules: list<string>}
     */
    public static function attributes(DocumentKind $kind, Locale $locale, string $body): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $first = strtok($body, "\n");
        $title = trim(ltrim((string) $first, '# '));

        $required = [];
        foreach (TemplateMarkup::parse($body) as $segment) {
            if ($segment['type'] === 'req') {
                $required[] = $segment['key'];
            }
        }

        return [
            'kind' => $kind,
            'locale' => $locale,
            'title' => $title,
            'body' => $body,
            'required_markers' => $required,
            'placeholders' => TemplateMarkup::placeholders($body),
            'covers_modules' => $kind === DocumentKind::PrivacyNotice ? ['core', 'heating', 'outages', 'parking', 'roster'] : [],
        ];
    }
}
