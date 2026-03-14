<?php declare(strict_types=1);

/**
 * Test: ContainerToolkit over a real application container.
 */

use Mcp\Exception\ToolCallException;
use Nette\McpInspector\DI\ContainerToolkit;
use Nette\McpInspector\DI\Schemas\Service;
use Nette\McpInspector\Inspector;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


class Mailer
{
}

class Logger
{
}


$project = createTestProject(<<<'NEON'
	parameters:
		siteName: Example
		database:
			dsn: 'mysql:host=localhost'
			password: secret
			token: 1234
	services:
		mailer: Mailer
		spare:
			create: Mailer
			autowired: false
			tags: [backup: {priority: 2}]
		logger:
			create: Logger
			tags: [monitor]
	NEON);

$container = Inspector::createContainer($project);
$toolkit = $container->getByType(ContainerToolkit::class);
assertToolsComplete($container->getByType(Nette\McpInspector\Server::class));


test('services are listed with types, tags, aliases and autowiring', function () use ($toolkit) {
	$result = $toolkit->getServices('Mailer');
	Assert::same(2, $result->count);
	Assert::same('Mailer', $result->filter);
	Assert::equal(new Service(name: 'mailer', type: Mailer::class, autowired: true, tags: [], aliases: [], created: false), $result->services[0]);
	Assert::same('spare', $result->services[1]->name);
	Assert::false($result->services[1]->autowired);
	Assert::same(['backup' => ['priority' => 2]], $result->services[1]->tags);

	Assert::true($toolkit->getServices()->count > 3); // framework services included
});


test('a single service, created state and unknown names', function () use ($toolkit, $container) {
	$service = $toolkit->getService('logger');
	Assert::same(Logger::class, $service->type);
	Assert::same(['monitor' => true], $service->tags);
	Assert::false($service->created);

	Assert::exception(fn() => $toolkit->getService('nope'), ToolCallException::class, "Service 'nope' not found");

	// through the server, the result is the JSON object the client sees
	$result = callTool($container->getByType(Nette\McpInspector\Server::class), 'di_get_service', ['name' => 'logger']);
	Assert::same(['name' => 'logger', 'type' => Logger::class, 'autowired' => true, 'tags' => ['monitor' => true], 'aliases' => [], 'created' => false], $result);
});


test('services are found by type and by tag', function () use ($toolkit) {
	$byType = $toolkit->findByType(Mailer::class);
	Assert::same(['mailer', 'spare'], array_column($byType->services, 'name'));
	Assert::same('mailer', $byType->autowired); // spare is excluded from autowiring
	Assert::same(0, $toolkit->findByType('Nonexistent\Type')->count);

	$byTag = $toolkit->findByTag('backup');
	Assert::same('spare', $byTag->services[0]->name);
	Assert::same(['backup' => ['priority' => 2]], $byTag->services[0]->tags);
});


test('parameters are flattened and secrets masked', function () use ($toolkit) {
	$names = $toolkit->getParameterNames()->names;
	Assert::contains('siteName', $names);
	Assert::contains('database.dsn', $names);
	Assert::contains('database.password', $names);

	Assert::same('Example', $toolkit->getParameter('siteName')->value);
	Assert::same('***', $toolkit->getParameter('database.password')->value);
	Assert::same('***', $toolkit->getParameter('database.dsn')->value);
	Assert::same('***', $toolkit->getParameter('database.token')->value); // an integer is a secret too
	Assert::same(['dsn' => '***', 'password' => '***', 'token' => '***'], $toolkit->getParameter('database')->value);

	Assert::exception(fn() => $toolkit->getParameter('database.host'), ToolCallException::class, "Parameter 'database.host' not found");
});
