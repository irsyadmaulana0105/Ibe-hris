<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $environment = $app->environment();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.sqlite.database');

        if ($environment !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Unsafe test database configuration: env=%s connection=%s database=%s. Clear Laravel config cache before testing.',
                $environment,
                $connection,
                (string) $database,
            ));
        }

        return $app;
    }
}
