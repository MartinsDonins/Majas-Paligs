<?php

declare(strict_types=1);

/*
 * LV and RU keys must be identical (CI). Every key used in app/ and resources/views must exist.
 */
function langKeys(string $file): array
{
    return array_keys(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
}

it('has identical LV and RU keys', function (string $dir): void {
    $lv = langKeys(lang_path($dir.'lv.json'));
    $ru = langKeys(lang_path($dir.'ru.json'));

    expect(array_values(array_diff($lv, $ru)))->toBe([], 'missing in RU')
        ->and(array_values(array_diff($ru, $lv)))->toBe([], 'missing in LV');
})->with(['resident' => [''], 'admin' => ['admin/']]);

it('has no empty translations', function (string $file): void {
    foreach (json_decode((string) file_get_contents(lang_path($file)), true) as $key => $value) {
        expect(trim((string) $value))->not->toBe('', $key);
    }
})->with(['lv.json', 'ru.json', 'admin/lv.json', 'admin/ru.json']);

it('translates every static key used in the code', function (): void {
    $keys = [];
    foreach (['app', 'resources/views'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            preg_match_all("/(?:__|trans_choice)\\('([a-z_]+\\.[A-Za-z0-9_.\\-]+)'[,)]/", (string) file_get_contents($file->getPathname()), $m);
            $keys = [...$keys, ...$m[1]];
        }
    }

    $known = array_merge(langKeys(lang_path('lv.json')), langKeys(lang_path('admin/lv.json')));
    $missing = array_values(array_unique(array_filter($keys, fn ($k) => ! in_array($k, $known, true) && ! str_ends_with($k, '.'))));

    expect($missing)->toBe([]);
});

it('avoids the words the copy rules ban in Russian UI', function (): void {
    $ru = mb_strtolower((string) file_get_contents(lang_path('ru.json')));

    foreach (['аккаунт', 'авторизац', 'верификац', 'регистрац', 'уведомлени'] as $word) {
        expect(str_contains($ru, $word))->toBeFalse($word);
    }
});

it('switches locale per cookie and per user', function (): void {
    $this->get('/')->assertSee('Jūsu mājas palīgs')->assertSee('lang="lv"', false);
    $this->withCookie('mp_locale', 'ru')->get('/')->assertSee('Помощник вашего дома')->assertSee('lang="ru"', false);

    $user = makeUser('ru@example.test');
    $this->actingAs($user)->post('/locale/ru')->assertRedirect();
    expect($user->refresh()->locale->value)->toBe('ru');
});
