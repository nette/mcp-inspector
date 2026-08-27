<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters;

use Nette\Application\IPresenterFactory;
use Nette\Application\PresenterFactory;
use Nette\McpInspector\AppContainer;


/**
 * The inspected application's PresenterFactory and its mapping, resolved on every call so reloads are honoured.
 */
final class PresenterAccessor
{
	public function __construct(
		private readonly AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return interface_exists(IPresenterFactory::class)
			&& $this->app->get()->getByType(IPresenterFactory::class, throw: false) instanceof PresenterFactory;
	}


	public function get(): PresenterFactory
	{
		$factory = $this->app->get()->getByType(IPresenterFactory::class);
		assert($factory instanceof PresenterFactory);
		return $factory;
	}


	/**
	 * The mapping as `application: mapping:` spells it: module => class mask.
	 * @return array<string, string>
	 */
	public function getMapping(): array
	{
		$mapping = (new \ReflectionProperty(PresenterFactory::class, 'mapping'))->getValue($this->get()); // no public getter until nette/application 3.3
		$result = [];
		foreach ($mapping as $module => [$prefix, $moduleMask, $presenterMask]) {
			$result[$module] = $prefix . $moduleMask . $presenterMask;
		}

		return $result;
	}
}
