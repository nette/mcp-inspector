<?php declare(strict_types=1);

/**
 * Test: RouterToolkit over a real application router.
 */

use Mcp\Exception\ToolCallException;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Router\RouterToolkit;
use Nette\McpInspector\Server;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


// LinkGenerator loads the presenter class to validate the link
class ArticlePresenter extends Nette\Application\UI\Presenter
{
}

class_alias(ArticlePresenter::class, 'App\Presentation\Article\ArticlePresenter');


$project = createTestProject(<<<'NEON'
	routing:
		routes:
			'article/<id \d+>': Article:show
			'<presenter>/<action>': Homepage:default
	NEON);

$container = Inspector::createContainer($project);
$toolkit = $container->getByType(RouterToolkit::class);
Assert::true($toolkit->isAvailable());
Assert::contains('router_match_url', $container->getByType(Server::class)->getToolNames());
assertToolsComplete($container->getByType(Server::class));


test('routes are listed with masks and defaults', function () use ($toolkit) {
	$result = $toolkit->getRoutes();
	Assert::same(2, $result->count);
	Assert::same('article/<id \d+>', $result->routes[0]->mask);
	Assert::same('Article', $result->routes[0]->presenter);
	Assert::same('show', $result->routes[0]->action);
});


test('URLs are matched, relative ones against http://localhost', function () use ($toolkit) {
	$result = $toolkit->matchUrl('/article/123');
	Assert::same('http://localhost/article/123', $result->url);
	Assert::true($result->matched);
	Assert::same('Article', $result->presenter);
	Assert::same('show', $result->action);
	Assert::same(['id' => '123'], $result->params);

	Assert::false($toolkit->matchUrl('https://example.com/a/b/c')->matched);
});


test('without an HTTP request the application cannot generate absolute links; the error says what to set', function () use ($toolkit, $container) {
	Assert::exception(
		fn() => $toolkit->generateUrl('Article:show', ['id' => 5]),
		ToolCallException::class,
		'%a%set `http: baseUrl: https://…`%a%',
	);

	// through the server, params arrive as a JSON object and pass the schema
	Assert::exception(
		fn() => callTool($container->getByType(Server::class), 'router_generate_url', ['destination' => 'Article:show', 'params' => ['id' => 5]]),
		ToolCallException::class,
		'%a%set `http: baseUrl: https://…`%a%',
	);
});
