<?php

declare(strict_types=1);

namespace App\Services\Roster;

use RuntimeException;

/**
 * A roster ciphertext that does not open in its context (wrong row / field / building / key). Never carries data.
 */
final class ContactVaultException extends RuntimeException {}
