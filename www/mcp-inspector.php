<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nette\McpInspector\ServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$psr17 = new Psr17Factory;
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();

$server = ServerFactory::create();
$response = $server->runHttp($request, $psr17, $psr17);

// Emit response
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
	foreach ($values as $value) {
		header("$name: $value", false);
	}
}
echo $response->getBody();
