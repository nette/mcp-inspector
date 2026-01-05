<?php

declare(strict_types=1);

use Nette\McpInspector\Server;
use Nette\McpInspector\Toolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


class DummyToolkit implements Toolkit
{
}


test('addToolkit returns self', function () {
	$server = new Server;
	$toolkit = new DummyToolkit;
	Assert::same($server, $server->addToolkit($toolkit));
});


test('multiple toolkits can be added', function () {
	$server = new Server;
	$server->addToolkit(new DummyToolkit);
	$server->addToolkit(new DummyToolkit);
	// No exception thrown = success
	Assert::true(true);
});
