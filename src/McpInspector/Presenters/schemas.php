<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters\Schemas;


/**
 * One presenter of the application.
 */
final class Presenter
{
	public function __construct(
		/** presenter name with module (Admin:Article) */
		public readonly string $name,
		public readonly string $class,
		public readonly string $file,
		/** @var list<string> actions from action* and render* methods */
		public readonly array $actions,
	) {
	}
}


/**
 * Result of presenter_get_presenters.
 */
final class PresenterList
{
	public function __construct(
		/** @var array<string, string> module => class mask, as in `application: mapping:` */
		public readonly array $mapping,
		/** @var list<Presenter> */
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
	public function __construct(
		public readonly string $name,
		public readonly string $class,
		public readonly string $file,
		/** @var list<string> actions from action* and render* methods */
		public readonly array $actions,
		/** @var list<string> signals from handle* methods */
		public readonly array $signals,
		/** @var list<string> components from createComponent* methods */
		public readonly array $components,
		/** @var list<string> */
		public readonly array $persistentParams,
		/** @var list<string> template files of the presenter's directory */
		public readonly array $templates,
	) {
	}
}
