<?php declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Nette\DI\Container;
use Nette\McpInspector\ContainerAccessor;
use Nette\McpInspector\Masking;
use Nette\McpInspector\Toolkit;
use function array_key_exists, count, is_array, is_dir, is_file, is_string, str_contains, str_replace;


/**
 * Toolkit for DI container introspection.
 *
 * All operations work on the runtime Container — no recompile is needed and the
 * cached generated container is used as-is. Compile-time-only data (factory
 * expressions, setup() calls, beforeCompile hooks) is not exposed.
 */
class DIToolkit implements Toolkit
{
	public static function tryCreate(ContainerAccessor $accessor): ?self
	{
		return new self($accessor);
	}


	public function __construct(
		private ContainerAccessor $accessor,
	) {
	}


	/**
	 * List all registered DI container services with their types and tags.
	 * If you already know the interface or class, prefer `di_find_by_type` — this tool
	 * is for fuzzy/name-based exploration when the exact type is unknown.
	 * @param ?string  $filter Optional filter by service name or type (case-sensitive substring match)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_get_services',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getServices(?string $filter = null): array
	{
		$container = $this->accessor->getContainer();
		$services = [];
		foreach ($container->getServiceTypes() as $name => $type) {
			if ($filter === null
				|| str_contains($name, $filter)
				|| str_contains($type, $filter)
			) {
				$services[] = $this->basicInfo($container, $name, $type);
			}
		}

		return $this->accessor->decorateResult([
			'services' => $services,
			'count' => count($services),
		]);
	}


	/**
	 * Get information about a specific service: type, tags, instantiation status.
	 * Compile-time data (factory expression, setup calls) is not exposed — read source if needed.
	 * @param string  $name The exact service name as registered in the DI container
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_get_service',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getService(
		#[Schema(minLength: 1)]
		string $name,
	): array
	{
		$container = $this->accessor->getContainer();
		if (!$container->hasService($name)) {
			return $this->accessor->decorateResult(['error' => "Service '$name' not found"]);
		}

		$created = array_key_exists($name, $container->getInstantiatedServices());
		return $this->accessor->decorateResult(
			$this->basicInfo($container, $name, $container->getServiceType($name)) + [
				'created' => $created,
			],
		);
	}


	/**
	 * List names of all DI container parameters. Companion to `di_get_parameter`:
	 * call this first to discover keys, then read individual values.
	 * Nested array parameters are flattened to dotted notation (e.g. database.default.dsn).
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_get_parameter_names',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getParameterNames(): array
	{
		$names = $this->collectParameterNames($this->accessor->getContainer()->getParameters());
		return $this->accessor->decorateResult([
			'names' => $names,
			'count' => count($names),
		]);
	}


	/**
	 * Get the value of a single DI container parameter by name.
	 * For nested values use dotted notation (e.g. database.default.dsn).
	 * Sensitive values (password, secret, token, apikey, api_key, credential, auth) are masked.
	 * Filesystem path values are normalized to forward slashes for readability.
	 * @param string  $name The exact parameter name (or dotted path for nested values)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_get_parameter',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getParameter(
		#[Schema(minLength: 1)]
		string $name,
	): array
	{
		$result = $this->resolveParameter($this->accessor->getContainer()->getParameters(), $name);
		if (!$result) {
			return $this->accessor->decorateResult(['error' => "Parameter '$name' not found"]);
		}

		$lastSegment = strrchr($name, '.');
		$leafKey = $lastSegment === false ? $name : substr($lastSegment, 1);

		return $this->accessor->decorateResult(['value' => $this->presentValue($leafKey, $result[0])]);
	}


	/**
	 * List all service aliases as a map of alias name to canonical service name.
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_get_aliases',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getAliases(): array
	{
		$aliases = $this->accessor->getContainer()->getAliases();
		return $this->accessor->decorateResult([
			'aliases' => $aliases,
			'count' => count($aliases),
		]);
	}


	/**
	 * Find services by tag name.
	 * @param string  $tag The tag name to search for
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_find_by_tag',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function findByTag(
		#[Schema(minLength: 1)]
		string $tag,
	): array
	{
		$container = $this->accessor->getContainer();
		$services = [];
		foreach ($container->findByTag($tag) as $name => $tagValue) {
			$services[] = $this->basicInfo($container, $name, $container->getServiceType($name)) + [
				'tagValue' => $tagValue,
			];
		}

		return $this->accessor->decorateResult([
			'tag' => $tag,
			'services' => $services,
			'count' => count($services),
		]);
	}


	/**
	 * Find services by type (class or interface).
	 * @param string  $type The fully qualified class or interface name
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'di_find_by_type',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function findByType(
		#[Schema(minLength: 1)]
		string $type,
	): array
	{
		// Guard satisfies the class-string requirement of findAutowired/findByType
		// without lying via @var. Unknown types yield an empty result, mirroring
		// the natural fail-quiet behaviour of Container::findByType().
		$services = [];
		if (class_exists($type) || interface_exists($type)) {
			$container = $this->accessor->getContainer();
			$autowired = array_flip($container->findAutowired($type));
			foreach ($container->findByType($type) as $name) {
				$services[] = $this->basicInfo($container, $name, $container->getServiceType($name)) + [
					'autowiredFor' => isset($autowired[$name]),
				];
			}
		}

		return $this->accessor->decorateResult([
			'type' => $type,
			'services' => $services,
			'count' => count($services),
		]);
	}


	/**
	 * @return array<string, mixed>
	 */
	private function basicInfo(Container $container, string $name, string $type): array
	{
		return [
			'name' => $name,
			'type' => $type,
			'tags' => $container->getServiceTags($name),
		];
	}


	/**
	 * Recursively flattens parameters to dotted-notation key list (e.g. database.default.dsn).
	 * @param  mixed[]  $parameters
	 * @return list<string>
	 */
	private function collectParameterNames(array $parameters, string $prefix = ''): array
	{
		$names = [];
		foreach ($parameters as $key => $value) {
			$fullKey = $prefix === '' ? (string) $key : "$prefix.$key";
			if (is_array($value) && $value !== []) {
				$names = array_merge($names, $this->collectParameterNames($value, $fullKey));
			} else {
				$names[] = $fullKey;
			}
		}
		return $names;
	}


	/**
	 * Looks up a value in nested array via dotted-notation path. Returns a single-element
	 * array wrapping the value on hit, empty array on miss — distinguishes a stored null
	 * from a missing key without by-ref out-params.
	 * @param  mixed[]  $parameters
	 * @return array{}|array{mixed}
	 */
	private function resolveParameter(array $parameters, string $name): array
	{
		$cursor = $parameters;
		foreach (explode('.', $name) as $segment) {
			if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
				return [];
			}
			$cursor = $cursor[$segment];
		}
		return [$cursor];
	}


	/**
	 * Returns value ready for display: masks if the leaf key is sensitive,
	 * normalizes filesystem paths to forward slashes.
	 */
	private function presentValue(string $key, mixed $value): mixed
	{
		if (Masking::shouldMask($key) && is_string($value)) {
			return '***MASKED***';
		}
		if (is_array($value)) {
			$result = [];
			foreach ($value as $k => $v) {
				$result[$k] = $this->presentValue((string) $k, $v);
			}
			return $result;
		}
		if ($value instanceof \UnitEnum) {
			$repr = ['enum' => $value::class . '::' . $value->name];
			if ($value instanceof \BackedEnum) {
				$repr['value'] = $value->value;
			}
			return $repr;
		}
		if (is_string($value) && str_contains($value, '\\') && (is_dir($value) || is_file($value))) {
			return str_replace('\\', '/', $value);
		}
		return $value;
	}
}
