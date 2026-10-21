<?php

declare(strict_types=1);

// Filament keys missing in (or corrected over) its own lv/ru pack; Laravel merges this over the vendor file.
// Without them screen readers and tables showed raw keys (fallback_locale was lv, not en — QA 2026-10-20).
return [
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
        ],
    ],
    'loading' => 'Загрузка…',
    'result_count' => '{0} Нет результатов|{1} :count результат|[2,4] :count результата|[5,*] :count результатов',
    'empty' => [
        'heading' => 'Записей пока нет',
        'description' => 'Здесь появятся новые записи.',
    ],
];
