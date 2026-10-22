<?php

declare(strict_types=1);

namespace App\Mcp;

use RuntimeException;

/**
 * A tool refused the call for a reason the caller can fix (missing scope, confirm, unknown building, dry run first …).
 * `reason` is a short code for the audit row; the message is the actionable English text the MCP client shows.
 */
final class McpRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
