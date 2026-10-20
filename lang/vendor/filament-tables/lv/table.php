<?php

declare(strict_types=1);

// Filament keys missing in (or corrected over) its own lv/ru pack; Laravel merges this over the vendor file.
// Without them screen readers and tables showed raw keys (fallback_locale was lv, not en — QA 2026-10-20).
return [
    'columns' => [
        'actions' => [
            'label' => 'Darbība|Darbības',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Jā',
                'false' => 'Nē',
            ],
        ],
        'text' => [
            'actions' => [
                'collapse_list' => 'Rādīt par :count mazāk',
                'expand_list' => 'Rādīt vēl :count',
            ],
        ],
    ],
    'loading' => 'Ielādē…',
    'result_count' => '{0} Nav rezultātu|{1} :count rezultāts|[2,*] :count rezultāti',
    'default_model_label' => 'ieraksts',
    'empty' => [
        'heading' => 'Vēl nav ierakstu',
        'description' => 'Šeit parādīsies jaunie ieraksti.',
    ],
];
