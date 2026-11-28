<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Never reach a real courier account from a developer's .env: tests that need KiriminAja set a fake key themselves.
        config(['services.kiriminaja.key' => null]);
    }
}
