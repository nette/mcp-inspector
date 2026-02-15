<?php declare(strict_types=1);

namespace Nette\McpInspector\Router\Schemas;


/**
 * One router of the application's RouteList.
 */
final class Route
{
	/**
	 * @param  string  $type  class of the router
	 * @param  ?string  $prefix  module prefix the route lives under (Admin:), null at the top level
	 * @param  ?string  $mask  URL mask, null for routers without one
	 * @param  ?string  $presenter  default presenter including the module prefix
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $prefix,
		public readonly ?string $mask,
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
	/**
	 * @param  list<Route>  $routes  in matching order
	 */
	public function __construct(
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
	/**
	 * @param  bool  $matched  false when no route handles the URL
	 * @param  string  $url  the absolute URL that was matched
	 * @param  array<string, mixed>  $params  parameters without presenter and action
	 */
	public function __construct(
		public readonly bool $matched,
		public readonly string $url,
		public readonly ?string $presenter,
		public readonly ?string $action,
		public readonly array $params,
	) {
	}
}


/**
 * Result of router_generate_url.
 */
final class Link
{
	/**
	 * @param  array<string, mixed>  $params
	 */
	public function __construct(
		public readonly string $destination,
		public readonly array $params,
		public readonly string $url,
	) {
	}
}
