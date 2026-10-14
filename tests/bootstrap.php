<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') { // @ dependencies may not be installed
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


// configure environment
Tester\Environment::setup();
Tester\Environment::setupFunctions();
date_default_timezone_set('Europe/Prague');


function getTempDir(): string
{
	$dir = __DIR__ . '/tmp/' . getmypid();
	@mkdir($dir, 0o777, true); // @ directory may already exist
	return $dir;
}


/**
 * Every tool as the client sees it carries the metadata Claude relies on, and its schema can be satisfied:
 * arrays say what their items are, objects what their properties are.
 */
function assertToolsComplete(Nette\McpInspector\Server $server): void
{
	foreach ($server->getTools() as $tool) {
		Tester\Assert::type('string', $tool->title, "$tool->name: title");
		Tester\Assert::type('string', $tool->description, "$tool->name: description");
		Tester\Assert::type('bool', $tool->annotations?->readOnlyHint, "$tool->name: readOnlyHint");
		Tester\Assert::type('array', $tool->outputSchema, "$tool->name: outputSchema");
		foreach ($tool->inputSchema['properties'] ?? [] as $property => $schema) {
			$types = (array) ($schema['type'] ?? []);
			if (in_array('array', $types, strict: true)) {
				Tester\Assert::true(isset($schema['items']), "$tool->name.$property: array without items");
			}
			if (in_array('object', $types, strict: true)) {
				Tester\Assert::true(isset($schema['properties']) || isset($schema['additionalProperties']), "$tool->name.$property: object without properties");
			}
		}
	}
}


/**
 * Calls a tool through the server the way a client would and checks the result against the tool's output schema.
 * @param  array<string, mixed>  $arguments
 */
function callTool(Nette\McpInspector\Server $server, string $name, array $arguments = []): mixed
{
	$result = $server->call($name, $arguments);
	foreach ($server->getTools() as $tool) {
		if ($tool->name === $name && $tool->outputSchema !== null) {
			$errors = (new Mcp\Capability\Discovery\SchemaValidator)->validateAgainstJsonSchema($result, $tool->outputSchema);
			Tester\Assert::same([], $errors, "$name: result does not match its output schema");
		}
	}

	return $result;
}


/**
 * Creates a throwaway Nette project: mcp-bootstrap.php returning a Configurator over config.neon.
 * Returns the project directory.
 */
function createTestProject(string $config = ''): string
{
	$dir = getTempDir() . '/project';
	Nette\Utils\FileSystem::delete($dir); // process ids get reused, so a previous run may have left files behind
	Nette\Utils\FileSystem::write("$dir/config.neon", $config);
	Nette\Utils\FileSystem::write("$dir/mcp-bootstrap.php", <<<'PHP'
		<?php
		return (new Nette\Bootstrap\Configurator)
			->setTempDirectory(__DIR__ . '/temp')
			->addConfig(__DIR__ . '/config.neon');
		PHP);
	return $dir;
}
