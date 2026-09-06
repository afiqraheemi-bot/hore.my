<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        try {
            DB::purge('pgsql_secondary');
            DB::disconnect('pgsql');
        } finally {
            parent::tearDown();
        }
    }
}
