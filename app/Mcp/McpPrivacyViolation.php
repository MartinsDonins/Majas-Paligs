<?php

declare(strict_types=1);

namespace App\Mcp;

use RuntimeException;

/**
 * A tool result would have carried personal data (McpPrivacy::assertClean). The message names only the key or the
 * kind of match — never the value.
 */
final class McpPrivacyViolation extends RuntimeException {}
