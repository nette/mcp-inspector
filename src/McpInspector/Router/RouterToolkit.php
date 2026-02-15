<?php declare(strict_types=1);

namespace Nette\McpInspector\Router;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Nette\Application\Routers;
use Nette\Http\Request;
use Nette\Http\Url;
use Nette\Http\UrlScript;
use Nette\McpInspector\Router\Schemas\Link;
use Nette\McpInspector\Router\Schemas\Route;
use Nette\McpInspector\Router\Schemas\RouteList;
use Nette\McpInspector\Router\Schemas\UrlMatch;
use Nette\McpInspector\Toolkit;
use Nette\Routing\Router;
use function count;


/**
 * Routes, URL matching and link generation.
 */
class RouterToolkit implements Toolkit
{
	public function __construct(
		private readonly RouterAccessor $router,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->router->isAvailable();
	}


	/**
	 * List all registered routes with their masks, defaults, and module prefixes.
	 */
	#[McpTool(
		name: 'router_get_routes',
		title: 'Routes',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getRoutes(): RouteList
	{
		$routes = $this->extractRoutes($this->router->get());
		return new RouteList($routes, count($routes));
	}


	/**
	 * Match a URL to presenter/action and extract its parameters. A relative URL is resolved against
	 * the configured base URL (or http://localhost/), so host-restricted routes need an absolute URL.
	 * @param string  $url URL to match (e.g. "/article/123" or "https://example.com/article/123")
	 */
	#[McpTool(
		name: 'router_match_url',
		title: 'Match URL',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function matchUrl(
		#[Schema(minLength: 1)]
		string $url,
	): UrlMatch
	{
		$requestUrl = $this->router->getRequestUrl();
		$base = $requestUrl->getHost() === '' ? new UrlScript('http://localhost/') : $requestUrl; // the CLI request has no host
		if (!preg_match('~^https?://~', $url)) {
			$url = rtrim($base->getBaseUrl(), '/') . '/' . ltrim($url, '/');
		}

		$scriptPath = str_starts_with($url, $base->getBaseUrl()) ? $base->getScriptPath() : '/'; // the application may live in a subdirectory
		$params = $this->router->get()->match(new Request(new UrlScript($url, $scriptPath)));
		if ($params === null) { // a valid answer to "which route handles this URL?", not a failure
			return new UrlMatch(matched: false, url: $url, presenter: null, action: null, params: []);
		}

		$presenter = $params['presenter'] ?? null;
		$action = $params['action'] ?? 'default';
		unset($params['presenter'], $params['action']);
		return new UrlMatch(matched: true, url: $url, presenter: $presenter, action: $action, params: $params);
	}


	/**
	 * Generate the URL for a presenter/action, the way {link} in templates does.
	 * @param string  $destination Presenter:action notation (e.g. "Article:show" or ":Admin:Article:edit")
	 * @param ?array<string, mixed>  $params Parameters of the link (e.g. {"id": 123})
	 */
	#[McpTool(
		name: 'router_generate_url',
		title: 'Generate URL',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function generateUrl(
		#[Schema(minLength: 1)]
		string $destination,
		#[Schema(definition: ['type' => ['object', 'null'], 'additionalProperties' => true])] // the SDK does not read array<string, mixed> docblocks
		?array $params = null,
	): Link
	{
		$url = $this->router->getLinkGenerator()->link($destination, $params ?? []);
		if ((new Url($url))->getHost() === '') {
			throw new ToolCallException("The application runs without an HTTP request, so it cannot tell its own address; set `http: baseUrl: https://…` in the application's configuration (got '$url').");
		}

		return new Link($destination, $params ?? [], $url);
	}


	/**
	 * @return list<Route>
	 */
	private function extractRoutes(Router $router, string $prefix = ''): array
	{
		if (!$router instanceof Routers\RouteList) {
			$defaults = method_exists($router, 'getDefaults') ? $router->getDefaults() : [];
			return [new Route(
				type: $router::class,
				prefix: $prefix ?: null,
				mask: method_exists($router, 'getMask') ? $router->getMask() : null,
				presenter: isset($defaults['presenter']) ? $prefix . $defaults['presenter'] : null,
				action: $defaults['action'] ?? null,
			)];
		}

		$module = $router->getModule();
		$routes = [];
		foreach ($router->getRouters() as $route) {
			$routes = [...$routes, ...$this->extractRoutes($route, $module ? $prefix . $module : $prefix)];
		}
		return $routes;
	}
}
