<?php declare(strict_types=1);

/**
 * Test: Nette\McpInspector\AppContainer builds the application container and reloads it on config changes.
 */

use Nette\McpInspector\AppContainer;
use Nette\Utils\FileSystem;
use Tester\Assert;


require __DIR__ . '/bootstrap.php';


$project = createTestProject();
$config = "$project/config.neon";
$mtime = time();
$writeConfig = function (string $neon) use ($config, &$mtime): void {
	FileSystem::write($config, $neon);
	touch($config, $mtime += 10); // a mtime change is what triggers recompilation
	clearstatcache();
};

$writeConfig("parameters:\n\tversion: 1\n");
$accessor = new AppContainer($project);


// first call builds the container in debug mode
$container = $accessor->get();
Assert::same(1, $container->getParameter('version'));
Assert::true($container->getParameter('debugMode'));
Assert::null($accessor->getLastError());


// unchanged config: the same instance is returned
Assert::same($container, $accessor->get());


// config change: a new container reflecting it
$writeConfig("parameters:\n\tversion: 2\n");
$updated = $accessor->get();
Assert::notSame($container, $updated);
Assert::same(2, $updated->getParameter('version'));
Assert::same($updated, $accessor->get());


// broken config: the last valid container is kept and the error is reported
$writeConfig("parameters:\n\tversion: [\n");
Assert::same($updated, $accessor->get());
Assert::type(Nette\Neon\Exception::class, $accessor->getLastError());


// fixed config: back to normal
$writeConfig("parameters:\n\tversion: 3\n");
Assert::same(3, $accessor->get()->getParameter('version'));
Assert::null($accessor->getLastError());


// failure on the first call is rethrown as RuntimeException, whatever the cause
FileSystem::write("$project/invalid/config.neon", "parameters:\n\tversion: [\n");
FileSystem::write("$project/invalid/mcp-bootstrap.php", FileSystem::read("$project/mcp-bootstrap.php"));
$e = Assert::exception(
	fn() => (new AppContainer("$project/invalid"))->get(),
	RuntimeException::class,
	'Unable to boot the application: %a%',
);
Assert::type(Nette\Neon\Exception::class, $e->getPrevious());

FileSystem::write("$project/broken/mcp-bootstrap.php", "<?php\nreturn 'nope';\n");
Assert::exception(
	fn() => (new AppContainer("$project/broken"))->get(),
	RuntimeException::class,
	'%a%must return a Nette\Bootstrap\Configurator instance, got string.',
);

Assert::exception(
	fn() => (new AppContainer("$project/missing"))->get(),
	RuntimeException::class,
	'Bootstrap script not found: %a%',
);
