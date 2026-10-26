<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must opt into AI with dummy credentials and mocked responses.
        config(['services.gemini.key' => null]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
