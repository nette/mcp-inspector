<?php declare(strict_types=1);

namespace Nette\McpInspector\Router\Schemas;


/**
 * One router of the application's RouteList.
 */
final class Route
{
	public function __construct(
		/** class of the router */
		public readonly string $type,
		/** module prefix the route lives under (Admin:), null at the top level */
		public readonly ?string $prefix,
		/** URL mask, null for routers without one */
		public readonly ?string $mask,
		/** default presenter including the module prefix */
		public readonly ?string $presenter,
		public readonly ?string $action,
	) {
	}
}


/**
 * Result of router_get_routes.
 */
final class RouteList
{
	public function __construct(
		/** @var list<Route> in matching order */
		public readonly array $routes,
		public readonly int $count,
	) {
	}
}


/**
 * Result of router_match_url.
 */
final class UrlMatch
{
	public function __construct(
		/** false when no route handles the URL */
		public readonly bool $matched,
		/** the absolute URL that was matched */
		public readonly string $url,
		public readonly ?string $presenter,
		public readonly ?string $action,
		/** @var array<string, mixed> parameters without presenter and action */
		public readonly array $params,
	) {
	}
}


/**
 * Result of router_generate_url.
 */
final class Link
{
	public function __construct(
		public readonly string $destination,
		/** @var array<string, mixed> */
		public readonly array $params,
		public readonly string $url,
	) {
	}
}
