<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use InvalidArgumentException;

/**
 * The generator input does not match the building's stored structure (BuildingGenerator::conflicts()). The message
 * is translated and meant for the platform admin.
 */
final class GeneratorConflict extends InvalidArgumentException {}
