<?php

declare(strict_types=1);

namespace App\Enums;

enum BuildingRoleType: string
{
    use HasLabel;

    case BuildingAdmin = 'building_admin';
    case SectionElder = 'section_elder';
    case ParkingOfficer = 'parking_officer';
    case Volunteer = 'volunteer';
    case ManagerViewer = 'manager_viewer';

    protected static function labelPrefix(): string
    {
        return 'building_role';
    }
}
