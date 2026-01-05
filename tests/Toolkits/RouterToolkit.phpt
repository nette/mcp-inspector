<?php

declare(strict_types=1);

use Nette\Application\Routers\RouteList;
use Nette\DI\Container;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkits\RouterToolkit;
use Nette\Routing\Router;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('tryCreate returns null when Router not configured', function () {
	$container = Mockery::mock(Container::class);
	$container->shouldReceive('getByType')->with(Router::class)->andThrow(new Exception('Not found'));

	$bridge = Mockery::mock(BootstrapBridge::class);
	$bridge->shouldReceive('getContainer')->andReturn($container);

	$toolkit = RouterToolkit::tryCreate($bridge);

	Assert::null($toolkit);
});


test('getRoutes returns list of routes', function () {
	$router = new RouteList;

	$toolkit = new RouterToolkit($router);
	$result = $toolkit->getRoutes();

	Assert::same(0, $result['count']);
	Assert::type('array', $result['routes']);
});


test('matchUrl returns matched route info', function () {
	$router = Mockery::mock(Router::class);
	$router->shouldReceive('match')->andReturn([
		'presenter' => 'Article',
		'action' => 'show',
		'id' => '123',
	]);

	$toolkit = new RouterToolkit($router);
	$result = $toolkit->matchUrl('/article/123');

	Assert::true($result['matched']);
	Assert::same('Article', $result['presenter']);
	Assert::same('show', $result['action']);
	Assert::same(['id' => '123'], $result['params']);
});


test('matchUrl returns no match', function () {
	$router = Mockery::mock(Router::class);
	$router->shouldReceive('match')->andReturn(null);

	$toolkit = new RouterToolkit($router);
	$result = $toolkit->matchUrl('/nonexistent');

	Assert::false($result['matched']);
	Assert::same('No route matches this URL', $result['error']);
});


test('matchUrl handles full URLs', function () {
	$router = Mockery::mock(Router::class);
	$router->shouldReceive('match')->andReturn([
		'presenter' => 'Homepage',
		'action' => 'default',
	]);

	$toolkit = new RouterToolkit($router);
	$result = $toolkit->matchUrl('https://example.com/');

	Assert::true($result['matched']);
	Assert::same('Homepage', $result['presenter']);
});


test('generateUrl returns error when LinkGenerator not configured', function () {
	$router = Mockery::mock(Router::class);

	$toolkit = new RouterToolkit($router);
	$result = $toolkit->generateUrl('Article:show');

	Assert::same('LinkGenerator not configured', $result['error']);
});


test('generateUrl returns generated URL', function () {
	$router = Mockery::mock(Router::class);

	// LinkGenerator is final, use anonymous class instead
	$linkGenerator = new class {
		public function link(string $dest, array $params = []): string
		{
			return '/article/123';
		}
	};

	$toolkit = new RouterToolkit($router, $linkGenerator);
	$result = $toolkit->generateUrl('Article:show', ['id' => 123]);

	Assert::same('Article:show', $result['destination']);
	Assert::same(['id' => 123], $result['params']);
	Assert::same('/article/123', $result['url']);
});


test('generateUrl handles errors', function () {
	$router = Mockery::mock(Router::class);

	// LinkGenerator is final, use anonymous class instead
	$linkGenerator = new class {
		public function link(string $dest, array $params = []): string
		{
			throw new Exception('Cannot generate link');
		}
	};

	$toolkit = new RouterToolkit($router, $linkGenerator);
	$result = $toolkit->generateUrl('Invalid:action');

	Assert::same('Invalid:action', $result['destination']);
	Assert::same('Cannot generate link', $result['error']);
});
