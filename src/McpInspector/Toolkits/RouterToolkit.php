<?php

declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Nette\Application\LinkGenerator;
use Nette\Application\Routers\RouteList;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkit;
use Nette\Routing\Router;


/**
 * Toolkit for router introspection.
 */
class RouterToolkit implements Toolkit
{
	public static function tryCreate(BootstrapBridge $bridge): ?self
	{
		$container = $bridge->getContainer();

		try {
			$router = $container->getByType(Router::class);
		} catch (\Throwable) {
			return null;
		}

		try {
			$linkGenerator = $container->getByType(LinkGenerator::class);
		} catch (\Throwable) {
			$linkGenerator = null;
		}

		return new self($router, $linkGenerator);
	}


	public function __construct(
		private Router $router,
		private ?object $linkGenerator = null,
	) {
	}


	/**
	 * List all registered routes with their masks, defaults, and module prefixes.
	 */
	#[McpTool(
		name: 'router_get_routes',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getRoutes(): array
	{
		$routes = $this->extractRoutes($this->router);

		return [
			'routes' => $routes,
			'count' => count($routes),
		];
	}


	/**
	 * Match URL to presenter/action and extract parameters.
	 * @param string $url URL to match (e.g., "/article/123" or "https://example.com/article/123")
	 */
	#[McpTool(
		name: 'router_match_url',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function matchUrl(string $url): array
	{
		// Normalize URL
		if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
			$url = 'http://localhost' . (str_starts_with($url, '/') ? '' : '/') . $url;
		}

		try {
			$urlScript = new UrlScript($url);
			$httpRequest = new Request($urlScript);
			$params = $this->router->match($httpRequest);

			if ($params === null) {
				return [
					'matched' => false,
					'url' => $url,
					'error' => 'No route matches this URL',
				];
			}

			$presenter = $params['presenter'] ?? null;
			$action = $params['action'] ?? 'default';
			unset($params['presenter'], $params['action']);

			return [
				'matched' => true,
				'url' => $url,
				'presenter' => $presenter,
				'action' => $action,
				'params' => $params,
			];
		} catch (\Throwable $e) {
			return [
				'matched' => false,
				'url' => $url,
				'error' => $e->getMessage(),
			];
		}
	}


	/**
	 * Generate URL for presenter/action using LinkGenerator.
	 * @param string $destination Presenter:action notation (e.g., "Article:show" or ":Front:Article:show")
	 * @param array|null $params Optional parameters for the URL (e.g., ["id" => 123])
	 */
	#[McpTool(
		name: 'router_generate_url',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function generateUrl(string $destination, ?array $params = null): array
	{
		if ($this->linkGenerator === null) {
			return ['error' => 'LinkGenerator not configured'];
		}

		try {
			$url = $this->linkGenerator->link($destination, $params ?? []);

			return [
				'destination' => $destination,
				'params' => $params ?? [],
				'url' => $url,
			];
		} catch (\Throwable $e) {
			return [
				'destination' => $destination,
				'params' => $params ?? [],
				'error' => $e->getMessage(),
			];
		}
	}


	private function extractRoutes(Router $router, string $prefix = ''): array
	{
		$routes = [];

		if ($router instanceof RouteList) {
			$module = $router->getModule();
			$newPrefix = $module ? $prefix . $module . ':' : $prefix;

			foreach ($router as $route) {
				$routes = array_merge($routes, $this->extractRoutes($route, $newPrefix));
			}
		} else {
			// Single route - try to extract info
			$info = [
				'type' => $router::class,
				'prefix' => $prefix ?: null,
			];

			// Try to get mask from Route
			if (method_exists($router, 'getMask')) {
				$info['mask'] = $router->getMask();
			}

			// Try to get defaults
			if (method_exists($router, 'getDefaults')) {
				$defaults = $router->getDefaults();
				if (isset($defaults['presenter'])) {
					$info['presenter'] = $prefix . $defaults['presenter'];
				}
				if (isset($defaults['action'])) {
					$info['action'] = $defaults['action'];
				}
			}

			$routes[] = $info;
		}

		return $routes;
	}
}
