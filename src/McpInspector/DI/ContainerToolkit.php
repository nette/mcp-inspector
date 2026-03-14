<?php declare(strict_types=1);

namespace Nette\McpInspector\DI;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Nette\DI\ServiceDescriptor;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Masking;
use Nette\McpInspector\Toolkit;
use function array_key_exists, count, in_array, is_array, is_scalar;
use Nette\McpInspector\DI\Schemas\Parameter;
use Nette\McpInspector\DI\Schemas\ParameterList;
use Nette\McpInspector\DI\Schemas\Service;
use Nette\McpInspector\DI\Schemas\ServiceList;
use Nette\McpInspector\DI\Schemas\ServicesByTag;
use Nette\McpInspector\DI\Schemas\ServicesByType;


/**
 * DI container introspection over the runtime Container: services, their types, tags, aliases and
 * autowiring, plus parameters. Compile-time data (factory expressions, setup calls) is not available here.
 */
class ContainerToolkit implements Toolkit
{
	private const NoParametersNote = 'No parameters are exported; the application may have `di: export: parameters: no` in its configuration.';


	public function __construct(
		private readonly AppContainer $app,
		private readonly Masking $masking,
	) {
	}


	public function isAvailable(): bool
	{
		return true;
	}


	/**
	 * List services of the DI container with their types, tags and aliases. Prefer `di_find_by_type`
	 * when you know the interface or class; this tool is for exploration by name.
	 * @param ?string  $filter Optional case-insensitive substring of the service name or type
	 */
	#[McpTool(
		name: 'di_get_services',
		title: 'DI services',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getServices(?string $filter = null): ServiceList
	{
		$container = $this->app->get();
		$services = [];
		foreach ($container->getServiceDescriptors() as $descriptor) {
			if ($filter === null || stripos($descriptor->name . ' ' . $descriptor->type, $filter) !== false) {
				$services[] = self::describe($descriptor);
			}
		}

		return new ServiceList($services, count($services), $filter);
	}


	/**
	 * Get details of one service: type, tags, aliases, whether it is autowired and already created.
	 * @param string  $name The service name (or alias) as registered in the DI container
	 */
	#[McpTool(
		name: 'di_get_service',
		title: 'DI service',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getService(
		#[Schema(minLength: 1)]
		string $name,
	): Service
	{
		$container = $this->app->get();
		foreach ($container->getServiceDescriptors() as $descriptor) {
			if ($descriptor->name === $name || in_array($name, $descriptor->aliases, strict: true)) {
				return self::describe($descriptor);
			}
		}

		throw new ToolCallException("Service '$name' not found");
	}


	/**
	 * Find services implementing a class or interface, and which of them autowiring picks.
	 * @param string  $type The fully qualified class or interface name
	 */
	#[McpTool(
		name: 'di_find_by_type',
		title: 'DI services by type',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function findByType(
		#[Schema(minLength: 1)]
		string $type,
	): ServicesByType
	{
		$services = [];
		$autowired = null;
		if (class_exists($type) || interface_exists($type)) {
			$container = $this->app->get();
			$descriptors = $container->getServiceDescriptors();
			foreach ($container->findByType($type) as $name) {
				$services[] = self::describe($descriptors[$name]);
			}
			$candidates = $container->findAutowired($type, preferredOnly: true) ?: $container->findAutowired($type);
			$autowired = count($candidates) === 1 ? $candidates[0] : null; // more than one means an ambiguity error at autowiring time
		}

		return new ServicesByType($type, $services, count($services), $autowired);
	}


	/**
	 * Find services carrying a tag; the tag values are in `tags` of each service.
	 * @param string  $tag The tag name
	 */
	#[McpTool(
		name: 'di_find_by_tag',
		title: 'DI services by tag',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function findByTag(
		#[Schema(minLength: 1)]
		string $tag,
	): ServicesByTag
	{
		$container = $this->app->get();
		$services = [];
		foreach ($container->getServiceDescriptors() as $descriptor) {
			if (array_key_exists($tag, $descriptor->tags)) {
				$services[] = self::describe($descriptor);
			}
		}

		return new ServicesByTag($tag, $services, count($services));
	}


	/**
	 * List names of the container parameters; nested arrays are flattened to dotted notation
	 * (e.g. database.default.dsn). Read a value with `di_get_parameter`.
	 */
	#[McpTool(
		name: 'di_get_parameter_names',
		title: 'DI parameter names',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getParameterNames(): ParameterList
	{
		$names = self::collectNames($this->app->get()->getParameters());
		return new ParameterList($names, count($names), $names === [] ? self::NoParametersNote : null);
	}


	/**
	 * Get the value of one container parameter, dotted notation for nested values (e.g. database.default.dsn).
	 * Values under keys that suggest a secret (password, token, dsn, …) are masked.
	 * @param string  $name The parameter name or dotted path
	 */
	#[McpTool(
		name: 'di_get_parameter',
		title: 'DI parameter',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getParameter(
		#[Schema(minLength: 1)]
		string $name,
	): Parameter
	{
		$value = $this->app->get()->getParameters();
		if ($value === []) {
			throw new ToolCallException(self::NoParametersNote);
		}
		foreach (explode('.', $name) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				throw new ToolCallException("Parameter '$name' not found");
			}
			$value = $value[$segment];
		}

		$leaf = strrchr($name, '.');
		return new Parameter($name, $this->present($leaf === false ? $name : substr($leaf, 1), $value));
	}


	private static function describe(ServiceDescriptor $descriptor): Service
	{
		return new Service(
			name: $descriptor->name,
			type: $descriptor->type,
			autowired: $descriptor->autowired,
			tags: $descriptor->tags,
			aliases: $descriptor->aliases,
			created: $descriptor->instance !== null,
		);
	}


	/**
	 * @param  array<mixed>  $parameters
	 * @return list<string>
	 */
	private static function collectNames(array $parameters, string $prefix = ''): array
	{
		$names = [];
		foreach ($parameters as $key => $value) {
			$path = $prefix === '' ? (string) $key : "$prefix.$key";
			if (is_array($value) && $value !== []) {
				$names = [...$names, ...self::collectNames($value, $path)];
			} else {
				$names[] = $path;
			}
		}

		return $names;
	}


	private function present(string $key, mixed $value): mixed
	{
		if (is_array($value)) {
			$result = [];
			foreach ($value as $k => $v) {
				$result[$k] = $this->present((string) $k, $v);
			}
			return $result;
		} elseif (is_scalar($value) && $this->masking->shouldMask($key)) {
			return Masking::Placeholder;
		} elseif ($value instanceof \BackedEnum) {
			return $value->value;
		} elseif ($value instanceof \UnitEnum) {
			return $value::class . '::' . $value->name;
		}

		return $value;
	}
}
