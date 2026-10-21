<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Carbon\CarbonImmutable;

/**
 * DPIA-lite checklist (ROUND2-SPEC §3.5.3): eight structured items stored in building_documents.answers as
 * {answer, note ≤ 500} (+ `accepted` on the residual-risk item). Items 1–5 are pre-filled from the controls the
 * product already has; the admin reviews them, types the sign-off name and ticks the residual-risk acceptance.
 * Completion requires every item answered and the acceptance ticked.
 */
final class DpiaLite
{
    public const NOTE_MAX = 500;

    /**
     * item key => allowed answers ('date' = Y-m-d, 'text' = free text ≤ 200).
     *
     * @var array<string, list<string>|string>
     */
    public const ITEMS = [
        'description' => ['confirmed', 'changed'],
        'necessity' => ['yes', 'partly', 'no'],
        'wp248' => ['none', 'some'],
        'risks' => ['assessed'],
        'measures' => ['in_place', 'partly'],
        'residual' => ['low', 'medium', 'high'],
        'review' => 'date',
        'signoff' => 'text',
    ];

    /**
     * Pre-filled answers (notes in the document's languages come from lang `dpia.prefill.<item>`).
     *
     * @return array<string, array{answer: ?string, note: string, accepted?: bool}>
     */
    public static function defaults(string $locale = 'lv'): array
    {
        $note = static fn (string $item): string => (string) __('dpia.prefill.'.$item, locale: $locale);

        return [
            'description' => ['answer' => 'confirmed', 'note' => $note('description')],
            'necessity' => ['answer' => 'yes', 'note' => $note('necessity')],
            'wp248' => ['answer' => 'none', 'note' => $note('wp248')],
            'risks' => ['answer' => 'assessed', 'note' => $note('risks')],
            'measures' => ['answer' => 'in_place', 'note' => $note('measures')],
            'residual' => ['answer' => 'low', 'note' => $note('residual'), 'accepted' => false],
            'review' => ['answer' => CarbonImmutable::now('Europe/Riga')->addMonths(12)->toDateString(), 'note' => ''],
            'signoff' => ['answer' => null, 'note' => ''],
        ];
    }

    /**
     * Keep only known items / answers; clip notes. Unknown answers become null (unanswered).
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, array{answer: ?string, note: string, accepted?: bool}>
     */
    public static function normalise(array $answers): array
    {
        $out = [];

        foreach (self::ITEMS as $item => $allowed) {
            $row = (array) ($answers[$item] ?? []);
            $answer = isset($row['answer']) ? trim((string) $row['answer']) : null;

            $answer = match (true) {
                $answer === null || $answer === '' => null,
                $allowed === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer) === 1 ? $answer : null,
                $allowed === 'text' => mb_substr($answer, 0, 200),
                default => in_array($answer, $allowed, true) ? $answer : null,
            };

            $out[$item] = ['answer' => $answer, 'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, self::NOTE_MAX)];
            if ($item === 'residual') {
                $out[$item]['accepted'] = (bool) ($row['accepted'] ?? false);
            }
        }

        return $out;
    }

    /**
     * Unanswered item keys (+ "residual_accepted" when the acceptance is not ticked).
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<string>
     */
    public static function missing(?array $answers): array
    {
        $answers = self::normalise($answers ?? []);
        $missing = array_keys(array_filter($answers, static fn (array $a): bool => $a['answer'] === null));

        if (! ($answers['residual']['accepted'] ?? false)) {
            $missing[] = 'residual_accepted';
        }

        return $missing;
    }
}
