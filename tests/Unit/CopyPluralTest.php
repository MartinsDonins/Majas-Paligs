<?php

declare(strict_types=1);

use App\Support\Copy;

/*
 * Review fix (native-speaker copy, 0.0.14): 0 and 1 are separate sentences, everything else uses the locale plural
 * rule — no more "сообщили квартир: :count" or "21 dzīvokļi".
 */
it('uses the Latvian plural forms (21 dzīvoklis, 5 dzīvokļi) and the special 0 / 1 sentences', function (): void {
    app()->setLocale('lv');

    expect(Copy::count('heating.report.thanks_count', 0))->toBe('Ziņojums saņemts.')
        ->and(Copy::count('heating.report.thanks_count', 1))->toStartWith('Jūs esat pirmais')
        ->and(Copy::count('heating.report.thanks_count', 21))->toBe('Jau ziņojis 21 dzīvoklis Jūsu stāvadā.')
        ->and(Copy::count('heating.report.thanks_count', 5))->toBe('Jau ziņojuši 5 dzīvokļi Jūsu stāvadā.')
        ->and(Copy::count('outage.reports', 11))->toBe('11 ziņojumi');
});

it('uses the Russian plural forms (21 квартира, 3 квартиры, 5 квартир)', function (): void {
    app()->setLocale('ru');

    expect(Copy::count('heating.report.thanks_count', 21))->toBe('Уже сообщила 21 квартира на вашем стояке.')
        ->and(Copy::count('heating.report.thanks_count', 3))->toBe('Уже сообщили 3 квартиры на вашем стояке.')
        ->and(Copy::count('heating.report.thanks_count', 5))->toBe('Уже сообщили 5 квартир на вашем стояке.')
        ->and(Copy::count('outage.reports', 2))->toBe('2 сообщения')
        ->and(Copy::count('outage.reports', 0))->toBe('сообщений пока нет')
        ->and(Copy::count('outage.banner.last', 4))->toBe('последнее отключение 4 дня назад');
});

it('keeps one grammatical form for the Russian radiator states', function (): void {
    app()->setLocale('ru');

    expect([__('heating.state.cold'), __('heating.state.lukewarm'), __('heating.state.warm')])
        ->toBe(['Холодные', 'Еле тёплые', 'Тёплые'])
        ->and(__('nav.heating'))->not->toBe(__('heating.state.warm'));
});
