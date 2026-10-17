<?php

declare(strict_types=1);

namespace Tests;

use App\Tenancy\CurrentBuilding;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && CurrentBuilding::has()) {
            CurrentBuilding::forget();
        }

        parent::tearDown();
    }
}
