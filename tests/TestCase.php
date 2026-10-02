<?php

namespace Tests;

use App\Services\BranchContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * BranchContext keeps request-scoped tenant state in statics. Real requests
     * reset it per lifecycle, but sequential tests in one process do not, so a
     * request in one test can leak scope into the next (particularly once a
     * model carries a branch scope). Reset for every test so suites run
     * order-independently.
     */
    protected function setUp(): void
    {
        parent::setUp();

        BranchContext::reset();
    }
}
