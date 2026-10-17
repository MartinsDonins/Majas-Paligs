<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ManagePanelProvider;
use App\Providers\Filament\PlatformPanelProvider;

return [
    AppServiceProvider::class,
    ManagePanelProvider::class,
    PlatformPanelProvider::class,
];
