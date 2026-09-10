<?php declare(strict_types=1);

namespace Nette\McpInspector\Presenters;

use Composer\Autoload\ClassLoader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenter;
use Nette\Application\UI;
use Nette\McpInspector\AppContainer;
use Nette\McpInspector\Presenters\Schemas\Presenter;
use Nette\McpInspector\Presenters\Schemas\PresenterDetail;
use Nette\McpInspector\Presenters\Schemas\PresenterList;
use Nette\McpInspector\Toolkit;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use function count, is_array, is_string;


/**
 * Presenters of the application: the mapping, the presenters found on disk with their actions, and the details
 * of one presenter (signals, components, persistent parameters, templates).
 */
class PresenterToolkit implements Toolkit
{
	public function __construct(
		private readonly PresenterAccessor $presenters,
		private readonly AppContainer $app,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->presenters->isAvailable();
	}


	/**
	 * List the presenters of the application with their actions, and the mapping from presenter names to classes.
	 * Presenters are found on disk in the directories of the mapped namespaces (*Presenter.php).
	 */
	#[McpTool(
		name: 'presenter_get_presenters',
		title: 'Presenters',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getPresenters(): PresenterList
	{
		$mapping = $this->presenters->getMapping();
		$presenters = [];
		foreach ($this->findPresenterClasses($mapping) as $class => $file) {
			$name = $this->unformatPresenterClass($class, $mapping);
			if ($name !== null) {
				$presenters[] = new Presenter($name, $class, $file, self::methodNames($class, ['action', 'render']));
			}
		}
		usort($presenters, fn(Presenter $a, Presenter $b) => strcmp($a->name, $b->name));

		return new PresenterList($mapping, $presenters, count($presenters));
	}


	/**
	 * Get details of one presenter: class, actions, signals, components, persistent parameters and templates.
	 * @param string  $name Presenter name with module (e.g. "Article" or "Admin:Article")
	 */
	#[McpTool(
		name: 'presenter_get_presenter',
		title: 'Presenter',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getPresenter(
		#[Schema(minLength: 1)]
		string $name,
	): PresenterDetail
	{
		try {
			$class = $this->presenters->get()->getPresenterClass($name);
		} catch (InvalidPresenterException $e) {
			throw new ToolCallException($e->getMessage());
		}

		$file = (new \ReflectionClass($class))->getFileName();
		$file = $file === false ? '' : FileSystem::unixSlashes($file);
		$persistent = is_subclass_of($class, UI\Presenter::class)
			? array_keys((new UI\ComponentReflection($class))->getPersistentParams())
			: [];

		$templates = [];
		if ($file !== '') {
			foreach (Finder::findFiles('*.latte')->from(dirname($file)) as $template) {
				$templates[] = FileSystem::unixSlashes((string) $template);
			}
			sort($templates);
		}

		return new PresenterDetail(
			name: $name,
			class: $class,
			file: $file,
			actions: self::methodNames($class, ['action', 'render']),
			signals: self::methodNames($class, ['handle']),
			components: self::methodNames($class, ['createComponent']),
			persistentParams: $persistent,
			templates: $templates,
		);
	}


	/**
	 * Presenter classes in the directories of the mapped namespaces: Composer PSR-4 directories and the
	 * application's %appDir% (RobotLoader has no public map).
	 * @param  array<string, string>  $mapping
	 * @return array<class-string, string>  class => file
	 */
	private function findPresenterClasses(array $mapping): array
	{
		$namespaces = [];
		foreach ($mapping as $mask) {
			$namespaces[] = rtrim((string) preg_replace('~[^\\\]*\*.*$~', '', $mask), '\\'); // the fixed namespace before the first wildcard
		}

		$directories = [];
		foreach (spl_autoload_functions() as $autoloader) {
			if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
				foreach ($autoloader[0]->getPrefixesPsr4() as $prefix => $dirs) {
					$prefix = rtrim($prefix, '\\');
					foreach ($namespaces as $namespace) {
						if (
							str_starts_with($namespace . '\\', $prefix . '\\')
							|| str_starts_with($prefix . '\\', $namespace . '\\')
						) {
							$directories = [...$directories, ...$dirs];
						}
					}
				}
			}
		}
		try {
			$appDir = $this->app->get()->getParameters()['appDir'] ?? null;
			if (is_string($appDir)) {
				$directories[] = $appDir;
			}
		} catch (\Throwable) {
			// parameters are not exported
		}

		$classes = [];
		foreach (array_unique(array_filter(array_map(fn(string $dir) => realpath($dir) ?: null, $directories))) as $directory) {
			foreach (Finder::findFiles('*Presenter.php')->from($directory) as $file) {
				$class = self::classInFile((string) $file);
				if (
					$class !== null
					&& class_exists($class)
					&& is_subclass_of($class, IPresenter::class)
					&& !(new \ReflectionClass($class))->isAbstract()
				) {
					$classes[$class] = FileSystem::unixSlashes((string) $file);
				}
			}
		}
		ksort($classes);

		return $classes;
	}


	/**
	 * The presenter name a class answers to, or null when no mapping mask produces it.
	 * @param  array<string, string>  $mapping
	 */
	private function unformatPresenterClass(string $class, array $mapping): ?string
	{
		foreach ($mapping as $module => $mask) {
			if (!preg_match('~^(?<prefix>(?:[\w\\\]*\\\)?)(?<module>\w*\*\w*?\\\)?(?<presenter>[\w\\\]*\*\*?\w*)$~D', $mask, $m)) {
				continue;
			}

			$moduleMask = $m['module'] === '' ? '*\\' : $m['module'];
			$pattern = '~^' . preg_quote($m['prefix'], '~')
				. '(?<modules>(?:' . str_replace('\*', '\w+', preg_quote($moduleMask, '~')) . ')*)'
				. str_replace(['\*\*', '\*'], ['(?<name>\w+)\\\\\k{name}', '(?<name>\w+)'], preg_quote($m['presenter'], '~'))
				. '$~';
			if (!preg_match($pattern, $class, $mm)) {
				continue;
			}

			$modulePattern = '~' . str_replace('\*', '(\w+)', preg_quote($moduleMask, '~')) . '~';
			preg_match_all($modulePattern, $mm['modules'], $modules);
			$parts = [...($module === '*' ? [] : [$module]), ...$modules[1], $mm['name']];
			return implode(':', $parts);
		}

		return null;
	}


	/**
	 * @param  class-string  $class
	 * @param  list<string>  $prefixes
	 * @return list<string>
	 */
	private static function methodNames(string $class, array $prefixes): array
	{
		$names = [];
		foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
			foreach ($prefixes as $prefix) {
				if (
					$method->getDeclaringClass()->getName() !== UI\Presenter::class
					&& preg_match('~^' . $prefix . '([A-Z]\w*)$~', $method->getName(), $m)
				) {
					$names[] = lcfirst($m[1]);
				}
			}
		}

		return array_values(array_unique($names));
	}


	private static function classInFile(string $file): ?string
	{
		$namespace = '';
		$tokens = \PhpToken::tokenize(FileSystem::read($file));
		foreach ($tokens as $i => $token) {
			if ($token->is(T_NAMESPACE)) {
				for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is([';', '{']); $j++) {
					if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
						$namespace = $tokens[$j]->text . '\\';
					}
				}
			} elseif ($token->is(T_CLASS)) {
				for ($j = $i + 1; isset($tokens[$j]); $j++) {
					if ($tokens[$j]->is(T_STRING)) {
						return $namespace . $tokens[$j]->text;
					} elseif (!$tokens[$j]->isIgnorable()) {
						break; // ::class or an anonymous class
					}
				}
			}
		}

		return null;
	}
}
