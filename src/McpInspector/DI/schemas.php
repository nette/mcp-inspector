<?php declare(strict_types=1);

namespace Nette\McpInspector\DI\Schemas;


/**
 * One service of the application's DI container.
 */
final readonly class Service
{
	public function __construct(
		public string $name,
		public ?string $type,
		/** true = autowiring may pick it, false = excluded, null = unknown */
		public ?bool $autowired,
		/** @var array<string, mixed> tag name => tag value */
		public array $tags,
		/** @var list<string> */
		public array $aliases,
		/** whether the instance has already been created in this process */
		public bool $created,
	) {
	}
}


/**
 * Result of di_get_services.
 */
final readonly class ServiceList
{
	public function __construct(
		/** @var list<Service> */
		public array $services,
		public int $count,
		/** the substring the services were filtered by */
		public ?string $filter,
	) {
	}
}


/**
 * Result of di_find_by_type.
 */
final readonly class ServicesByType
{
	public function __construct(
		public string $type,
		/** @var list<Service> */
		public array $services,
		public int $count,
		/** name of the service autowiring picks for this type; null when none or ambiguous */
		public ?string $autowired,
	) {
	}
}


/**
 * Result of di_find_by_tag.
 */
final readonly class ServicesByTag
{
	public function __construct(
		public string $tag,
		/** @var list<Service> */
		public array $services,
		public int $count,
	) {
	}
}


/**
 * Result of di_get_parameter_names.
 */
final readonly class ParameterList
{
	public function __construct(
		/** @var list<string> dotted paths of the leaf values */
		public array $names,
		public int $count,
		/** explanation when the list is empty */
		public ?string $note,
	) {
	}
}


/**
 * Result of di_get_parameter.
 */
final readonly class Parameter
{
	public function __construct(
		public string $name,
		/** the value, secrets replaced by *** */
		public mixed $value,
	) {
	}
}
