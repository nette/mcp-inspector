<?php declare(strict_types=1);

namespace Nette\McpInspector\Bridge;

use Nette\DI\Container;
use Nette\McpInspector\ContainerAccessor;
use function sprintf;


/**
 * Loads container from project's mcp-bootstrap.php (a closure returning Nette\DI\Container).
 * Rebuilds on every call so config edits are picked up live; on rebuild failure keeps the
 * last-known-good container and exposes the error via {@see decorateResult()}.
 */
class BootstrapBridge implements ContainerAccessor
{
	/** @var ?\Closure(): Container */
	private ?\Closure $factory = null;
	private ?Container $container = null;
	private ?\Throwable $lastError = null;


	public function __construct(
		private string $projectDir = '.',
		private string $bootstrapPath = 'mcp-bootstrap.php',
	) {
	}


	/**
	 * Returns the current DI container, rebuilding it on every call.
	 *
	 * If rebuild fails and a previously valid container exists, returns that one
	 * (use {@see getLastError()} to detect the stale state). If rebuild fails on
	 * the very first call (no fallback available), the underlying exception is rethrown.
	 */
	public function getContainer(): Container
	{
		try {
			$container = $this->buildContainer();
			$this->container = $container;
			$this->lastError = null;
			return $container;
		} catch (\Throwable $e) {
			$this->lastError = $e;
			if ($this->container === null) {
				throw $e;
			}
			return $this->container;
		}
	}


	/**
	 * Returns the exception from the most recent failed container rebuild,
	 * or null if the latest rebuild succeeded. Resets after a successful rebuild.
	 */
	public function getLastError(): ?\Throwable
	{
		return $this->lastError;
	}


	/**
	 * Adds a `_warning` key to a tool result if the last container rebuild failed.
	 * Toolkits should pipe their successful results through this so the user
	 * is informed they're seeing data from a stale (last-known-good) container.
	 *
	 * @param  array<string, mixed>  $result
	 * @return array<string, mixed>
	 */
	public function decorateResult(array $result): array
	{
		if ($this->lastError !== null) {
			$result['_warning'] = sprintf(
				'Container rebuild failed (%s: %s). Showing data from last successful build. Fix and re-run to refresh.',
				$this->lastError::class,
				$this->lastError->getMessage(),
			);
		}
		return $result;
	}


	private function buildContainer(): Container
	{
		if ($this->factory === null) {
			$path = $this->resolvePath($this->bootstrapPath);
			if (!file_exists($path)) {
				throw new \RuntimeException("Bootstrap script not found: $path");
			}

			$factory = require $path;
			if (!$factory instanceof \Closure) {
				throw new \RuntimeException(sprintf(
					'Bootstrap script %s must return a Closure that produces a Nette\DI\Container, got %s.',
					$path,
					get_debug_type($factory),
				));
			}
			$this->factory = $factory;
		}

		$result = ($this->factory)();
		if (!$result instanceof Container) {
			throw new \RuntimeException(sprintf(
				'Bootstrap script %s closure must return a Nette\DI\Container instance, got %s.',
				$this->bootstrapPath,
				get_debug_type($result),
			));
		}

		return $result;
	}


	private function resolvePath(string $path): string
	{
		return preg_match('~^(?:[A-Za-z]:|/)~', $path) === 1
			? $path
			: $this->projectDir . '/' . $path;
	}
}
