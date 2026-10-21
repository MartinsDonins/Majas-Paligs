<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentTemplate;
use App\Models\User;

/**
 * Document masters (ROUND2-SPEC §3.3, §3.8): platform_admin only. A published version is never edited or deleted
 * (also enforced by a DB trigger) — a change is a new draft version.
 */
final class DocumentTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->platform($user);
    }

    public function view(User $user, DocumentTemplate $template): bool
    {
        return $this->platform($user);
    }

    public function create(User $user): bool
    {
        return $this->platform($user);
    }

    public function update(User $user, DocumentTemplate $template): bool
    {
        return $this->platform($user) && ! $template->isPublished();
    }

    public function publish(User $user, DocumentTemplate $template): bool
    {
        return $this->platform($user) && ! $template->isPublished();
    }

    public function delete(User $user, DocumentTemplate $template): bool
    {
        return $this->platform($user) && ! $template->isPublished();
    }

    private function platform(User $user): bool
    {
        return ! $user->isTombstone() && $user->isPlatformAdmin();
    }
}
