<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PrivacyNoticeKind;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\PrivacyNoticeVersion;
use App\Models\Section;
use App\Models\User;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Invites\InviteCodeService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL DEMO ONLY — synthetic accounts (@example.test) for clicking through the app. Refuses to run outside
 * APP_ENV=local. Opens gate L-core with a FICTIONAL controller/DPA and prints a few invite codes.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder runs only with APP_ENV=local.');
        }

        $this->call([Series602Seeder::class, PrivacyNoticeSeeder::class, PucesIela51Seeder::class]);

        PrivacyNoticeVersion::query()->whereNull('published_at')->update(['published_at' => now()]);
        $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->firstOrFail();

        $building = Building::query()->where('slug', PucesIela51Seeder::SLUG)->firstOrFail();
        $building->forceFill([
            'settings' => $building->settings->with([
                'controller' => ['name' => 'DEMO biedrība (fiktīva)', 'contact' => 'demo-dati@example.test', 'basis_ref' => 'DEMO'],
                'dpa_signed_at' => now()->toDateString(),
                'dpa_ref' => 'DEMO-DPA-1',
                'dpa_scope' => ['core', 'heating', 'outages'],
                'modules' => ['heating' => true, 'outages' => true],
            ]),
        ])->save();

        $admin = $this->user('demo-admin@example.test');
        $elder = $this->user('demo-elder5@example.test');
        $resident = $this->user('demo-resident@example.test');

        $codes = [];

        PlatformBuildingContext::run($building, PlatformPurpose::Seed, function () use ($template, $admin, $elder, $resident, &$codes): void {
            if (BuildingPrivacyNotice::current() === null) {
                BuildingPrivacyNotice::query()->create([
                    'template_version_id' => $template->id,
                    'controller_snapshot' => ['name' => 'DEMO biedrība (fiktīva)', 'contact' => 'demo-dati@example.test'],
                    'covers_modules' => ['core', 'heating', 'outages'],
                    'published_at' => now(),
                ]);
            }

            $s5 = Section::query()->where('number', 5)->firstOrFail();
            foreach ([[$admin, BuildingRoleType::BuildingAdmin, null], [$elder, BuildingRoleType::SectionElder, $s5->id]] as [$u, $role, $section]) {
                BuildingRole::query()->firstOrCreate(
                    ['user_id' => $u->id, 'role' => $role->value, 'section_id' => $section],
                    ['granted_at' => now(), 'expires_at' => now()->addYear(), 'mandate_ref' => 'DEMO'],
                );
            }

            foreach ([[$elder, 157, MembershipStatus::Approved], [$resident, 161, MembershipStatus::Pending]] as [$u, $number, $status]) {
                $apartment = Apartment::query()->where('number', $number)->firstOrFail();
                ApartmentMembership::query()->firstOrCreate(
                    ['apartment_id' => $apartment->id, 'user_id' => $u->id],
                    ['role' => MembershipRole::Owner, 'status' => $status, 'approved_at' => $status === MembershipStatus::Approved ? now() : null],
                );
            }

            $service = app(InviteCodeService::class);
            foreach ([145, 149, 153] as $number) {
                $apartment = Apartment::query()->where('number', $number)->firstOrFail();
                $code = $service->generate();
                ApartmentInvite::query()->create([
                    'apartment_id' => $apartment->id,
                    'code_hmac' => $service->hmac($code),
                    'expires_at' => now()->addDays(60),
                    'batch_ref' => 'demo',
                ]);
                $codes[$number] = $service->format($code);
            }
        });

        $this->command?->info('Demo accounts (log in with the e-mail; mails go to Mailpit): demo-admin@, demo-elder5@, demo-resident@example.test');
        foreach ($codes as $number => $code) {
            $this->command?->line("Invite code Dz. {$number}: {$code}");
        }
        $this->command?->line('Platform admin: php artisan majas:platform-admin you@example.test');
    }

    private function user(string $email): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['locale' => 'lv']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
