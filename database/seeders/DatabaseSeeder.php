<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Structure-only seed (zero personal data): series catalogue, privacy notice drafts, the
 * parking legal banner, the house document masters (DPA, notice, DPIA-lite …) and the pilot building.
 * DemoSeeder (synthetic users, local only) is NOT called here: `php artisan db:seed --class=DemoSeeder`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            Series602Seeder::class,
            PrivacyNoticeSeeder::class,
            LegalNoticeSeeder::class,
            DocumentTemplateSeeder::class,
            PucesIela51Seeder::class,
        ]);
    }
}
