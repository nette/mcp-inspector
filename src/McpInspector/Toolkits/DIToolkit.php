<?php

declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkit;


/**
 * Toolkit for DI container introspection.
 */
class DIToolkit implements Toolkit
{
	public static function tryCreate(BootstrapBridge $bridge): ?self
	{
		return new self($bridge->getContainerBuilder());
	}


	public function __construct(
		private ContainerBuilder $builder,
	) {
	}


	/**
	 * List all registered DI container services with their types and autowiring info.
	 * @param string|null $filter Optional filter by service name or type (case-sensitive substring match)
	 */
	#[McpTool(
		name: 'di_get_services',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getServices(?string $filter = null): array
	{
		$services = [];

		foreach ($this->builder->getDefinitions() as $name => $definition) {
			$info = $this->extractBasicInfo($name, $definition);

			if ($filter === null
				|| str_contains($name, $filter)
				|| str_contains($info['type'] ?? '', $filter)
			) {
				$services[] = $info;
			}
		}

		return [
			'services' => $services,
			'count' => count($services),
		];
	}


	/**
	 * Get detailed information about a specific service including factory, setup calls, and tags.
	 * @param string $name The exact service name as registered in the DI container
	 */
	#[McpTool(
		name: 'di_get_service',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getService(string $name): array
	{
		if (!$this->builder->hasDefinition($name)) {
			return ['error' => "Service '$name' not found"];
		}

		$definition = $this->builder->getDefinition($name);
		return $this->extractFullDetails($name, $definition);
	}


	private function extractBasicInfo(string $name, Definition $definition): array
	{
		return [
			'name' => $name,
			'type' => $definition->getType(),
			'autowired' => $definition->getAutowired(),
			'tags' => $definition->getTags(),
		];
	}


	private function extractFullDetails(string $name, Definition $definition): array
	{
		$info = $this->extractBasicInfo($name, $definition);

		// Add kind
		$info['kind'] = match (true) {
			$definition instanceof ServiceDefinition => 'service',
			$definition instanceof FactoryDefinition => 'factory',
			default => $definition::class,
		};

		// Add factory info for ServiceDefinition
		if ($definition instanceof ServiceDefinition) {
			$creator = $definition->getCreator();
			if ($creator) {
				$entity = $creator->getEntity();
				$info['factory'] = $this->formatEntity($entity);
			}

			// Add setup calls
			$setup = $definition->getSetup();
			if ($setup) {
				$info['setup'] = array_map(
					fn($s) => $this->formatEntity($s->getEntity()),
					$setup,
				);
			}
		}

		// Add result type for FactoryDefinition
		if ($definition instanceof FactoryDefinition) {
			$info['resultType'] = $definition->getResultType();
			$info['interface'] = $definition->getImplement();
		}

		return $info;
	}


	private function formatEntity(mixed $entity): ?string
	{
		if (is_string($entity)) {
			return $entity;
		}

		if (is_array($entity) && count($entity) === 2) {
			[$class, $method] = $entity;
			if (is_string($class)) {
				return "$class::$method";
			}
		}

		return null;
	}
}
