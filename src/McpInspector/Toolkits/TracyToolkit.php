<?php declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Nette\IOException;
use Nette\McpInspector\ContainerAccessor;
use Nette\McpInspector\Toolkit, Nette\Utils\FileSystem, Tracy\Debugger;
use function in_array;


/**
 * Toolkit for Tracy debugger introspection.
 */
class TracyToolkit implements Toolkit
{
	private const LogLevels = ['debug', 'info', 'warning', 'error', 'exception', 'critical'];

	private ?string $logDirectory;


	public static function tryCreate(ContainerAccessor $accessor): ?self
	{
		// Tracy must be available
		if (!class_exists(Debugger::class)) {
			return null;
		}

		// Tracy::enableTracy() is typically called by App\Bootstrap, so Debugger::$logDirectory
		// is set up at this point. Fall back to project-relative conventional paths if not.
		$logDir = Debugger::$logDirectory;

		if (!$logDir) {
			$params = $accessor->getContainer()->getParameters();
			$rootDir = $params['rootDir'] ?? getcwd();
			foreach ([$rootDir . '/log', $rootDir . '/var/log', $rootDir . '/temp/log'] as $candidate) {
				if (is_dir($candidate) && is_readable($candidate)) {
					$logDir = $candidate;
					break;
				}
			}
		}

		return new self($logDir);
	}


	public function __construct(?string $logDirectory)
	{
		$this->logDirectory = $logDirectory;
	}


	/**
	 * Get the last logged exception with details.
	 * Returns exception message, file, line, and stack trace summary.
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'tracy_get_last_exception',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getLastException(): array
	{
		if (!$this->logDirectory) {
			return ['error' => 'Log directory not configured'];
		}

		$exceptionLog = $this->logDirectory . '/exception.log';
		if (!is_file($exceptionLog)) {
			return ['error' => 'No exception log found', 'path' => $exceptionLog];
		}

		$lines = file($exceptionLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: null;
		if ($lines === null) {
			return ['message' => 'No exceptions logged'];
		}

		$result = $this->parseExceptionLogLine($lines[array_key_last($lines)]);

		if (isset($result['htmlFile'])) {
			$htmlPath = $this->logDirectory . '/' . $result['htmlFile'];
			if (is_file($htmlPath)) {
				$details = $this->parseExceptionHtml($htmlPath);
				$result = array_merge($result, $details);
			}
		}

		return $result;
	}


	/**
	 * List recent exceptions from the log.
	 * @param int  $limit Maximum number of exceptions to return (default 10)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'tracy_get_exceptions',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getExceptions(
		#[Schema(minimum: 1, maximum: 200)]
		int $limit = 10,
	): array
	{
		if (!$this->logDirectory) {
			return ['error' => 'Log directory not configured'];
		}

		// Find exception HTML files
		$files = glob($this->logDirectory . '/exception--*.html');
		if (!$files) {
			return ['exceptions' => [], 'count' => 0];
		}

		// Sort by modification time (newest first) — spaceship avoids subtraction overflow
		usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

		$exceptions = [];
		foreach (array_slice($files, 0, $limit) as $file) {
			$mtime = filemtime($file);
			if ($mtime === false) {
				throw new IOException("Cannot stat file $file");
			}
			$exceptions[] = [
				'file' => basename($file),
				'date' => date('Y-m-d H:i:s', $mtime),
				'size' => filesize($file),
			];
		}

		return [
			'exceptions' => $exceptions,
			'count' => count($exceptions),
			'total' => count($files),
		];
	}


	/**
	 * Get detailed information about a specific exception by its HTML filename.
	 * Use `tracy_get_exceptions` first to obtain valid filenames, or `tracy_get_last_exception`
	 * for a one-shot lookup of the freshest crash.
	 * @param string  $filename The exception HTML filename (e.g., exception--2025-01-16--06-30--abc123.html)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'tracy_get_exception',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getException(
		#[Schema(minLength: 1)]
		string $filename,
	): array
	{
		if (!$this->logDirectory) {
			return ['error' => 'Log directory not configured'];
		}

		// Security: prevent directory traversal
		$filename = basename($filename);
		if (!str_starts_with($filename, 'exception--') || !str_ends_with($filename, '.html')) {
			return ['error' => 'Invalid exception filename format'];
		}

		$path = $this->logDirectory . '/' . $filename;
		if (!is_file($path)) {
			return ['error' => "Exception file not found: $filename"];
		}

		return $this->parseExceptionHtml($path);
	}


	/**
	 * Get recent PHP warnings logged by Tracy.
	 * Warnings include E_WARNING, E_NOTICE, E_DEPRECATED and similar non-fatal errors.
	 * @param int  $limit Maximum number of warnings to return
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'tracy_get_warnings',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getWarnings(
		#[Schema(minimum: 1, maximum: 200)]
		int $limit = 20,
	): array
	{
		if (!$this->logDirectory) {
			return ['error' => 'Log directory not configured'];
		}

		$logFile = $this->logDirectory . '/warning.log';
		if (!is_file($logFile)) {
			return ['warnings' => [], 'count' => 0, 'message' => 'No warnings logged'];
		}

		$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: null;
		if ($lines === null) {
			return ['warnings' => [], 'count' => 0];
		}

		$total = count($lines);
		$lines = array_reverse(array_slice($lines, -$limit));

		$warnings = [];
		foreach ($lines as $line) {
			$warnings[] = $this->parseWarningLine($line);
		}

		return [
			'warnings' => $warnings,
			'count' => count($warnings),
			'total' => $total,
		];
	}


	/**
	 * Get recent log entries from Tracy logs.
	 * @param string  $level Log level: debug, info, warning, error, exception, critical
	 * @param int  $limit Maximum number of entries to return
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'tracy_get_log',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getLog(
		#[Schema(enum: ['debug', 'info', 'warning', 'error', 'exception', 'critical'])]
		string $level = 'error',
		#[Schema(minimum: 1, maximum: 200)]
		int $limit = 20,
	): array
	{
		if (!$this->logDirectory) {
			return ['error' => 'Log directory not configured'];
		}

		$level = strtolower($level);
		if (!in_array($level, self::LogLevels, true)) {
			return ['error' => 'Invalid level. Valid: ' . implode(', ', self::LogLevels)];
		}

		$logFile = $this->logDirectory . '/' . $level . '.log';
		if (!is_file($logFile)) {
			return ['entries' => [], 'count' => 0, 'file' => $logFile];
		}

		$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: null;
		if ($lines === null) {
			return ['entries' => [], 'count' => 0];
		}

		$lines = array_reverse(array_slice($lines, -$limit));

		$entries = [];
		foreach ($lines as $line) {
			$entries[] = $this->parseLogLine($line);
		}

		return [
			'level' => $level,
			'entries' => $entries,
			'count' => count($entries),
		];
	}


	/**
	 * @return array<string, mixed>
	 */
	private function parseExceptionLogLine(string $line): array
	{
		$result = ['raw' => $line];

		// Parse timestamp: [2025-01-16 06-30-52]
		if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}-\d{2}-\d{2})\]/', $line, $m)) {
			$result['timestamp'] = str_replace('-', ':', substr($m[1], 11));
			$result['date'] = substr($m[1], 0, 10);
		}

		// Parse exception type and message
		if (preg_match('/^\[[^\]]+\]\s+(\w+(?:\\\\\w+)*):?\s*(.+?)(?:\s+@|$)/', $line, $m)) {
			$result['type'] = $m[1];
			$result['message'] = trim($m[2]);
		}

		// Parse URL/source
		if (preg_match('/@\s+([^\s@]+)\s+@@/', $line, $m)) {
			$result['url'] = $m[1];
		}

		// Parse HTML file reference
		if (preg_match('/@@\s+(\S+\.html)$/', $line, $m)) {
			$result['htmlFile'] = $m[1];
		}

		return $result;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function parseLogLine(string $line): array
	{
		$result = ['raw' => $line];

		// Parse timestamp
		if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}[:-]\d{2}[:-]\d{2})\]/', $line, $m)) {
			$result['timestamp'] = $m[1];
		}

		// Get message (everything after timestamp)
		if (preg_match('/^\[[^\]]+\]\s+(.+)$/', $line, $m)) {
			$result['message'] = $m[1];
		}

		return $result;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function parseWarningLine(string $line): array
	{
		$result = ['raw' => $line];

		// Parse timestamp: [2025-01-16 06-30-52] or [2025-01-16 06:30:52]
		if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}[:-]\d{2}[:-]\d{2})\]/', $line, $m)) {
			$result['timestamp'] = str_replace('-', ':', substr($m[1], 11));
			$result['date'] = substr($m[1], 0, 10);
		}

		// Parse ErrorException format: ErrorException: message in file:line
		if (preg_match('/ErrorException:\s*(.+?)\s+in\s+(.+):(\d+)/', $line, $m)) {
			$result['message'] = trim($m[1]);
			$result['file'] = $m[2];
			$result['line'] = (int) $m[3];
		}
		// Parse simple message format: message in file:line
		elseif (preg_match('/^\[[^\]]+\]\s+(.+?)\s+in\s+(.+):(\d+)/', $line, $m)) {
			$result['message'] = trim($m[1]);
			$result['file'] = $m[2];
			$result['line'] = (int) $m[3];
		}
		// Fallback: just get the message
		elseif (preg_match('/^\[[^\]]+\]\s+(.+)$/', $line, $m)) {
			$result['message'] = $m[1];
		}

		return $result;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function parseExceptionHtml(string $path): array
	{
		$html = FileSystem::read($path);
		$mtime = filemtime($path);
		if ($mtime === false) {
			throw new IOException("Cannot stat file $path");
		}
		$result = [
			'file' => basename($path),
			'path' => $path,
			'size' => filesize($path),
			'modified' => date('Y-m-d H:i:s', $mtime),
		];

		// Parse title (exception type and message)
		if (preg_match('/<title>([^<]+)<\/title>/', $html, $m)) {
			$result['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
		}

		// Parse exception class from h1
		if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/', $html, $m)) {
			$result['exceptionClass'] = trim(strip_tags($m[1]));
		}

		// Parse message from first <p> after h1
		if (preg_match('/<h1[^>]*>.*?<\/h1>\s*<p[^>]*>(.+?)<\/p>/s', $html, $m)) {
			$message = strip_tags($m[1]);
			$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
			$result['message'] = trim(preg_replace('/\s+/', ' ', $message));
		}

		// Parse file and line from source location
		if (preg_match('/in\s+<a[^>]*>([^<]+)<\/a>\s*:\s*(\d+)/', $html, $m)) {
			$result['sourceFile'] = $m[1];
			$result['sourceLine'] = (int) $m[2];
		}

		// Extract first few stack trace entries
		if (preg_match_all('/<tr[^>]*>\s*<td[^>]*>\s*(\d+)\.\s*<\/td>.*?<td[^>]*>(.+?)<\/td>/s', $html, $matches, PREG_SET_ORDER)) {
			$trace = [];
			foreach (array_slice($matches, 0, 5) as $match) {
				$entry = strip_tags($match[2]);
				$entry = html_entity_decode($entry, ENT_QUOTES, 'UTF-8');
				$trace[] = trim(preg_replace('/\s+/', ' ', $entry));
			}
			if ($trace) {
				$result['stackTrace'] = $trace;
			}
		}

		return $result;
	}
}
