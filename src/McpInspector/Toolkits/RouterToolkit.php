<?php declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Nette\Application\LinkGenerator;
use Nette\Application\Routers\RouteList;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\McpInspector\ContainerAccessor;
use Nette\McpInspector\Toolkit;
use Nette\Routing\Router;
use function count;


/**
 * Toolkit for router introspection.
 */
class RouterToolkit implements Toolkit
{
	public static function tryCreate(ContainerAccessor $accessor): ?self
	{
		try {
			$accessor->getContainer()->getByType(Router::class);
		} catch (\Throwable) {
			return null;
		}
		return new self($accessor);
	}


	public function __construct(
		private ContainerAccessor $accessor,
	) {
	}


	/**
	 * List all registered routes with their masks, defaults, and module prefixes.
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'router_get_routes',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getRoutes(): array
	{
		$routes = $this->extractRoutes($this->router());

		return $this->accessor->decorateResult([
			'routes' => $routes,
			'count' => count($routes),
		]);
	}


	/**
	 * Match URL to presenter/action and extract parameters.
	 * Pass an absolute URL when host-restricted routes matter — relative paths are
	 * matched against the optional `host` parameter (or http://localhost as a fallback,
	 * which won't trigger host-locked or HTTPS-only routes the way the real host would).
	 * @param string  $url URL to match (e.g., "/article/123" or "https://example.com/article/123")
	 * @param ?string  $host Optional base URL (scheme + host, e.g. "https://example.com") used to resolve a relative `$url`
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'router_match_url',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function matchUrl(
		#[Schema(minLength: 1)]
		string $url,
		?string $host = null,
	): array
	{
		if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
			$base = $host !== null ? rtrim($host, '/') : 'http://localhost';
			$url = $base . (str_starts_with($url, '/') ? '' : '/') . $url;
		}

		try {
			$urlScript = new UrlScript($url);
			$httpRequest = new Request($urlScript);
			$params = $this->router()->match($httpRequest);

			if ($params === null) {
				return $this->accessor->decorateResult([
					'matched' => false,
					'url' => $url,
					'error' => 'No route matches this URL',
				]);
			}

			$presenter = $params['presenter'] ?? null;
			$action = $params['action'] ?? 'default';
			unset($params['presenter'], $params['action']);

			return $this->accessor->decorateResult([
				'matched' => true,
				'url' => $url,
				'presenter' => $presenter,
				'action' => $action,
				'params' => $params,
			]);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult([
				'matched' => false,
				'url' => $url,
				'error' => $e->getMessage(),
			]);
		}
	}


	/**
	 * Generate URL for presenter/action using LinkGenerator.
	 * @param string  $destination Presenter:action notation (e.g., "Article:show" or ":Front:Article:show")
	 * @param ?mixed[]  $params Optional parameters for the URL (e.g., ["id" => 123])
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'router_generate_url',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function generateUrl(
		#[Schema(minLength: 1)]
		string $destination,
		?array $params = null,
	): array
	{
		try {
			$linkGenerator = $this->accessor->getContainer()->getByType(LinkGenerator::class);
		} catch (\Throwable) {
			return $this->accessor->decorateResult(['error' => 'LinkGenerator not configured']);
		}

		try {
			$url = $linkGenerator->link($destination, $params ?? []);

			return $this->accessor->decorateResult([
				'destination' => $destination,
				'params' => $params ?? [],
				'url' => $url,
			]);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult([
				'destination' => $destination,
				'params' => $params ?? [],
				'error' => $e->getMessage(),
			]);
		}
	}


	private function router(): Router
	{
		return $this->accessor->getContainer()->getByType(Router::class);
	}


	/**
	 * @return list<array<string, mixed>>
	 */
	private function extractRoutes(Router $router, string $prefix = ''): array
	{
		$routes = [];

		if ($router instanceof RouteList) {
			$module = $router->getModule();
			$newPrefix = $module ? $prefix . $module . ':' : $prefix;

			foreach ($router->getRouters() as $route) {
				$routes = array_merge($routes, $this->extractRoutes($route, $newPrefix));
			}
		} else {
			$info = [
				'type' => $router::class,
				'prefix' => $prefix ?: null,
			];

			if (method_exists($router, 'getMask')) {
				$info['mask'] = $router->getMask();
			}

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
