<?php

declare(strict_types=1);

namespace FileHutch\Tests\Laravel;

use FileHutch\Client;
use FileHutch\Laravel\DirectUploads;
use FileHutch\Laravel\FileHutchServiceProvider;
use FileHutch\Laravel\Policies;
use FileHutch\Tests\Support\FakeHttp;
use FileHutch\Tests\Support\Fixtures as F;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeHttp $http;

    protected function getPackageProviders($app): array
    {
        return [FileHutchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('filehutch.api_key', F::API_KEY);
        $app['config']->set('filehutch.url', F::BASE);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeHttp();
        $this->app->instance(Client::class, F::client($this->http));
        Policies::flush();
        DirectUploads::authorize(null);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('avatar_file_id')->nullable();
            $table->string('contract_file_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DirectUploads::authorize(null);
        parent::tearDown();
    }
}
