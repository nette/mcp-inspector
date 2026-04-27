<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Nette\DI\Container;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;


/**
 * Creates configured MCP server instances. Built-in toolkits self-register via
 * static `tryCreate(ContainerAccessor)`; project toolkits are auto-discovered
 * by {@see Toolkit} interface from the DI container. Subclass and override
 * {@see registerBuiltInToolkits()} or {@see registerProjectToolkits()} to customize.
 */
class ServerFactory
{
	protected const BuiltInToolkits = [
		Toolkits\DIToolkit::class,
	];


	public function __construct(
		protected string $projectDir,
		protected string $bootstrapPath = 'mcp-bootstrap.php',
	) {
	}


	/**
	 * Build the server. Pass PSR-17 factories only when running over HTTP.
	 */
	public function create(
		?ResponseFactoryInterface $responseFactory = null,
		?StreamFactoryInterface $streamFactory = null,
	): Server
	{
		$accessor = new BootstrapBridge($this->projectDir, $this->bootstrapPath);
		$container = $accessor->getContainer();
		$this->assertNotProduction($container);

		$server = new Server($responseFactory, $streamFactory);
		$this->registerProjectToolkits($server, $container);
		$this->registerBuiltInToolkits($server, $accessor);

		return $server;
	}


	/**
	 * Refuse to start against a production-mode app: full DI introspection would expose
	 * configuration (best-effort masked) that's not safe to hand to an MCP client.
	 * Override `MCP_INSPECTOR_ALLOW_PRODUCTION=1` if you really know what you're doing.
	 */
	protected function assertNotProduction(Container $container): void
	{
		if ($container->getParameter('debugMode') === false && getenv('MCP_INSPECTOR_ALLOW_PRODUCTION') !== '1') {
			throw new \RuntimeException(
				'MCP Inspector refuses to run against a production-mode application. '
				. 'Switch the project to debug mode, or set MCP_INSPECTOR_ALLOW_PRODUCTION=1 to override.',
			);
		}
	}


	/**
	 * Register the built-in toolkits shipped with MCP Inspector. Override to add or replace.
	 * Skips a built-in if a project toolkit registered earlier is already an instance of it.
	 */
	protected function registerBuiltInToolkits(Server $server, ContainerAccessor $accessor): void
	{
		foreach (static::BuiltInToolkits as $class) {
			if ($server->hasToolkitOfType($class)) {
				continue; // overridden by a project toolkit
			}
			$toolkit = $class::tryCreate($accessor);
			if ($toolkit !== null) {
				$server->addToolkit($toolkit);
			}
		}
	}


	/**
	 * Auto-discover project toolkits from the DI container by {@see Toolkit} interface.
	 * Override to use a different discovery strategy (tags, attributes, etc.).
	 */
	protected function registerProjectToolkits(Server $server, Container $container): void
	{
		foreach ($container->findByType(Toolkit::class) as $name) {
			$toolkit = $container->getService($name);
			assert($toolkit instanceof Toolkit);
			$server->addToolkit($toolkit);
		}
	}
}
