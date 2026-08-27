<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters\Schemas;


/**
 * One presenter of the application.
 */
final class Presenter
{
	/**
	 * @param  string  $name  presenter name with module (Admin:Article)
	 * @param  list<string>  $actions  actions from action* and render* methods
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $class,
		public readonly string $file,
		public readonly array $actions,
	) {
	}
}


/**
 * Result of presenter_get_presenters.
 */
final class PresenterList
{
	/**
	 * @param  array<string, string>  $mapping  module => class mask, as in `application: mapping:`
	 * @param  list<Presenter>  $presenters
	 */
	public function __construct(
		public readonly array $mapping,
		public readonly array $presenters,
		public readonly int $count,
	) {
	}
}


/**
 * Result of presenter_get_presenter.
 */
final class PresenterDetail
{
	/**
	 * @param  list<string>  $actions  actions from action* and render* methods
	 * @param  list<string>  $signals  signals from handle* methods
	 * @param  list<string>  $components  components from createComponent* methods
	 * @param  list<string>  $persistentParams
	 * @param  list<string>  $templates  template files of the presenter's directory
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $class,
		public readonly string $file,
		public readonly array $actions,
		public readonly array $signals,
		public readonly array $components,
		public readonly array $persistentParams,
		public readonly array $templates,
	) {
	}
}
