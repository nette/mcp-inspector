<?php declare(strict_types=1);

/**
 * Test: Nette\McpInspector\Inspector builds the inspector container from config/mcp-inspector.neon.
 */

use Mcp\Capability\Attribute\McpTool;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Server;
use Nette\McpInspector\Toolkit;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


class FirstToolkit implements Toolkit
{
	public function __construct(
		public AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return true;
	}


	#[McpTool(name: 'first_one')]
	public function one(): array
	{
		return [];
	}


	/**
	 * @param int $limit  how many
	 */
	#[McpTool]
	public function first_two(int $limit = 10): array
	{
		return ['limit' => $limit];
	}


	public function notATool(): void
	{
	}
}


class UnavailableToolkit implements Toolkit
{
	public function isAvailable(): bool
	{
		return false;
	}


	#[McpTool(name: 'never')]
	public function never(): array
	{
		return [];
	}
}


$project = createTestProject();
$mtime = time();
$writeConfig = function (string $neon) use ($project, &$mtime): void {
	$file = "$project/" . Inspector::ConfigFile;
	FileSystem::write($file, $neon);
	touch($file, $mtime += 10); // a mtime change is what triggers recompilation
	clearstatcache();
};


// no configuration file: defaults, no toolkits
$container = Inspector::createContainer($project);
$server = $container->getByType(Server::class);
Assert::true($server->isEnabled());
Assert::notContains('first_one', $server->getToolNames()); // built-in toolkits only
Assert::type(AppContainer::class, $container->getByType(AppContainer::class));


// toolkits are ordinary services; unavailable ones and disabled tools are left out
$writeConfig(<<<'NEON'
	inspector:
		enabled: false
		disableTools: [first_t*, app_*, presenter_*, router_*, db_*, di_*, tracy_*]

	services:
		- FirstToolkit
		- UnavailableToolkit
	NEON);
$container = Inspector::createContainer($project);
$server = $container->getByType(Server::class);
Assert::false($server->isEnabled());
Assert::same(['first_one'], $server->getToolNames());
Assert::same($container->getByType(AppContainer::class), $container->getByType(FirstToolkit::class)->app);


// call() goes through the SDK: arguments are mapped by name and validated against the schema
$writeConfig("services:\n\t- FirstToolkit\n");
$server = Inspector::createContainer($project)->getByType(Server::class);
Assert::same(['limit' => 5], $server->call('first_two', ['limit' => 5]));
Assert::same(['limit' => 10], $server->call('first_two'));
Assert::exception(
	fn() => $server->call('first_two', ['limit' => 'many']),
	Mcp\Exception\ToolCallException::class,
	"Invalid arguments for tool 'first_two': %a%",
);
Assert::exception(fn() => $server->call('nope'), InvalidArgumentException::class, "Unknown tool 'nope'.");


// a project without temp/ gets its container compiled into the system temp directory
$bare = getTempDir() . '/bare';
FileSystem::delete($bare);
FileSystem::write("$bare/mcp-bootstrap.php", FileSystem::read("$project/mcp-bootstrap.php"));
Assert::type(Server::class, Inspector::createContainer($bare)->getByType(Server::class));
Assert::false(is_dir("$bare/temp"));


// configuration errors are reported
$writeConfig("inspector:\n\tenabled: maybe\n");
Assert::exception(
	fn() => Inspector::createContainer($project),
	Nette\DI\InvalidConfigurationException::class,
	"%a%expects to be bool, 'maybe' given.",
);

$writeConfig("inspector:\n\tenabled: [\n");
Assert::exception(
	fn() => Inspector::createContainer($project),
	RuntimeException::class,
	'Invalid %a%mcp-inspector.neon: %a%',
);

Assert::exception(
	fn() => Inspector::createContainer($project, configFile: "$project/missing.neon"),
	RuntimeException::class,
	'Inspector configuration not found: %a%',
);
