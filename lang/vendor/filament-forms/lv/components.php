<?php

declare(strict_types=1);

// Filament keys missing in (or corrected over) its own lv/ru pack; Laravel merges this over the vendor file.
// Without them screen readers and tables showed raw keys (fallback_locale was lv, not en — QA 2026-10-20).
return [
    'checkbox_list' => [
        'required_description' => 'Izvēlieties vismaz vienu.',
    ],
    'select' => [
        'required_description' => 'Izvēlieties vienu.',
        'no_options_message' => 'Nav izvēļu.',
        'search_label' => 'Meklēt',
        'error_message' => 'Neizdevās ielādēt izvēles. Mēģiniet vēlreiz.',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Mēnesis',
        ],
        'year_input' => [
            'label' => 'Gads',
        ],
        'hour_input' => [
            'label' => 'Stunda',
        ],
        'minute_input' => [
            'label' => 'Minūte',
        ],
        'second_input' => [
            'label' => 'Sekunde',
        ],
    ],
    'radio' => [
        'boolean' => [
            'true' => 'Jā',
            'false' => 'Nē',
        ],
    ],
    'repeater' => [
        'columns' => [
            'actions' => [
                'label' => 'Darbības',
            ],
        ],
    ],
];
