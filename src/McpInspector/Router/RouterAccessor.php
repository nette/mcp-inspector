<?php declare(strict_types=1);

namespace Nette\McpInspector\Router;

use Nette\Application\LinkGenerator;
use Nette\Http\IRequest;
use Nette\Http\UrlScript;
use Nette\McpInspector\AppContainer;
use Nette\Routing\Router;


/**
 * The inspected application's router, link generator and request URL, resolved on every call so reloads are honoured.
 */
final class RouterAccessor
{
	public function __construct(
		private readonly AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->app->get()->getByType(Router::class, throw: false) !== null;
	}


	public function get(): Router
	{
		return $this->app->get()->getByType(Router::class);
	}


	/**
	 * The application's LinkGenerator; in the CLI it generates absolute links only when the application sets `http: baseUrl:`.
	 */
	public function getLinkGenerator(): LinkGenerator
	{
		return $this->app->get()->getByType(LinkGenerator::class);
	}


	/**
	 * URL of the application's request; in the CLI it has a host only when the application sets `http: baseUrl:`.
	 */
	public function getRequestUrl(): UrlScript
	{
		return $this->app->get()->getByType(IRequest::class)->getUrl();
	}
}
