<?php declare(strict_types=1);

use Nette\DI\Container;
use Nette\McpInspector\Toolkits\DIToolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * @param array<string, string>             $services    name => type
 * @param array<string, array<string, mixed>> $tagsByService  service => (tag => value)
 * @param array<string, list<string>>       $byType      type => list of service names
 * @param array<string, list<string>>       $autowiredByType  type => list of autowired service names
 * @param array<string, mixed>              $parameters
 * @param array<string, string>             $aliases     alias => canonical
 * @param array<string, object>             $instances   created service map
 */
function createToolkit(
	array $services = [],
	array $tagsByService = [],
	array $byType = [],
	array $autowiredByType = [],
	array $parameters = [],
	array $aliases = [],
	array $instances = [],
): DIToolkit
{
	$container = Mockery::mock(Container::class);
	$container->shouldReceive('getServiceTypes')->andReturn($services);
	$container->shouldReceive('getInstantiatedServices')->andReturn($instances);
	$container->shouldReceive('getAliases')->andReturn($aliases);
	$container->shouldReceive('getParameters')->andReturn($parameters);

	$container->shouldReceive('hasService')->andReturnUsing(
		fn(string $name) => isset($services[$name]) || isset($aliases[$name]),
	);
	$container->shouldReceive('getServiceType')->andReturnUsing(
		fn(string $name) => $services[$name] ?? $services[$aliases[$name] ?? $name] ?? '',
	);
	$container->shouldReceive('getServiceTags')->andReturnUsing(
		fn(string $name) => $tagsByService[$name] ?? [],
	);
	$container->shouldReceive('findByType')->andReturnUsing(
		fn(string $type) => $byType[$type] ?? [],
	);
	$container->shouldReceive('findAutowired')->andReturnUsing(
		fn(string $type) => $autowiredByType[$type] ?? [],
	);
	$container->shouldReceive('findByTag')->andReturnUsing(function (string $tag) use ($tagsByService) {
		$result = [];
		foreach ($tagsByService as $service => $tags) {
			if (array_key_exists($tag, $tags)) {
				$result[$service] = $tags[$tag];
			}
		}
		return $result;
	});

	return new DIToolkit(wrapAccessor($container));
}


test('getServices returns all services', function () {
	$toolkit = createToolkit(services: [
		'foo' => 'stdClass',
		'bar' => 'ArrayObject',
	]);
	$result = $toolkit->getServices();

	Assert::same(2, $result['count']);
	Assert::same('foo', $result['services'][0]['name']);
	Assert::same('stdClass', $result['services'][0]['type']);
	Assert::same('bar', $result['services'][1]['name']);
	Assert::same('ArrayObject', $result['services'][1]['type']);
});


test('getServices filters by name', function () {
	$toolkit = createToolkit(services: [
		'database.connection' => 'PDO',
		'cache.storage' => 'stdClass',
	]);
	$result = $toolkit->getServices('database');

	Assert::same(1, $result['count']);
	Assert::same('database.connection', $result['services'][0]['name']);
});


test('getServices filters by type', function () {
	$toolkit = createToolkit(services: [
		'foo' => 'stdClass',
		'bar' => 'ArrayObject',
	]);
	$result = $toolkit->getServices('Array');

	Assert::same(1, $result['count']);
	Assert::same('bar', $result['services'][0]['name']);
});


test('getService returns details with tags and instantiation status', function () {
	$instance = new stdClass;
	$toolkit = createToolkit(
		services: ['myService' => 'stdClass'],
		tagsByService: ['myService' => ['mytag' => 'value']],
		instances: ['myService' => $instance],
	);
	$result = $toolkit->getService('myService');

	Assert::same('myService', $result['name']);
	Assert::same('stdClass', $result['type']);
	Assert::same(['mytag' => 'value'], $result['tags']);
	Assert::true($result['created']);
});


test('getService reports not-yet-instantiated services', function () {
	$toolkit = createToolkit(services: ['lazy' => 'stdClass']);
	$result = $toolkit->getService('lazy');

	Assert::false($result['created']);
});


test('getService returns error for non-existent service', function () {
	$toolkit = createToolkit();
	$result = $toolkit->getService('nonexistent');

	Assert::same("Service 'nonexistent' not found", $result['error']);
});


test('getParameterNames lists flattened parameter names', function () {
	$toolkit = createToolkit(parameters: [
		'appDir' => '/var/www/app',
		'debugMode' => true,
		'database' => [
			'host' => 'localhost',
			'password' => 'secret123',
		],
	]);
	$result = $toolkit->getParameterNames();

	Assert::same(4, $result['count']);
	Assert::same(['appDir', 'debugMode', 'database.host', 'database.password'], $result['names']);
});


test('getParameter returns single value by name', function () {
	$toolkit = createToolkit(parameters: [
		'appDir' => '/var/www/app',
		'debugMode' => true,
	]);

	Assert::same(['value' => '/var/www/app'], $toolkit->getParameter('appDir'));
	Assert::same(['value' => true], $toolkit->getParameter('debugMode'));
	Assert::same(['error' => "Parameter 'missing' not found"], $toolkit->getParameter('missing'));
});


test('getParameter resolves nested paths via dotted notation', function () {
	$toolkit = createToolkit(parameters: [
		'database' => [
			'host' => 'localhost',
			'port' => 5432,
		],
	]);

	Assert::same(['value' => 'localhost'], $toolkit->getParameter('database.host'));
	Assert::same(['value' => 5432], $toolkit->getParameter('database.port'));
	Assert::same(['error' => "Parameter 'database.unknown' not found"], $toolkit->getParameter('database.unknown'));
});


test('getParameter masks sensitive leaf values', function () {
	$toolkit = createToolkit(parameters: [
		'database' => [
			'password' => 'secret123',
			'apiKey' => 'sk-abc',
		],
	]);

	Assert::same(['value' => '***MASKED***'], $toolkit->getParameter('database.password'));
	Assert::same(['value' => '***MASKED***'], $toolkit->getParameter('database.apiKey'));
});


enum DIToolkitTestBlog: int
{
	case First = 1;
	case Second = 2;
}

enum DIToolkitTestPlain
{
	case Foo;
}

test('getParameter renders backed enum values as structured object', function () {
	$toolkit = createToolkit(parameters: [
		'blog' => DIToolkitTestBlog::First,
	]);

	Assert::same(
		['value' => ['enum' => 'DIToolkitTestBlog::First', 'value' => 1]],
		$toolkit->getParameter('blog'),
	);
});


test('getParameter renders unit (non-backed) enums without value field', function () {
	$toolkit = createToolkit(parameters: [
		'mode' => DIToolkitTestPlain::Foo,
	]);

	Assert::same(
		['value' => ['enum' => 'DIToolkitTestPlain::Foo']],
		$toolkit->getParameter('mode'),
	);
});


test('getAliases returns alias map', function () {
	$toolkit = createToolkit(
		services: ['database.default.explorer' => 'Nette\Database\Explorer'],
		aliases: ['nette.database.default.context' => 'database.default.explorer'],
	);
	$result = $toolkit->getAliases();

	Assert::same(1, $result['count']);
	Assert::same(['nette.database.default.context' => 'database.default.explorer'], $result['aliases']);
});


test('findByTag returns services with specific tag', function () {
	$toolkit = createToolkit(
		services: ['foo' => 'stdClass', 'bar' => 'ArrayObject'],
		tagsByService: ['foo' => ['console.command' => 'list']],
	);
	$result = $toolkit->findByTag('console.command');

	Assert::same(1, $result['count']);
	Assert::same('foo', $result['services'][0]['name']);
	Assert::same('list', $result['services'][0]['tagValue']);
});


test('findByType returns services with autowired flag', function () {
	$toolkit = createToolkit(
		services: ['default' => 'PDO', 'sync' => 'PDO'],
		byType: ['PDO' => ['default', 'sync']],
		autowiredByType: ['PDO' => ['default']],
	);
	$result = $toolkit->findByType('PDO');

	Assert::same(2, $result['count']);
	Assert::same('default', $result['services'][0]['name']);
	Assert::true($result['services'][0]['autowiredFor']);
	Assert::same('sync', $result['services'][1]['name']);
	Assert::false($result['services'][1]['autowiredFor']);
});
