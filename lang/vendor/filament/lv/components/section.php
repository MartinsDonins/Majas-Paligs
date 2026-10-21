<?php

declare(strict_types=1);

// Filament keys missing in (or corrected over) its own lv/ru pack; Laravel merges this over the vendor file.
// Without them screen readers and tables showed raw keys (fallback_locale was lv, not en — QA 2026-10-20).
return [
    'actions' => [
        'collapse' => [
            'label' => 'Sakļaut sadaļu',
        ],
        'expand' => [
            'label' => 'Izvērst sadaļu',
        ],
    ],
];
