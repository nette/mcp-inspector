<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy;

use Nette\McpInspector\AppContainer;
use Tracy\ILogger;
use Tracy\Logger;


/**
 * The inspected application's Tracy logger, i.e. the directory it writes logs and exception reports to.
 */
final class LoggerAccessor
{
	public function __construct(
		private readonly AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->getDirectory() !== null;
	}


	public function getDirectory(): ?string
	{
		if (!interface_exists(ILogger::class)) {
			return null;
		}

		$logger = $this->app->get()->getByType(ILogger::class, throw: false);
		return $logger instanceof Logger && $logger->directory !== null && is_dir($logger->directory)
			? $logger->directory
			: null;
	}
}
