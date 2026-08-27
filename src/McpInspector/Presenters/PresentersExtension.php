<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters;

use Nette\DI\CompilerExtension;


/**
 * Registers the presenter accessor and toolkit; the mapping comes from the application's PresenterFactory.
 */
final class PresentersExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('accessor'))
			->setFactory(PresenterAccessor::class);
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(PresenterToolkit::class);
	}
}
