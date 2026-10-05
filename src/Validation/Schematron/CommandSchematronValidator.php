<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\Schematron;

use DOMDocument;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Validation\ValidationResult;
use RuntimeException;

/**
 * Runs an external command that writes SVRL to standard output, e.g. Saxon-HE with the
 * official pre-compiled EN 16931 XSLT:
 *
 *     new CommandSchematronValidator([
 *         Syntax::UblInvoice->value => ['java', '-jar', '/opt/saxon/saxon-he.jar', '-s:{file}', '-xsl:/opt/en16931/ubl/xslt/EN16931-UBL-validation.xslt'],
 *         Syntax::Cii->value => ['java', '-jar', '/opt/saxon/saxon-he.jar', '-s:{file}', '-xsl:/opt/en16931/cii/xslt/EN16931-CII-validation.xslt'],
 *     ]);
 *
 * The command is executed without a shell (argument array); "{file}" is replaced by the path
 * of a temporary copy of the document. Neither Saxon nor the XSLT files ship with this library.
 */
final readonly class CommandSchematronValidator implements SchematronValidator
{
    /**
     * @param array<string, list<string>> $commands Syntax value => command argument list
     */
    public function __construct(
        private array $commands,
        private SvrlParser $parser = new SvrlParser(),
        private int $timeoutSeconds = 120,
    ) {}

    public function validate(DOMDocument $document, Syntax $syntax): ValidationResult
    {
        $command = $this->commands[$syntax->value] ?? null;
        if ($command === null || $command === []) {
            throw new RuntimeException(sprintf('No Schematron command configured for %s.', $syntax->label()));
        }

        $file = tempnam(sys_get_temp_dir(), 'pn-invoice-');
        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        try {
            if ($document->save($file) === false) {
                throw new RuntimeException('Could not write the temporary document.');
            }

            $arguments = array_map(static fn(string $argument): string => str_replace('{file}', $file, $argument), $command);

            return $this->parser->parse($this->run($arguments));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): string
    {
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Schematron command.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException('The Schematron command timed out.');
            }

            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $exitCode = $status['exitcode'];
        if ($exitCode !== 0 && trim($stdout) === '') {
            throw new RuntimeException(sprintf('The Schematron command failed with exit code %d: %s', $exitCode, trim($stderr)));
        }

        return $stdout;
    }
}
