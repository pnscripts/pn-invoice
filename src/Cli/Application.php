<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Cli;

use Pnscripts\Invoice\Validation\InvoiceValidator;
use Pnscripts\Invoice\Validation\Rules\BusinessRuleValidator;
use Pnscripts\Invoice\Validation\Severity;
use Pnscripts\Invoice\Validation\ValidationResult;

/**
 * Minimal dependency-free command line front end.
 *
 * Exit codes: 0 = valid (no errors), 1 = validation errors, 2 = usage or input error.
 */
final class Application
{
    public const string VERSION = '0.2.0';

    public const int EXIT_OK = 0;
    public const int EXIT_INVALID = 1;
    public const int EXIT_USAGE = 2;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
    ) {}

    /**
     * @param list<string> $argv including the program name
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);
        $command = array_shift($arguments);

        return match ($command) {
            null, '-h', '--help', 'help' => $this->help(),
            '-V', '--version' => $this->version(),
            'validate' => $this->validate($arguments),
            'rules' => $this->rules($arguments),
            default => $this->usageError(sprintf('Unknown command "%s".', $command)),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function validate(array $arguments): int
    {
        $format = 'text';
        $runXsd = true;
        $runRules = true;
        $only = null;
        $files = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--format=')) {
                $format = substr($argument, 9);
            } elseif ($argument === '--json') {
                $format = 'json';
            } elseif ($argument === '--no-xsd') {
                $runXsd = false;
            } elseif ($argument === '--no-rules') {
                $runRules = false;
            } elseif (str_starts_with($argument, '--only=')) {
                $only = array_values(array_filter(array_map('trim', explode(',', substr($argument, 7))), static fn(string $id): bool => $id !== ''));
            } elseif (str_starts_with($argument, '-')) {
                return $this->usageError(sprintf('Unknown option "%s".', $argument));
            } else {
                $files[] = $argument;
            }
        }

        if ($files === []) {
            return $this->usageError('Missing file argument. Usage: pn-invoice validate <file.xml> [<file.xml> ...]');
        }
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->usageError('--format must be "text" or "json".');
        }

        $validator = new InvoiceValidator(
            rules: new BusinessRuleValidator(null, $only),
            runXsd: $runXsd,
            runRules: $runRules,
        );

        $exitCode = self::EXIT_OK;
        $report = [];

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                fwrite($this->stderr, sprintf("File not found or not readable: %s\n", $file));
                $exitCode = self::EXIT_USAGE;
                continue;
            }

            $result = $validator->validateFile($file);
            if (!$result->isValid() && $exitCode === self::EXIT_OK) {
                $exitCode = self::EXIT_INVALID;
            }

            if ($format === 'json') {
                $report[] = ['file' => $file] + $result->toArray();
            } else {
                $this->printText($file, $result);
            }
        }

        if ($format === 'json') {
            fwrite($this->stdout, json_encode(count($report) === 1 ? $report[0] : $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }

        return $exitCode;
    }

    private function printText(string $file, ValidationResult $result): void
    {
        $layers = implode(', ', array_map(static fn($layer): string => $layer->value, $result->layers));
        fwrite($this->stdout, sprintf("%s\n  layers: %s\n", $file, $layers));

        foreach ($result->violations as $violation) {
            $where = $violation->location !== '' ? ' at ' . $violation->location : '';
            $line = $violation->line !== null ? sprintf(' (line %d)', $violation->line) : '';
            fwrite($this->stdout, sprintf(
                "  [%s] %s %s%s%s\n      %s\n",
                strtoupper($violation->severity === Severity::Error ? 'error' : $violation->severity->value),
                $violation->ruleId,
                $violation->layer->value,
                $where,
                $line,
                $violation->message,
            ));
        }

        fwrite($this->stdout, sprintf(
            "  result: %s (%d error(s), %d warning(s))\n",
            $result->isValid() ? 'VALID' : 'INVALID',
            count($result->errors()),
            count($result->warnings()),
        ));
        fwrite($this->stdout, "  note: business rules cover a documented subset of EN 16931; see README.\n");
    }

    /**
     * @param list<string> $arguments
     */
    private function rules(array $arguments): int
    {
        $rules = BusinessRuleValidator::implementedRules();

        if (in_array('--json', $arguments, true)) {
            fwrite($this->stdout, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return self::EXIT_OK;
        }

        foreach ($rules as $id => $description) {
            fwrite($this->stdout, sprintf("%-10s %s\n", $id, $description));
        }
        fwrite($this->stdout, sprintf("\n%d business rules implemented in PHP.\n", count($rules)));

        return self::EXIT_OK;
    }

    private function help(): int
    {
        fwrite($this->stdout, <<<TXT
            PN Invoice {$this->versionString()}

            Usage:
              pn-invoice validate <file.xml> [<file.xml> ...] [options]
              pn-invoice rules [--json]
              pn-invoice --version

            Validate options:
              --format=text|json   Output format (default: text); --json is a shortcut
              --no-xsd             Skip XML Schema validation
              --no-rules           Skip the PHP business rules
              --only=ID[,ID...]    Report only these business rule ids (e.g. BR-CO-10,BR-S-08)

            Exit codes: 0 valid, 1 validation errors, 2 usage or input error.
            The business rules implement a documented subset of EN 16931; this is not a
            certification of conformance and not legal or tax advice.

            TXT);

        return self::EXIT_OK;
    }

    private function version(): int
    {
        fwrite($this->stdout, 'PN Invoice ' . $this->versionString() . "\n");

        return self::EXIT_OK;
    }

    private function versionString(): string
    {
        return self::VERSION;
    }

    private function usageError(string $message): int
    {
        fwrite($this->stderr, $message . "\nRun \"pn-invoice --help\" for usage.\n");

        return self::EXIT_USAGE;
    }
}
