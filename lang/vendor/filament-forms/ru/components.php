<?php

declare(strict_types=1);

// Filament keys missing in (or corrected over) its own lv/ru pack; Laravel merges this over the vendor file.
// Without them screen readers and tables showed raw keys (fallback_locale was lv, not en — QA 2026-10-20).
return [
    'checkbox_list' => [
        'required_description' => 'Выберите хотя бы один вариант.',
    ],
    'select' => [
        'required_description' => 'Выберите вариант.',
        'search_label' => 'Поиск',
        'error_message' => 'Не удалось загрузить варианты. Попробуйте ещё раз.',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Месяц',
        ],
        'year_input' => [
            'label' => 'Год',
        ],
        'hour_input' => [
            'label' => 'Час',
        ],
        'minute_input' => [
            'label' => 'Минута',
        ],
        'second_input' => [
            'label' => 'Секунда',
        ],
    ],
    'repeater' => [
        'columns' => [
            'actions' => [
                'label' => 'Действия',
            ],
        ],
    ],
];
