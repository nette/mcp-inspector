<?php declare(strict_types=1);

/**
 * Test: PresenterToolkit over the application's PresenterFactory and presenter classes found on disk.
 */

use Composer\Autoload\ClassLoader;
use Mcp\Exception\ToolCallException;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Presenters\PresenterToolkit;
use Nette\McpInspector\Server;
use Nette\Utils\FileSystem;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


$project = createTestProject(<<<'NEON'
	application:
		mapping: Demo\Presentation\*\**Presenter
	NEON);

// a PSR-4 namespace with presenters, the way Composer autoloads them
FileSystem::write("$project/app/Presentation/Home/HomePresenter.php", <<<'PHP'
	<?php
	namespace Demo\Presentation\Home;

	class HomePresenter extends \Nette\Application\UI\Presenter
	{
		#[\Nette\Application\Attributes\Persistent]
		public string $lang = 'en';

		public function actionDefault(): void {}
		public function renderDefault(): void {}
		public function actionDetail(int $id): void {}
		public function handleRefresh(): void {}
		protected function createComponentSearchForm(): \Nette\Application\UI\Form { return new \Nette\Application\UI\Form; }
	}
	PHP);
FileSystem::write("$project/app/Presentation/Home/default.latte", '');
FileSystem::write("$project/app/Presentation/Admin/Article/ArticlePresenter.php", <<<'PHP'
	<?php
	namespace Demo\Presentation\Admin\Article;

	class ArticlePresenter extends \Nette\Application\UI\Presenter
	{
		public function actionEdit(int $id): void {}
	}
	PHP);
FileSystem::write("$project/app/Presentation/BasePresenter.php", <<<'PHP'
	<?php
	namespace Demo\Presentation;

	abstract class BasePresenter extends \Nette\Application\UI\Presenter
	{
	}
	PHP);
$loader = new ClassLoader;
$loader->addPsr4('Demo\\', "$project/app");
$loader->register();

$container = Inspector::createContainer($project);
$toolkit = $container->getByType(PresenterToolkit::class);
Assert::true($toolkit->isAvailable());
assertToolsComplete($container->getByType(Server::class));
Assert::same(2, callTool($container->getByType(Server::class), 'presenter_get_presenters')['count']);
Assert::same('Home', callTool($container->getByType(Server::class), 'presenter_get_presenter', ['name' => 'Home'])['name']);


test('presenters are found on disk and named through the mapping; abstract ones are skipped', function () use ($toolkit) {
	$result = $toolkit->getPresenters();
	Assert::same(['*' => 'Demo\Presentation\*\**Presenter', 'Nette' => 'NetteModule\*\*Presenter'], $result->mapping);
	Assert::same(2, $result->count);
	Assert::same('Admin:Article', $result->presenters[0]->name);
	Assert::same('Demo\Presentation\Admin\Article\ArticlePresenter', $result->presenters[0]->class);
	Assert::same(['edit'], $result->presenters[0]->actions);
	Assert::same('Home', $result->presenters[1]->name);
	Assert::same(['default', 'detail'], $result->presenters[1]->actions);
	Assert::match('%a%/app/Presentation/Home/HomePresenter.php', $result->presenters[1]->file);
});


test('details of one presenter', function () use ($toolkit) {
	$detail = $toolkit->getPresenter('Home');
	Assert::same('Demo\Presentation\Home\HomePresenter', $detail->class);
	Assert::same(['default', 'detail'], $detail->actions);
	Assert::same(['refresh'], $detail->signals);
	Assert::same(['searchForm'], $detail->components);
	Assert::same(['lang'], $detail->persistentParams);
	Assert::match('%a%/app/Presentation/Home/default.latte', $detail->templates[0]);

	Assert::same('Demo\Presentation\Admin\Article\ArticlePresenter', $toolkit->getPresenter('Admin:Article')->class);
	Assert::exception(fn() => $toolkit->getPresenter('Missing'), ToolCallException::class, "Cannot load presenter 'Missing'%a%");
});
