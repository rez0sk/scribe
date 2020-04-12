<?php

namespace Knuckles\Scribe\Tests;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Knuckles\Scribe\ScribeServiceProvider;
use Knuckles\Scribe\Tests\Fixtures\TestController;
use Knuckles\Scribe\Tests\Fixtures\TestGroupController;
use Knuckles\Scribe\Tests\Fixtures\TestPartialResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestUser;
use Knuckles\Scribe\Tools\Utils;
use Orchestra\Testbench\TestCase;
use ReflectionException;

class GenerateDocumentationTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = app(\Illuminate\Database\Eloquent\Factory::class);
        $factory->define(TestUser::class, function () {
            return [
                'id' => 4,
                'first_name' => 'Tested',
                'last_name' => 'Again',
                'email' => 'a@b.com',
            ];
        });
    }

    public function tearDown(): void
    {
        Utils::deleteDirectoryAndContents('/public/docs');
        Utils::deleteDirectoryAndContents('/resources/docs');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            ScribeServiceProvider::class,
        ];
    }

    /** @test */
    public function can_process_traditional_laravel_route_syntax()
    {
        RouteFacade::get('/api/test', TestController::class . '@withEndpointDescription');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/test', $output);
    }

    /** @test */
    public function can_process_closure_routes()
    {
        RouteFacade::get('/api/closure', function () {
            return 'hi';
        });

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/closure', $output);
    }

    /**
     * @group dingo
     * @test
     */
    public function can_process_routes_on_dingo()
    {
        $api = app(\Dingo\Api\Routing\Router::class);
        $api->version('v1', function ($api) {
            $api->get('/closure', function () {
                return 'foo';
            });
            $api->get('/test', TestController::class . '@withEndpointDescription');
        });

        config(['scribe.router' => 'dingo']);
        config(['scribe.routes.0.match.prefixes' => ['*']]);
        config(['scribe.routes.0.match.versions' => ['v1']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] closure', $output);
        $this->assertStringContainsString('Processed route: [GET] test', $output);
    }

    /** @test */
    public function can_process__callable_tuple_syntax()
    {
        RouteFacade::get('/api/array/test', [TestController::class, 'withEndpointDescription']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/array/test', $output);
    }

    /** @test */
    public function can_skip_single_routes()
    {
        RouteFacade::get('/api/skip', TestController::class . '@skip');
        RouteFacade::get('/api/test', TestController::class . '@withEndpointDescription');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Skipping route: [GET] api/skip', $output);
        $this->assertStringContainsString('Processed route: [GET] api/test', $output);
    }

    /** @test */
    public function can_skip_non_existent_response_files()
    {
        RouteFacade::get('/api/non-existent', TestController::class . '@withNonExistentResponseFile');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Skipping route: [GET] api/non-existent', $output);
        $this->assertStringContainsString('@responseFile i-do-not-exist.json does not exist', $output);
    }

    /** @test */
    public function can_parse_resource_routes()
    {
        RouteFacade::resource('/api/users', TestResourceController::class);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [GET] api/users/create', $output);
        $this->assertStringContainsString('Processed route: [GET] api/users/{user}', $output);
        $this->assertStringContainsString('Processed route: [GET] api/users/{user}/edit', $output);
        $this->assertStringContainsString('Processed route: [POST] api/users', $output);
        $this->assertStringContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);
        $this->assertStringContainsString('Processed route: [DELETE] api/users/{user}', $output);
    }

    /** @test */
    public function can_parse_partial_resource_routes()
    {
        RouteFacade::resource('/api/users', TestResourceController::class)
                ->only(['index', 'store']);

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [POST] api/users', $output);

        $this->assertStringNotContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);
        $this->assertStringNotContainsString('Processed route: [DELETE] api/users/{user}', $output);

        RouteFacade::apiResource('/api/users', TestResourceController::class)
                ->only(['index', 'store']);
        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [POST] api/users', $output);

        $this->assertStringNotContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);
        $this->assertStringNotContainsString('Processed route: [DELETE] api/users/{user}', $output);
    }

    /** @test */
    public function supports_partial_resource_controller()
    {
        RouteFacade::resource('/api/users', TestPartialResourceController::class);

        config(['scribe.routes.0.prefixes' => ['api/*']]);

        $output = $this->artisan('scribe:generate');

        $this->assertStringContainsString('Processed route: [GET] api/users', $output);
        $this->assertStringContainsString('Processed route: [PUT,PATCH] api/users/{user}', $output);

    }

    /** @test */
    public function generated_postman_collection_file_is_correct()
    {
        RouteFacade::get('/api/withDescription', [TestController::class, 'withEndpointDescription']);
        RouteFacade::get('/api/withResponseTag', TestController::class . '@withResponseTag');
        RouteFacade::post('/api/withBodyParameters', TestController::class . '@withBodyParameters');
        RouteFacade::get('/api/withQueryParameters', TestController::class . '@withQueryParameters');
        RouteFacade::get('/api/withAuthTag', TestController::class . '@withAuthenticatedTag');
        RouteFacade::get('/api/withEloquentApiResource', [TestController::class, 'withEloquentApiResource']);
        RouteFacade::get('/api/withEloquentApiResourceCollectionClass', [TestController::class, 'withEloquentApiResourceCollectionClass']);
        RouteFacade::post('/api/withMultipleResponseTagsAndStatusCode', [TestController::class, 'withMultipleResponseTagsAndStatusCode']);
        RouteFacade::get('/api/echoesUrlParameters/{param}-{param2}/{param3?}', [TestController::class, 'echoesUrlParameters']);

        // We want to have the same values for params each time
        config(['scribe.faker_seed' => 1234]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Authorization' => 'customAuthToken',
                'Custom-Header' => 'NotSoCustom',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_postman_collection_domain_is_correct()
    {
        $domain = 'http://somedomain.test';
        RouteFacade::get('/api/test', TestController::class . '@withEndpointDescription');

        config(['scribe.base_url' => $domain]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'));
        $endpointUrl = $generatedCollection->item[0]->item[0]->request->url->host;
        $this->assertTrue(Str::startsWith($endpointUrl, 'somedomain.test'));
    }

    /** @test */
    public function generated_postman_collection_can_have_custom_url()
    {
        Config::set('scribe.base_url', 'http://yourapp.app');
        RouteFacade::get('/api/test', TestController::class . '@withEndpointDescription');
        RouteFacade::post('/api/responseTag', TestController::class . '@withResponseTag');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection_custom_url.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_postman_collection_can_have_secure_url()
    {
        Config::set('scribe.base_url', 'https://yourapp.app');
        RouteFacade::get('/api/test', TestController::class . '@withEndpointDescription');
        RouteFacade::post('/api/responseTag', TestController::class . '@withResponseTag');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection_with_secure_url.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_postman_collection_can_append_custom_http_headers()
    {
        RouteFacade::get('/api/headers', TestController::class . '@checkCustomHeaders');
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Authorization' => 'customAuthToken',
                'Custom-Header' => 'NotSoCustom',
            ],
        ]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection_with_custom_headers.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_postman_collection_can_have_query_parameters()
    {
        RouteFacade::get('/api/withQueryParameters', TestController::class . '@withQueryParameters');
        // We want to have the same values for params each time
        config(['scribe.faker_seed' => 1234]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection_with_query_parameters.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function generated_postman_collection_can_add_body_parameters()
    {
        RouteFacade::get('/api/withBodyParameters', TestController::class . '@withBodyParameters');
        // We want to have the same values for params each time
        config(['scribe.faker_seed' => 1234]);
        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedCollection = json_decode(file_get_contents(__DIR__ . '/../public/docs/collection.json'), true);
        // The Postman ID varies from call to call; erase it to make the test data reproducible.
        $generatedCollection['info']['_postman_id'] = '';
        $fixtureCollection = json_decode(file_get_contents(__DIR__ . '/Fixtures/collection_with_body_parameters.json'), true);
        $this->assertEquals($fixtureCollection, $generatedCollection);
    }

    /** @test */
    public function can_append_custom_http_headers()
    {
        RouteFacade::get('/api/headers', TestController::class . '@checkCustomHeaders');

        config(['scribe.routes.0.match.prefixes' => ['api/*']]);
        config([
            'scribe.routes.0.apply.headers' => [
                'Authorization' => 'customAuthToken',
                'Custom-Header' => 'NotSoCustom',
            ],
        ]);
        $this->artisan('scribe:generate');

        $generatedMarkdown = $this->getFileContents(__DIR__ . '/../resources/docs/source/groups/0-group-a.md');
        $this->assertContainsIgnoringWhitespace('"Authorization": "customAuthToken","Custom-Header":"NotSoCustom"', $generatedMarkdown);
    }

    /** @test */
    public function can_parse_utf8_response()
    {
        RouteFacade::get('/api/utf8', TestController::class . '@withUtf8ResponseTag');

        config(['scribe.routes.0.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $generatedMarkdown = file_get_contents(__DIR__ . '/../resources/docs/source/groups/0-group-a.md');
        $this->assertStringContainsString('Лорем ипсум долор сит амет', $generatedMarkdown);
    }

    /** @test */
    public function sorts_group_naturally()
    {
        RouteFacade::get('/api/action1', TestGroupController::class . '@action1');
        RouteFacade::get('/api/action1b', TestGroupController::class . '@action1b');
        RouteFacade::get('/api/action2', TestGroupController::class . '@action2');
        RouteFacade::get('/api/action10', TestGroupController::class . '@action10');

        config(['scribe.routes.0.prefixes' => ['api/*']]);
        $this->artisan('scribe:generate');

        $this->assertFileExists(__DIR__ . '/../resources/docs/source/groups/0-1-group-1.md');
        $this->assertFileExists(__DIR__ . '/../resources/docs/source/groups/1-2-group-2.md');
        $this->assertFileExists(__DIR__ . '/../resources/docs/source/groups/2-10-group-10.md');
    }
}
