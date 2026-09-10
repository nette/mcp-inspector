<?php declare(strict_types=1);

namespace Nette\McpInspector\Tracy;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Nette\McpInspector\Masking;
use Nette\McpInspector\Toolkit;
use Nette\McpInspector\Tracy\Schemas\Log;
use Nette\McpInspector\Tracy\Schemas\LogEntry;
use Nette\McpInspector\Tracy\Schemas\Report;
use Nette\McpInspector\UntrustedData;
use Nette\Utils\FileSystem;
use function count, strlen;


/**
 * Tracy logs of the inspected application: entries of the log files by level and the exception reports
 * that Tracy 2.12+ writes as markdown for agents next to the HTML ones.
 */
class TracyToolkit implements Toolkit
{
	public function __construct(
		private readonly LoggerAccessor $logger,
		private readonly Masking $masking,
	) {
	}


	public function isAvailable(): bool
	{
		return $this->logger->isAvailable();
	}


	/**
	 * Get the newest entries of a Tracy log, newest first. Exceptions carry the name of their markdown report,
	 * read it with `tracy_get_report`.
	 * @param string  $level Log file to read: exception (default), error, warning, critical, info, debug
	 * @param int  $limit Maximum number of entries
	 */
	#[UntrustedData]
	#[McpTool(
		name: 'tracy_get_log',
		title: 'Tracy log',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getLog(
		#[Schema(enum: ['exception', 'error', 'warning', 'critical', 'info', 'debug'])]
		string $level = 'exception',
		#[Schema(minimum: 1, maximum: 200)]
		int $limit = 10,
	): Log
	{
		$directory = $this->directory();
		$file = "$directory/$level.log";
		[$lines, $total] = is_file($file) ? self::tail($file, $limit) : [[], 0];

		$entries = [];
		foreach ($lines as $line) {
			$entries[] = self::parseLine($this->masking->maskText($line), $directory);
		}

		return new Log($level, $entries, count($entries), $total);
	}


	/**
	 * Get an exception report written by Tracy: the exception, the code around it, the stack trace with
	 * arguments and the environment, as markdown. Values of secret-looking keys are masked.
	 * @param string  $report The report name from `tracy_get_log` (exception--2026-01-16--06-30--abc123.md)
	 * @param int  $maxLength Truncate the report to this many characters; the stack trace with dumped arguments can be long
	 */
	#[UntrustedData]
	#[McpTool(
		name: 'tracy_get_report',
		title: 'Tracy exception report',
		annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function getReport(
		#[Schema(minLength: 1)]
		string $report,
		#[Schema(minimum: 1000, maximum: 200_000)]
		int $maxLength = 20_000,
	): Report
	{
		$report = basename($report); // no directory traversal
		if (!preg_match('~^exception--.+\.(md|html)$~', $report)) {
			throw new ToolCallException('Invalid report name');
		}

		$report = preg_replace('~\.html$~', '.md', $report);
		$path = $this->directory() . '/' . $report;
		if (!is_file($path)) {
			throw new ToolCallException("Report not found: $report (Tracy 2.12+ writes markdown reports next to the HTML ones)");
		}

		$content = $this->masking->maskText(FileSystem::read($path));
		$truncated = strlen($content) > $maxLength;
		return new Report($report, $truncated ? substr($content, 0, $maxLength) : $content, $truncated);
	}


	private function directory(): string
	{
		return $this->logger->getDirectory()
			?? throw new ToolCallException('The application has no Tracy log directory');
	}


	/**
	 * The last lines of a file, newest first, read from the end so a large log is not loaded whole.
	 * @return array{list<string>, int}  lines, total line count
	 */
	private static function tail(string $file, int $limit): array
	{
		$handle = fopen($file, 'rb') ?: throw new ToolCallException("Unable to read $file");
		try {
			$total = 0;
			while (($chunk = fread($handle, 1 << 20)) !== false && $chunk !== '') {
				$total += substr_count($chunk, "\n");
			}
			$size = ftell($handle) ?: 0;
			if ($size > 0 && fseek($handle, -1, SEEK_END) === 0 && fgetc($handle) !== "\n") {
				$total++; // the last line has no newline
			}

			$buffer = '';
			$position = $size;
			while ($position > 0 && substr_count($buffer, "\n") <= $limit) {
				$length = min(1 << 16, $position);
				$position -= $length;
				fseek($handle, $position);
				$buffer = fread($handle, $length) . $buffer;
			}
		} finally {
			fclose($handle);
		}

		$lines = array_values(array_filter(explode("\n", str_replace("\r", '', $buffer)), fn(string $line) => $line !== ''));
		return [array_reverse(array_slice($lines, -$limit)), $total];
	}


	/**
	 * Parses a Tracy log line: [2026-01-16 06-30-52] message  @  source  @@  exception--….html
	 */
	private static function parseLine(string $line, string $directory): LogEntry
	{
		if (!preg_match('~^\[(\d{4}-\d{2}-\d{2}) (\d{2})-(\d{2})-(\d{2})\]\s*(.*)$~', $line, $m)) {
			return new LogEntry(time: null, message: $line, source: null, report: null);
		}

		$time = "$m[1] $m[2]:$m[3]:$m[4]";
		$rest = $m[5];
		$report = $source = null;
		if (preg_match('~^(.*?)\s+@@\s+(\S+\.html)$~', $rest, $mm)) {
			$rest = $mm[1];
			$candidate = substr($mm[2], 0, -5) . '.md';
			$report = is_file("$directory/$candidate") ? $candidate : null;
		}
		if (preg_match('~^(.*?)\s+@\s+(\S+)$~', $rest, $mm)) {
			$rest = $mm[1];
			$source = $mm[2];
		}

		return new LogEntry($time, $rest, $source, $report);
	}
}
