<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters\Schemas;


/**
 * One presenter of the application.
 */
final readonly class Presenter
{
	public function __construct(
		/** presenter name with module (Admin:Article) */
		public string $name,
		public string $class,
		public string $file,
		/** @var list<string> actions from action* and render* methods */
		public array $actions,
	) {
	}
}


/**
 * Result of presenter_get_presenters.
 */
final readonly class PresenterList
{
	public function __construct(
		/** @var array<string, string> module => class mask, as in `application: mapping:` */
		public array $mapping,
		/** @var list<Presenter> */
		public array $presenters,
		public int $count,
	) {
	}
}


/**
 * Result of presenter_get_presenter.
 */
final readonly class PresenterDetail
{
	public function __construct(
		public string $name,
		public string $class,
		public string $file,
		/** @var list<string> actions from action* and render* methods */
		public array $actions,
		/** @var list<string> signals from handle* methods */
		public array $signals,
		/** @var list<string> components from createComponent* methods */
		public array $components,
		/** @var list<string> */
		public array $persistentParams,
		/** @var list<string> template files of the presenter's directory */
		public array $templates,
	) {
	}
}
