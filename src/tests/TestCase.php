<?php

namespace Tests;

use App\Workflow\FlowTemplates;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\LicenseFactory;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite's order fixtures were written against the in-house flow (manufacture stage, ADR-0012); the retail
        // default of ADR-0038 is provisioned explicitly by the tests that cover it (tests/Feature/Workshop).
        config(['opsone.default_flow_template' => FlowTemplates::IN_HOUSE]);
    }

    protected function tearDown(): void
    {
        LicenseFactory::cleanup();

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
