<?php declare(strict_types=1);

use Nette\McpInspector\Toolkits\TracyToolkit;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('getLastException returns error when no log directory', function () {
	$toolkit = new TracyToolkit(null);
	$result = $toolkit->getLastException();

	Assert::same('Log directory not configured', $result['error']);
});


test('getLastException returns error when log file missing', function () {
	$toolkit = new TracyToolkit(__DIR__ . '/../tmp');
	$result = $toolkit->getLastException();

	Assert::contains('No exception log found', $result['error']);
});


test('getExceptions returns empty when no exceptions', function () {
	$toolkit = new TracyToolkit(__DIR__ . '/../tmp');
	$result = $toolkit->getExceptions();

	Assert::same([], $result['exceptions']);
	Assert::same(0, $result['count']);
});


test('getException validates filename format', function () {
	$toolkit = new TracyToolkit(__DIR__ . '/../tmp');

	// Invalid format
	$result = $toolkit->getException('malicious.php');
	Assert::same('Invalid exception filename format', $result['error']);

	// Directory traversal attempt
	$result = $toolkit->getException('../../../etc/passwd');
	Assert::same('Invalid exception filename format', $result['error']);

	// Valid format but file doesn't exist
	$result = $toolkit->getException('exception--2025-01-01--00-00--abc123.html');
	Assert::contains('not found', $result['error']);
});


test('getLog returns entries from log file', function () {
	$logDir = __DIR__ . '/../tmp/tracy-test-' . uniqid();
	@mkdir($logDir, 0o777, true);

	// Create test log file
	$logContent = "[2025-01-16 10:30:00] Test error message\n[2025-01-16 10:31:00] Another error\n";
	file_put_contents($logDir . '/error.log', $logContent);

	$toolkit = new TracyToolkit($logDir);
	$result = $toolkit->getLog('error', 10);

	Assert::same('error', $result['level']);
	Assert::same(2, $result['count']);
	Assert::contains('Another error', $result['entries'][0]['message']);
	Assert::contains('Test error', $result['entries'][1]['message']);

	// Cleanup
	@unlink($logDir . '/error.log');
	@rmdir($logDir);
});


test('getLog validates level parameter', function () {
	$toolkit = new TracyToolkit(__DIR__ . '/../tmp');
	$result = $toolkit->getLog('invalid');

	Assert::contains('Invalid level', $result['error']);
});


test('parseExceptionLogLine extracts components', function () {
	$toolkit = new TracyToolkit(__DIR__ . '/../tmp');

	// Use reflection to test private method
	$method = new ReflectionMethod($toolkit, 'parseExceptionLogLine');

	$line = '[2025-01-16 10-30-52] ParseError: syntax error, unexpected token  @  https://example.com/  @@  exception--2025-01-16--10-30--abc123def.html';
	$result = $method->invoke($toolkit, $line);

	Assert::same('2025-01-16', $result['date']);
	Assert::same('ParseError', $result['type']);
	Assert::contains('syntax error', $result['message']);
	Assert::same('https://example.com/', $result['url']);
	Assert::same('exception--2025-01-16--10-30--abc123def.html', $result['htmlFile']);
});
