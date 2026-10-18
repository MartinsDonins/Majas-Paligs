<?php

declare(strict_types=1);

namespace App\Services\Parking;

use RuntimeException;

/**
 * A ciphertext could not be opened (wrong row / table / building / key version, tampered or unknown key).
 */
final class PlateVaultException extends RuntimeException {}
