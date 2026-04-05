<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy;

use Nette\DI\CompilerExtension;


/**
 * Registers the Tracy logger accessor and toolkit; the log directory comes from the application's logger.
 */
final class TracyExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('accessor'))
			->setFactory(LoggerAccessor::class);
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(TracyToolkit::class);
	}
}
