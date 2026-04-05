<?php declare(strict_types=1);

/**
 * Test: TracyToolkit over the log directory of the application's Tracy logger.
 */

use Mcp\Exception\ToolCallException;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Server;
use Nette\McpInspector\Tracy\Schemas\LogEntry;
use Nette\McpInspector\Tracy\TracyToolkit;
use Nette\Utils\FileSystem;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


$project = createTestProject(<<<'NEON'
	services:
		tracy.logger: Tracy\Logger(%tempDir%/../log)
	NEON);
$logDir = "$project/log";
FileSystem::write("$logDir/error.log", <<<'LOG'
	[2026-01-16 06-30-52] first  @  http://example.com/
	[2026-01-16 06-31-10] PHP Warning: Undefined variable $x in /app/Foo.php:12  @  CLI
	LOG);
FileSystem::write("$logDir/exception.log", <<<'LOG'
	[2026-01-15 10-00-00] RuntimeException: old  @  http://example.com/  @@  exception--2026-01-15--10-00--old.html
	[2026-01-16 06-32-00] RuntimeException: boom  @  http://example.com/x  @@  exception--2026-01-16--06-32--abc123.html
	LOG);
FileSystem::write("$logDir/exception--2026-01-16--06-32--abc123.html", '<html>…</html>');
FileSystem::write("$logDir/exception--2026-01-16--06-32--abc123.md", "# RuntimeException: boom\n\nin /app/Foo.php:12\n\n## Stack Trace\n\n1. Foo::bar()\n");

$container = Inspector::createContainer($project);
$toolkit = $container->getByType(TracyToolkit::class);
Assert::true($toolkit->isAvailable());
assertToolsComplete($container->getByType(Server::class));


test('exception log is the default; entries come newest first with their markdown report', function () use ($toolkit) {
	$result = $toolkit->getLog();
	Assert::same('exception', $result->level);
	Assert::same(2, $result->count);
	Assert::equal(new LogEntry(
		time: '2026-01-16 06:32:00',
		message: 'RuntimeException: boom',
		source: 'http://example.com/x',
		report: 'exception--2026-01-16--06-32--abc123.md',
	), $result->entries[0]);
	Assert::null($result->entries[1]->report); // the old report has no markdown twin
});


test('through the server, log entries and reports are marked as untrusted data', function () use ($container) {
	$server = $container->getByType(Server::class);
	Assert::contains('untrusted', callTool($server, 'tracy_get_log')['_notice']);
	Assert::contains('untrusted', callTool($server, 'tracy_get_report', ['report' => 'exception--2026-01-16--06-32--abc123.md'])['_notice']);
});


test('a large log is read from the end', function () use ($toolkit, $logDir) {
	$lines = [];
	for ($i = 1; $i <= 50_000; $i++) {
		$lines[] = "[2026-01-16 06-30-52] message $i  @  CLI";
	}
	FileSystem::write("$logDir/warning.log", implode("\n", $lines) . "\n");

	$result = $toolkit->getLog('warning', 3);
	Assert::same(50_000, $result->total);
	Assert::same(['message 50000', 'message 49999', 'message 49998'], array_column($result->entries, 'message'));
});


test('other levels and the limit', function () use ($toolkit) {
	$result = $toolkit->getLog('error', 1);
	Assert::same(1, $result->count);
	Assert::same(2, $result->total);
	Assert::same('2026-01-16 06:31:10', $result->entries[0]->time);
	Assert::same('PHP Warning: Undefined variable $x in /app/Foo.php:12', $result->entries[0]->message);
	Assert::same('CLI', $result->entries[0]->source);

	Assert::same(0, $toolkit->getLog('info')->count);
});


test('reports are returned as Tracy wrote them, secrets masked, optionally truncated', function () use ($toolkit, $logDir) {
	$report = $toolkit->getReport('exception--2026-01-16--06-32--abc123.md');
	Assert::match("# RuntimeException: boom\n%A%", $report->content);
	Assert::false($report->truncated);

	$report = $toolkit->getReport('exception--2026-01-16--06-32--abc123.html', maxLength: 1000); // the HTML name is accepted too
	Assert::same('exception--2026-01-16--06-32--abc123.md', $report->report);

	FileSystem::write("$logDir/exception--2026-01-16--07-00--env.md", "# Boom\n\n## Environment\n\n| Key | Value |\n|---|---|\n| DB_PASSWORD | s3cret |\n| DB_USER | root |\n\n\$options = ['token' => 'abc']\n");
	Assert::same("# Boom\n\n## Environment\n\n| Key | Value |\n|---|---|\n| DB_PASSWORD | *** |\n| DB_USER | root |\n\n\$options = ['token' => '***']\n", $toolkit->getReport('exception--2026-01-16--07-00--env.md')->content);

	Assert::exception(fn() => $toolkit->getReport('../secret.md'), ToolCallException::class, 'Invalid report name');
	Assert::exception(fn() => $toolkit->getReport('exception--2026-01-15--10-00--old.md'), ToolCallException::class, 'Report not found: %a%');
});


test('without a Tracy logger the toolkit is unavailable', function () {
	$project = createTestProject();
	Assert::false(Inspector::createContainer($project)->getByType(TracyToolkit::class)->isAvailable());
});
