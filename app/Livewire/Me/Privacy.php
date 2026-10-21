<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Enums\ExportStatus;
use App\Gdpr\AccountEraser;
use App\Gdpr\GdprExporter;
use App\Models\DataExport;
use App\Models\User;
use App\Tenancy\UserScopedQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Privātums" (/me/privacy): export (ZIP across all buildings, signed 10-min link), account deletion
 * (2 steps, tombstone) and "Kas skatīja manus datus". No CurrentBuilding: everything goes through
 * UserScopedQuery (allow-listed caller).
 */
#[Layout('layouts::app')]
final class Privacy extends Component
{
    public bool $confirmDelete = false;

    public string $deleteWord = '';

    public function requestExport(): void
    {
        $user = $this->user();
        $key = 'gdpr-export:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            session()->flash('status', 'export_limit');

            return;
        }
        RateLimiter::hit($key, 86400);

        $export = DataExport::query()->create(['user_id' => $user->id, 'status' => ExportStatus::Queued]);
        AuditLogger::record('gdpr.export_requested', $export, [(int) $user->id], platform: true, actorRole: 'self');

        GdprExporter::dispatch((string) $export->id);
        session()->flash('status', 'export_queued');
    }

    public function askDelete(): void
    {
        $this->confirmDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmDelete = false;
        $this->deleteWord = '';
    }

    public function delete(AccountEraser $eraser): void
    {
        $expected = mb_strtoupper(__('privacy.delete.word'));

        // DZĒST = DZEST = dzest: many phone keyboards hide Ē (QA 2026-10-20). Same for УДАЛИТЬ.
        $fold = static fn (string $w): string => mb_strtoupper(Str::ascii(trim($w)));
        if ($fold($this->deleteWord) !== $fold($expected)) {
            $this->addError('deleteWord', __('privacy.delete.word_mismatch', ['word' => $expected]));

            return;
        }

        $user = $this->user();
        $eraser->erase($user);

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('landing');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $user = $this->user();
        $scoped = UserScopedQuery::for($user);

        $latest = DataExport::query()->where('user_id', $user->id)->latest('created_at')->first();
        $download = null;
        if ($latest !== null && $latest->status === ExportStatus::Ready && $latest->expires_at?->isFuture()) {
            $download = URL::temporarySignedRoute('me.exports.download', now()->addMinutes((int) config('majas.exports.signed_minutes', 10)), ['export' => $latest->id]);
        }

        return view('livewire.me.privacy', [
            'memberships' => $scoped->memberships(),
            'latest' => $latest,
            'download' => $download,
        ])->title(__('privacy.title'));
    }
}
