<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Cli\Application;
use PnScripts\Invoice\Tests\Support\SampleInvoices;
use PnScripts\Invoice\Writer\UblWriter;

final class ApplicationTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $file;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        $file = tempnam(sys_get_temp_dir(), 'pn-invoice-cli-');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testValidFileExitsWithZero(): void
    {
        file_put_contents($this->file, (new UblWriter())->write(SampleInvoices::standard()));

        self::assertSame(Application::EXIT_OK, $this->cli('validate', $this->file));
        self::assertStringContainsString('result: VALID', $this->stdoutText());
    }

    public function testInvalidFileExitsWithOne(): void
    {
        $xml = (new UblWriter())->write(SampleInvoices::standard());
        file_put_contents($this->file, str_replace('<cbc:PayableAmount currencyID="EUR">1454.36', '<cbc:PayableAmount currencyID="EUR">1.00', $xml));

        self::assertSame(Application::EXIT_INVALID, $this->cli('validate', $this->file));
        $output = $this->stdoutText();
        self::assertStringContainsString('BR-CO-16', $output);
        self::assertStringContainsString('result: INVALID', $output);
    }

    public function testJsonOutput(): void
    {
        file_put_contents($this->file, '<broken');

        self::assertSame(Application::EXIT_INVALID, $this->cli('validate', $this->file, '--format=json'));
        $json = json_decode($this->stdoutText(), true);
        self::assertIsArray($json);
        self::assertFalse($json['valid']);
        self::assertSame($this->file, $json['file']);
    }

    public function testMissingFileExitsWithTwo(): void
    {
        self::assertSame(Application::EXIT_USAGE, $this->cli('validate', '/nonexistent.xml'));
        self::assertStringContainsString('not found', $this->stderrText());
    }

    public function testUsageErrors(): void
    {
        self::assertSame(Application::EXIT_USAGE, $this->cli('validate'));
        self::assertSame(Application::EXIT_USAGE, $this->cli('validate', '--bogus', $this->file));
        self::assertSame(Application::EXIT_USAGE, $this->cli('validate', '--format=xml', $this->file));
        self::assertSame(Application::EXIT_USAGE, $this->cli('frobnicate'));
    }

    public function testHelpVersionAndRules(): void
    {
        self::assertSame(Application::EXIT_OK, $this->cli('--help'));
        self::assertSame(Application::EXIT_OK, $this->cli('--version'));
        self::assertSame(Application::EXIT_OK, $this->cli('rules'));
        $output = $this->stdoutText();
        self::assertStringContainsString('pn-invoice validate', $output);
        self::assertStringContainsString('PN Invoice ' . Application::VERSION, $output);
        self::assertStringContainsString('BR-CO-10', $output);
        self::assertStringContainsString('104 business rules', $output);
    }

    public function testOnlyAndSkipOptions(): void
    {
        $xml = (new UblWriter())->write(SampleInvoices::standard());
        file_put_contents($this->file, str_replace('<cbc:PayableAmount currencyID="EUR">1454.36', '<cbc:PayableAmount currencyID="EUR">1.00', $xml));

        self::assertSame(Application::EXIT_OK, $this->cli('validate', $this->file, '--only=BR-01'));
        self::assertSame(Application::EXIT_OK, $this->cli('validate', $this->file, '--no-rules', '--no-xsd'));
    }

    private function cli(string ...$arguments): int
    {
        return (new Application($this->stdout, $this->stderr))->run(['pn-invoice', ...array_values($arguments)]);
    }

    private function stdoutText(): string
    {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }

    private function stderrText(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }
}
