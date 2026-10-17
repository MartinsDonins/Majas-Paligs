<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\PinAuthService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * After a login with the elder's temporary code: the only reachable page until a new (non-weak) PIN is set.
 */
#[Layout('layouts::guest')]
final class SetNewPin extends Component
{
    public string $pin = '';

    public string $pinConfirm = '';

    public function save(PinAuthService $pins): void
    {
        /** @var User $user */
        $user = auth()->user();

        if ($this->pin !== $this->pinConfirm) {
            $this->pin = $this->pinConfirm = '';
            $this->addError('pinConfirm', __('auth.pin.mismatch'));

            return;
        }

        $apartment = $user->login_handle !== null ? (int) explode('-', $user->login_handle)[0] : null;

        try {
            $pins->setPin($user, $this->pin, $apartment ?: null);
        } catch (ValidationException $e) {
            $this->pin = $this->pinConfirm = '';
            $this->addError('pin', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->pin = $this->pinConfirm = '';
        session()->regenerate();
        $this->redirectRoute('home.redirect');
    }

    public function render(): View
    {
        return view('livewire.auth.set-new-pin')->title(__('auth.pin.new_title'));
    }
}
