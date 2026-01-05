<?php

declare(strict_types=1);

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
