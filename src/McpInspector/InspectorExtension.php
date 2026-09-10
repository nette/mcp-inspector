<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Mcp\Capability\Registry\ReferenceHandler;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Core of the inspector: the accessor to the inspected application's container and the Server,
 * which collects every Toolkit service. Section `inspector:`.
 */
final class InspectorExtension extends CompilerExtension
{
	private ServiceDefinition $server;


	public function __construct(
		private readonly string $projectDir,
		private readonly string $bootstrapPath,
	) {
	}


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			// false keeps the inspector from starting at all
			'enabled' => Expect::bool(true),
			// tool names or fnmatch() patterns hidden from the agent
			'disableTools' => Expect::listOf('string'),
			// key names whose values are masked in results (whole words; camelCase and snake_case both match)
			'mask' => Expect::listOf('string')->default(Masking::DefaultKeys),
			// text appended to the server instructions the agent reads on connect (workflows of custom toolkits)
			'instructions' => Expect::string(''),
		]);
	}


	public function loadConfiguration(): void
	{
		$config = $this->getConfig();
		assert($config instanceof \stdClass);
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('appContainer'))
			->setFactory(AppContainer::class, [$this->projectDir, $this->bootstrapPath]);

		$builder->addDefinition($this->prefix('masking'))
			->setFactory(Masking::class, [$config->mask]);

		$builder->addDefinition($this->prefix('referenceHandler'))
			->setFactory(ReferenceHandler::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('toolCallHandler'))
			->setFactory(ToolCallHandler::class, [new Reference($this->prefix('referenceHandler'))]);

		$this->server = $builder->addDefinition($this->prefix('server'))
			->setFactory(Server::class, [
				'enabled' => $config->enabled,
				'disabledTools' => $config->disableTools,
				'instructions' => $config->instructions,
			]);
	}


	public function beforeCompile(): void
	{
		$this->server->setArgument('toolkits', array_map(
			fn(string $name) => new Reference($name),
			array_keys($this->getContainerBuilder()->findByType(Toolkit::class)),
		));
	}
}
