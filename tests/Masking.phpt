<?php declare(strict_types=1);

/**
 * Test: Nette\McpInspector\Masking recognizes secret-looking keys by whole words and masks values in text.
 */

use Nette\McpInspector\Masking;
use Tester\Assert;


require __DIR__ . '/bootstrap.php';


$masking = new Masking;

foreach (['password', 'db_password', 'dbPassword', 'PASSWORD', 'DB_PASSWORD', 'secret', 'token', 'authToken', 'auth_token', 'auth', 'apiKey', 'api_key', 'apikey', 'API_KEY', 'credentials', 'dsn', 'database.default.dsn', 'pass', 'SMTP_PASS'] as $key) {
	Assert::true($masking->shouldMask($key), $key);
}

foreach (['author', 'author_id', 'authorName', 'AUTHOR_ID', 'tokenizer', 'connections_count', 'connection', 'name', 'id', 'cacheKey', 'key', 'secretary', 'bypass'] as $key) {
	Assert::false($masking->shouldMask($key), $key);
}


// the key list is configurable
$custom = new Masking(['key', 'pin']);
Assert::true($custom->shouldMask('stripe.key'));
Assert::true($custom->shouldMask('userPin'));
Assert::false($custom->shouldMask('password'));
Assert::false((new Masking([]))->shouldMask('password'));


// values are masked in text: markdown tables and key/value pairs
Assert::same(<<<'TXT'
	| Key | Value |
	| DB_PASSWORD | *** |
	| DB_USER | root |
	password: ***
	'apiKey' => '***',
	"author" => "john"
	$options = ['token' => '***']
	TXT, $masking->maskText(<<<'TXT'
	| Key | Value |
	| DB_PASSWORD | s3cret |
	| DB_USER | root |
	password: hunter2
	'apiKey' => 'abc-123',
	"author" => "john"
	$options = ['token' => 'xyz']
	TXT));
