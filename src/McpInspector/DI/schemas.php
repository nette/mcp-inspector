<?php declare(strict_types=1);

namespace Nette\McpInspector\DI\Schemas;


/**
 * One service of the application's DI container.
 */
final class Service
{
	/**
	 * @param  ?bool  $autowired  true = autowiring may pick it, false = excluded, null = unknown
	 * @param  array<string, mixed>  $tags  tag name => tag value
	 * @param  list<string>  $aliases
	 * @param  bool  $created  whether the instance has already been created in this process
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $type,
		public readonly ?bool $autowired,
		public readonly array $tags,
		public readonly array $aliases,
		public readonly bool $created,
	) {
	}
}


/**
 * Result of di_get_services.
 */
final class ServiceList
{
	/**
	 * @param  list<Service>  $services
	 * @param  ?string  $filter  the substring the services were filtered by
	 */
	public function __construct(
		public readonly array $services,
		public readonly int $count,
		public readonly ?string $filter,
	) {
	}
}


/**
 * Result of di_find_by_type.
 */
final class ServicesByType
{
	/**
	 * @param  list<Service>  $services
	 * @param  ?string  $autowired  name of the service autowiring picks for this type; null when none or ambiguous
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $services,
		public readonly int $count,
		public readonly ?string $autowired,
	) {
	}
}


/**
 * Result of di_find_by_tag.
 */
final class ServicesByTag
{
	/**
	 * @param  list<Service>  $services
	 */
	public function __construct(
		public readonly string $tag,
		public readonly array $services,
		public readonly int $count,
	) {
	}
}


/**
 * Result of di_get_parameter_names.
 */
final class ParameterList
{
	/**
	 * @param  list<string>  $names  dotted paths of the leaf values
	 * @param  ?string  $note  explanation when the list is empty
	 */
	public function __construct(
		public readonly array $names,
		public readonly int $count,
		public readonly ?string $note,
	) {
	}
}


/**
 * Result of di_get_parameter.
 */
final class Parameter
{
	/**
	 * @param  mixed  $value  the value, secrets replaced by ***
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $value,
	) {
	}
}
