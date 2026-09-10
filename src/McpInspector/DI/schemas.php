<?php declare(strict_types=1);

namespace Nette\McpInspector\DI\Schemas;


/**
 * One service of the application's DI container.
 */
final class Service
{
	public function __construct(
		public readonly string $name,
		public readonly ?string $type,
		/** true = autowiring may pick it, false = excluded, null = unknown */
		public readonly ?bool $autowired,
		/** @var array<string, mixed> tag name => tag value */
		public readonly array $tags,
		/** @var list<string> */
		public readonly array $aliases,
		/** whether the instance has already been created in this process */
		public readonly bool $created,
	) {
	}
}


/**
 * Result of di_get_services.
 */
final class ServiceList
{
	public function __construct(
		/** @var list<Service> */
		public readonly array $services,
		public readonly int $count,
		/** the substring the services were filtered by */
		public readonly ?string $filter,
	) {
	}
}


/**
 * Result of di_find_by_type.
 */
final class ServicesByType
{
	public function __construct(
		public readonly string $type,
		/** @var list<Service> */
		public readonly array $services,
		public readonly int $count,
		/** name of the service autowiring picks for this type; null when none or ambiguous */
		public readonly ?string $autowired,
	) {
	}
}


/**
 * Result of di_find_by_tag.
 */
final class ServicesByTag
{
	public function __construct(
		public readonly string $tag,
		/** @var list<Service> */
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
	public function __construct(
		/** @var list<string> dotted paths of the leaf values */
		public readonly array $names,
		public readonly int $count,
		/** explanation when the list is empty */
		public readonly ?string $note,
	) {
	}
}


/**
 * Result of di_get_parameter.
 */
final class Parameter
{
	public function __construct(
		public readonly string $name,
		/** the value, secrets replaced by *** */
		public readonly mixed $value,
	) {
	}
}
