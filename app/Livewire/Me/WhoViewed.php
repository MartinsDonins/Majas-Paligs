<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Models\User;
use App\Tenancy\UserScopedQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Kas skatīja manus datus" — audit entries where I am a data subject, across buildings, showing the actor's
 * ROLE only (never a name); deleted actors render as "Dzēsts lietotājs".
 */
final class WhoViewed extends Component
{
    public ?int $before = null;

    public function more(int $beforeId): void
    {
        $this->before = $beforeId > 0 ? $beforeId : null;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $entries = UserScopedQuery::for($user)->auditAboutMe($this->before, 30);

        return view('livewire.me.who-viewed', ['entries' => $entries]);
    }
}
