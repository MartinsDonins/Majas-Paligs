<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Landing / step 1: language first (two big buttons), "Lielais teksts" toggle reachable before login,
 * then "I have a code from my mailbox" or "Log in".
 */
#[Layout('layouts::guest')]
final class LanguagePick extends Component
{
    public bool $largeText = false;

    public function mount(): void
    {
        $this->largeText = request()->cookie('mp_text_size') === 'large'
            || auth()->user()?->text_size->value === 'large';
    }

    public function toggleLargeText(): void
    {
        $this->largeText = ! $this->largeText;
        $value = $this->largeText ? 'large' : 'normal';

        Cookie::queue(cookie('mp_text_size', $value, 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));

        $user = auth()->user();
        if ($user !== null) {
            $user->forceFill(['text_size' => $value])->save();
        }

        $this->redirect(route('landing'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.onboarding.language-pick');
    }
}
