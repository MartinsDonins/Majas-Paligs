<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Livewire\Elder\ApprovalQueue;
use App\Livewire\Elder\PinReset;
use App\Livewire\Onboarding\ChooseRole;
use App\Livewire\Onboarding\RedeemInvite;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

/*
 * No Livewire component exposes building/apartment/membership ids or HMACs as public properties; ids passed to
 * actions are re-authorised (tampering → 403 / 404).
 */
it('exposes no id, apartment or hmac public properties on resident components', function (): void {
    $dir = app_path('Livewire');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($dir) + 1));
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $name = strtolower($prop->getName());
            $locked = $prop->getAttributes(Locked::class) !== [];
            expect($locked || ! preg_match('/(^|_)(id|apartment|building|membership|hmac|plate)(_|$)|(building|apartment|membership)id$/', $name))
                ->toBeTrue("{$class}::\${$prop->getName()} must be #[Locked] or not public");
        }
    }
});

it('returns 403 when an elder tampers the membership id to another stairwell', function (): void {
    $b = makeBuilding('lock-a', 2, 1, open: true);
    $elder1 = makeUser('elder1@example.test');
    grantRole($elder1, $b, BuildingRoleType::SectionElder, 1);
    $foreign = member(makeUser(), $b, 6, MembershipStatus::Pending); // stairwell 2

    inBuilding($b, fn () => Livewire::actingAs($elder1)->test(ApprovalQueue::class)->call('approve', $foreign->id)->assertForbidden());
    expect($foreign->refresh()->status)->toBe(MembershipStatus::Pending);
});

it('cannot reach another building’s membership by id (scope → 404)', function (): void {
    $a = makeBuilding('lock-b', 1, 1, open: true);
    $b = makeBuilding('lock-c', 1, 1, open: true);
    $admin = makeUser('admin-lock@example.test');
    grantRole($admin, $a, BuildingRoleType::BuildingAdmin);
    $foreign = member(makeUser(), $b, 1, MembershipStatus::Pending);

    inBuilding($a, fn () => Livewire::actingAs($admin)->test(PinReset::class)->call('issue', $foreign->id)->assertNotFound());
});

it('never keeps the typed invite code in the component state after submit', function (): void {
    $b = makeBuilding('lock-d', 1, 1, open: true);
    $code = makeInvite($b, 1);

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')->assertSet('code', '');
    Livewire::test(ChooseRole::class)->assertDontSee($code);
});
