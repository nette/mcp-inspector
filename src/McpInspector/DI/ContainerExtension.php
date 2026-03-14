<?php declare(strict_types=1);

namespace Nette\McpInspector\DI;

use Nette\DI\CompilerExtension;


/**
 * Registers the DI container toolkit; it has no configuration of its own.
 */
final class ContainerExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$this->getContainerBuilder()->addDefinition($this->prefix('toolkit'))
			->setFactory(ContainerToolkit::class);
	}
}
