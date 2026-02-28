<?php declare(strict_types=1);

namespace Nette\McpInspector\Database;

use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section `database:` of config/mcp-inspector.neon; registers the Explorer accessor and toolkit.
 */
final class DatabaseExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			// false lets db_query modify data (UPDATE, DELETE, DDL…); true allows SELECT-like statements only
			'readOnly' => Expect::bool(true),
			// maximum number of rows returned by db_query
			'rowLimit' => Expect::int(100)->min(1),
		]);
	}


	public function loadConfiguration(): void
	{
		$config = $this->getConfig();
		assert($config instanceof \stdClass);

		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('accessor'))
			->setFactory(ExplorerAccessor::class);
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(DatabaseToolkit::class, [
				'readOnly' => $config->readOnly,
				'rowLimit' => $config->rowLimit,
			]);
	}
}
