<?php

declare(strict_types=1);

/*
 * Only what differs from Livewire's defaults (the package config is merged under this one).
 */
return [
    'navigate' => [
        // Livewire's progress bar injects a <style> element whose nonce does not match the page after a
        // wire:navigate head swap → CSP style-src violations on every navigation (QA 2026-10-20). The resident app
        // shows its own bar instead (resources/css/app.css `html.is-navigating`, toggled in resources/js/app.js).
        'show_progress_bar' => false,
        'progress_bar_color' => '#0B4F55',
    ],
];
