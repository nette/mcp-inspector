<?php declare(strict_types=1);

// The Nette Tester command-line runner can be
// invoked through the command: ../vendor/bin/tester .

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


// configure environment
Tester\Environment::setup();
Tester\Environment::setupFunctions();
date_default_timezone_set('Europe/Prague');
Mockery::setLoader(new Mockery\Loader\RequireLoader(getTempDir()));


function getTempDir(): string
{
	$dir = __DIR__ . '/tmp/' . getmypid();
	@mkdir($dir, 0o777, true);
	return $dir;
}


tearDown(function () {
	Mockery::close();
});


/**
 * Builds a ContainerAccessor whose container resolves the given type→instance map via getByType,
 * and throws for any other type (mirroring real Nette\DI\Container::getByType semantics).
 *
 * @param  array<class-string, object>  $byType
 */
function accessorWithTypes(array $byType): Nette\McpInspector\ContainerAccessor
{
	$container = Mockery::mock(Nette\DI\Container::class);
	$container->shouldReceive('getByType')->andReturnUsing(function (string $type) use ($byType) {
		if (!isset($byType[$type])) {
			throw new Nette\DI\MissingServiceException("No service of type $type.");
		}
		return $byType[$type];
	});
	return wrapAccessor($container);
}


/** Wraps a (mocked) Container as a ContainerAccessor that always returns it without warnings. */
function wrapAccessor(Nette\DI\Container $container): Nette\McpInspector\ContainerAccessor
{
	return new class ($container) implements Nette\McpInspector\ContainerAccessor {
		public function __construct(
			private Nette\DI\Container $container,
		) {
		}


		public function getContainer(): Nette\DI\Container
		{
			return $this->container;
		}


		public function decorateResult(array $result): array
		{
			return $result;
		}
	};
}
