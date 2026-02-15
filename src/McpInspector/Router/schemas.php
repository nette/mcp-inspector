<?php declare(strict_types=1);

namespace Nette\McpInspector\Router\Schemas;


/**
 * One router of the application's RouteList.
 */
final readonly class Route
{
	public function __construct(
		/** class of the router */
		public string $type,
		/** module prefix the route lives under (Admin:), null at the top level */
		public ?string $prefix,
		/** URL mask, null for routers without one */
		public ?string $mask,
		/** default presenter including the module prefix */
		public ?string $presenter,
		public ?string $action,
	) {
	}
}


/**
 * Result of router_get_routes.
 */
final readonly class RouteList
{
	public function __construct(
		/** @var list<Route> in matching order */
		public array $routes,
		public int $count,
	) {
	}
}


/**
 * Result of router_match_url.
 */
final readonly class UrlMatch
{
	public function __construct(
		/** false when no route handles the URL */
		public bool $matched,
		/** the absolute URL that was matched */
		public string $url,
		public ?string $presenter,
		public ?string $action,
		/** @var array<string, mixed> parameters without presenter and action */
		public array $params,
	) {
	}
}


/**
 * Result of router_generate_url.
 */
final readonly class Link
{
	public function __construct(
		public string $destination,
		/** @var array<string, mixed> */
		public array $params,
		public string $url,
	) {
	}
}
