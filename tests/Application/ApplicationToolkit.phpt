<?php declare(strict_types=1);

/**
 * Test: ApplicationToolkit describes the inspected application.
 */

use Nette\McpInspector\Application\ApplicationToolkit;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Server;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$project = createTestProject(<<<'NEON'
	database:
		dsn: 'sqlite::memory:'
	services:
		tracy.logger: Tracy\Logger(%tempDir%/../log)
	NEON);
FileSystem::createDir("$project/log");
FileSystem::write("$project/composer.lock", Json::encode([
	'packages' => [
		['name' => 'nette/application', 'version' => 'v3.3.0'],
		['name' => 'nette/di', 'version' => 'v3.2.7'],
		['name' => 'symfony/console', 'version' => 'v7.0.0'],
	],
	'packages-dev' => [
		['name' => 'tracy/tracy', 'version' => 'v2.12.1'],
	],
]));

$container = Inspector::createContainer($project);
Assert::contains('app_get_info', $container->getByType(Server::class)->getToolNames());
assertToolsComplete($container->getByType(Server::class));
Assert::same('sqlite', callTool($container->getByType(Server::class), 'app_get_info')['database']);

$info = $container->getByType(ApplicationToolkit::class)->getInfo();
Assert::same(PHP_VERSION, $info->php);
Assert::same('v3.3.0', $info->nette);
Assert::same(['nette/application' => 'v3.3.0', 'nette/di' => 'v3.2.7', 'tracy/tracy' => 'v2.12.1'], $info->packages);
Assert::same('sqlite', $info->database);
Assert::match('%a%/log', $info->directories['logDir']);
Assert::match('%a%/temp', $info->directories['tempDir']);


// without composer.lock and database
$project = createTestProject();
$info = Inspector::createContainer($project)->getByType(ApplicationToolkit::class)->getInfo();
Assert::same([], $info->packages);
Assert::null($info->nette);
Assert::null($info->database);
