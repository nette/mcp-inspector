<?php declare(strict_types=1);

namespace Nette\McpInspector\Router;

use Nette\DI\CompilerExtension;


/**
 * Registers the router accessor and toolkit; the base URL comes from the application's request (`http: baseUrl:` in the CLI).
 */
final class RouterExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('accessor'))
			->setFactory(RouterAccessor::class);
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(RouterToolkit::class);
	}
}
