<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\LandParcel;
use App\Models\Riser;
use App\Models\Room;
use App\Models\Section;
use App\Models\User;
use App\Services\Opening\OpeningChecklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('building_detail')]
#[Description('One building: stairwells (numbers, floors, apartment ranges, layout), structure counts, risers by kind / confidence, gates with the missing conditions, the opening checklist (step statuses + who does each step), team counts and invite-letter counts. Free-text gate inputs (controller, DPA ref, mandate) are reported as set / not set only.')]
#[IsReadOnly]
final class BuildingDetailTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);

        return $this->inBuilding($building, static function (Building $b): array {
            $checklist = OpeningChecklist::for($b);
            $s = $b->settings;

            $sections = Section::query()->orderBy('number')->get()->map(static fn (Section $section): array => [
                'number' => (int) $section->number,
                'floors' => (int) $section->floors,
                'apartments_per_floor' => (int) $section->apartments_per_floor,
                'first_apartment' => (int) $section->first_apartment_no,
                'last_apartment' => (int) $section->first_apartment_no + (int) $section->floors * (int) $section->apartments_per_floor - 1,
                'layout' => $section->layout_code,
            ])->values()->all();

            $risers = Riser::query()->selectRaw('kind, confidence, count(*) AS n')->groupBy('kind', 'confidence')->get()
                ->map(static fn (Riser $r): array => [
                    'kind' => $r->getRawOriginal('kind'),
                    'confidence' => $r->getRawOriginal('confidence'),
                    'count' => (int) $r->getAttribute('n'),
                ])->values()->all();

            $steps = [];
            foreach (OpeningChecklist::STEPS as $step => $who) {
                $steps[] = ['step' => $step, 'who' => $who, 'status' => $checklist->status($step)];
            }

            $roles = BuildingRole::query()->current()->selectRaw('role, count(*) AS n')->groupBy('role')->pluck('n', 'role');

            return [
                'slug' => $b->slug,
                'name' => $b->name,
                'city' => $b->city,
                'status' => $b->status->value,
                'floors' => (int) $b->floors,
                'year_built' => $b->year_built,
                'default_locale' => $b->default_locale,
                'manager_set' => $b->manager_name !== null,
                'manager_emergency_phone_set' => $b->manager_emergency_phone !== null,
                'structure' => [
                    'sections' => $sections,
                    'apartments' => Apartment::query()->count(),
                    'apartments_active' => Apartment::query()->active()->count(),
                    'rooms' => Room::query()->count(),
                    'risers' => $risers,
                    'land_parcels' => LandParcel::query()->count(),
                ],
                'settings' => [
                    'modules' => $s->modules,
                    'adhoc_report_valid_days' => $s->adhocReportValidDays,
                    'confirm_min_apartments' => $s->confirmMinApartments,
                    'parking_enforcement_mode' => $s->parkingEnforcementMode,
                    'parking_rules_adopted_at' => $s->parkingRulesAdoptedAt,
                    'share_with_manager' => ['heating_floors' => $s->shareWithManager['heating_floors'], 'apartment_numbers' => $s->shareWithManager['apartment_numbers']],
                    'pin_paused' => $s->pinPaused(),
                    'controller_set' => $s->controller['name'] !== null && $s->controller['contact'] !== null,
                    'dpa_signed_at' => $s->dpaSignedAt,
                    'dpa_ref_set' => $s->dpaRef !== null,
                    'dpa_scope' => $s->dpaScope,
                    'dpia_completed_at' => $s->dpiaCompletedAt,
                    'mandate_set' => $s->mandateRef !== null,
                    'roster_informed_at' => $s->rosterInformedAt,
                    'opening_completed_at' => $s->openingCompletedAt,
                ],
                'gates' => [
                    'core' => ['open' => $checklist->core->open, 'missing' => $checklist->core->missing],
                    'roster' => ['open' => $checklist->roster->open, 'missing' => $checklist->roster->missing],
                    'parking' => [
                        'level' => $checklist->parking->level,
                        'missing_l1' => $checklist->parking->missingL1,
                        'missing_l2' => $checklist->parking->missingL2,
                        'module_on' => $checklist->parking->moduleOn,
                    ],
                ],
                'opening' => [
                    'steps' => $steps,
                    'progress' => [
                        'core' => $checklist->progress('core'),
                        'roster' => $checklist->progress('roster'),
                        'parking' => $checklist->progress('parking'),
                    ],
                ],
                'team' => [
                    'building_admins' => (int) ($roles[BuildingRoleType::BuildingAdmin->value] ?? 0),
                    'section_elders' => (int) ($roles[BuildingRoleType::SectionElder->value] ?? 0),
                    'parking_officers' => (int) ($roles[BuildingRoleType::ParkingOfficer->value] ?? 0),
                    'volunteers' => (int) ($roles[BuildingRoleType::Volunteer->value] ?? 0),
                    'elders_per_section' => $checklist->eldersPerSection(),
                ],
                'memberships' => [
                    'approved' => ApartmentMembership::query()->where('status', MembershipStatus::Approved->value)->count(),
                    'pending' => ApartmentMembership::query()->where('status', MembershipStatus::Pending->value)->count(),
                    'apartments_with_member' => ApartmentMembership::query()->where('status', MembershipStatus::Approved->value)->distinct()->count('apartment_id'),
                ],
                'invite_letters' => [
                    'total' => ApartmentInvite::query()->count(),
                    'usable' => ApartmentInvite::query()->whereNull('revoked_at')->where('expires_at', '>', now())->whereColumn('uses', '<', 'max_uses')->count(),
                    'redeemed' => ApartmentInvite::query()->where('uses', '>', 0)->count(),
                    'revoked' => ApartmentInvite::query()->whereNotNull('revoked_at')->count(),
                ],
            ];
        });
    }
}
