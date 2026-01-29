<?php declare(strict_types=1);

/**
 * Test: bin/mcp-inspector commands and their exit codes, run as a separate process.
 */

use Nette\Utils\FileSystem;
use Tester\Assert;


require __DIR__ . '/bootstrap.php';


/**
 * @param  list<string>  $args
 * @return array{int, string, string}  exit code, stdout, stderr
 */
function runBin(array $args, array $env = []): array
{
	$command = array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../bin/mcp-inspector', ...$args]);
	$process = proc_open(implode(' ', $command), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	return [proc_close($process), (string) $stdout, (string) $stderr];
}


$project = createTestProject();

[$code, $out] = runBin(['--help']);
Assert::same(0, $code);
Assert::contains('Usage: mcp-inspector', $out);

[$code, , $err] = runBin(['frobnicate']);
Assert::same(1, $code);
Assert::same("Unknown command 'frobnicate'.\n", $err);

[$code, $out] = runBin(['call', 'nope', "--project=$project"]);
Assert::same(1, $code);
Assert::contains("Unknown tool 'nope'.", $out);

[$code, , $err] = runBin(['call', 'nope', '{broken', "--project=$project"]);
Assert::same(1, $code);
Assert::contains('Invalid JSON arguments', $err);

[$code, , $err] = runBin(['call', 'nope', "--project=$project"], ['MCP_INSPECTOR_DISABLED' => '1']);
Assert::same(1, $code);
Assert::contains('MCP_INSPECTOR_DISABLED', $err);

// init creates the project files
$dir = getTempDir() . '/bin-init';
FileSystem::delete($dir);
FileSystem::createDir($dir);
[$code, $out] = runBin(['init', "--project=$dir", '--php=php8.3']);
Assert::same(0, $code);
Assert::contains('mcp-bootstrap.php created', $out);
Assert::true(is_file("$dir/.mcp.json"));
Assert::contains('php8.3', FileSystem::read("$dir/.mcp.json"));
