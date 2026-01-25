<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use function sprintf;


/**
 * The inspected application's DI container, built by the Configurator returned from
 * mcp-bootstrap.php. Config edits are picked up live: the container is recompiled and re-instantiated
 * when its configuration changes; on failure the last valid container is kept and the error is
 * exposed via {@see getLastError()}.
 */
final class AppContainer
{
	private ?Configurator $configurator = null;
	private ?Container $container = null;
	private ?\Throwable $lastError = null;


	public function __construct(
		private readonly string $projectDir,
		private readonly string $bootstrapPath = 'mcp-bootstrap.php',
	) {
	}


	/**
	 * Returns the current DI container. The bootstrap script runs once; on every call the compiled container
	 * is checked for expiration and a new instance is created only when its class changed. If that fails and
	 * a previous container exists, it is returned; a failure on the first call is rethrown.
	 */
	public function get(): Container
	{
		try {
			$this->configurator ??= $this->loadConfigurator();
			if ($this->container === null || $this->container::class !== $this->configurator->loadContainer()) {
				$this->container = $this->configurator->createContainer();
			}
			$this->lastError = null;
		} catch (\Throwable $e) {
			$this->lastError = $e;
			if ($this->container === null) {
				throw $e instanceof \RuntimeException
					? $e
					: new \RuntimeException('Unable to boot the application: ' . $e->getMessage(), 0, $e);
			}
		}

		return $this->container;
	}


	/**
	 * Returns the exception from the most recent failed container rebuild, or null if the latest rebuild succeeded.
	 */
	public function getLastError(): ?\Throwable
	{
		return $this->lastError;
	}


	private function loadConfigurator(): Configurator
	{
		$path = FileSystem::isAbsolute($this->bootstrapPath)
			? $this->bootstrapPath
			: $this->projectDir . '/' . $this->bootstrapPath;
		if (!file_exists($path)) {
			throw new \RuntimeException("Bootstrap script not found: $path");
		}

		$configurator = require $path;
		if (!$configurator instanceof Configurator) {
			throw new \RuntimeException(sprintf(
				'Bootstrap script %s must return a Nette\Bootstrap\Configurator instance, got %s.',
				$path,
				get_debug_type($configurator),
			));
		}

		return $configurator->setDebugMode(true); // auto-rebuild is what makes config changes visible
	}
}
