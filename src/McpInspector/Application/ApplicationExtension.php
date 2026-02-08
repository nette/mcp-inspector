<?php declare(strict_types=1);

namespace Nette\McpInspector\Application;

use Nette\DI\CompilerExtension;


/**
 * Registers the application toolkit; it has no configuration of its own.
 */
final class ApplicationExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(ApplicationToolkit::class, ['projectDir' => $builder->parameters['projectDir']]);
	}
}
