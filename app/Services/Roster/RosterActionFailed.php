<?php

declare(strict_types=1);

namespace App\Services\Roster;

use RuntimeException;

/**
 * A refused roster action with a reason key (`roster.error.<key>`): empty, name_too_long, phone_invalid,
 * phones_too_many, email_invalid, comment_too_long, cap, export_cap, step_up, gate_closed. Never carries a value.
 */
final class RosterActionFailed extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('roster: '.$key);
    }

    public function label(): string
    {
        return __('roster.error.'.$this->key);
    }
}
