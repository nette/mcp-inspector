<?php declare(strict_types=1);

/**
 * Test: Nette\McpInspector\Installer generates the project files and never overwrites them.
 */

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\McpInspector\Cli\Installer;
use Nette\McpInspector\Inspector;
use Nette\McpInspector\Masking;
use Nette\McpInspector\Server;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;


require __DIR__ . '/bootstrap.php';


// the classic skeleton shape: static boot() returning the Configurator
class Bootstrap
{
	public static function boot(): Configurator
	{
		return new Configurator;
	}
}


$dir = getTempDir() . '/install';
FileSystem::delete($dir);
FileSystem::createDir("$dir/.cursor"); // Cursor is present, VS Code is not
FileSystem::write("$dir/.mcp.json", Json::encode(['mcpServers' => ['other' => ['command' => 'x']]], pretty: true));

$messages = (new Installer($dir, 'php8.3'))->install();
Assert::same([
	'mcp-bootstrap.php created.',
	Inspector::ConfigFile . ' created.',
	'.mcp.json: nette-inspector server added.',
	'.cursor/mcp.json: nette-inspector server created.',
], $messages);
Assert::same('php8.3', Json::decode(FileSystem::read("$dir/.cursor/mcp.json"), forceArrays: true)['mcpServers']['nette-inspector']['command']);
Assert::false(is_file("$dir/.vscode/mcp.json"));

Assert::contains('return App\Bootstrap::boot();', FileSystem::read("$dir/mcp-bootstrap.php"));
Assert::notContains('TODO', FileSystem::read("$dir/mcp-bootstrap.php"));

Assert::same(Installer::ConfigTemplate, FileSystem::read("$dir/" . Inspector::ConfigFile));
Assert::same(Masking::DefaultKeys, Neon::decode(Installer::ConfigTemplate)['inspector']['mask']); // the template spells out the real defaults
$lf = fn(string $s) => str_replace("\r\n", "\n", $s);
Assert::contains("```neon\n" . $lf(Installer::ConfigTemplate) . '```', $lf(FileSystem::read(__DIR__ . '/../readme.md'))); // the README shows the very same file
Assert::true(Inspector::createContainer($dir)->getByType(Server::class)->isEnabled()); // the generated file is valid and spells out the defaults

$mcp = Json::decode(FileSystem::read("$dir/.mcp.json"), forceArrays: true);
Assert::same(['command' => 'x'], $mcp['mcpServers']['other']); // existing entries kept
Assert::same('php8.3', $mcp['mcpServers']['nette-inspector']['command']);
Assert::same('stdio', $mcp['mcpServers']['nette-inspector']['type']);
Assert::match('%a%bin/mcp-inspector', $mcp['mcpServers']['nette-inspector']['args'][0]);


// second run leaves everything untouched
FileSystem::write("$dir/mcp-bootstrap.php", 'custom');
Assert::same([
	'mcp-bootstrap.php already exists, left untouched.',
	Inspector::ConfigFile . ' already exists, left untouched.',
	'.mcp.json already contains the nette-inspector server, left untouched.',
	'.cursor/mcp.json already contains the nette-inspector server, left untouched.',
], (new Installer($dir))->install());
Assert::same('custom', FileSystem::read("$dir/mcp-bootstrap.php"));
