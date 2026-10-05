<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\Xsd;

use Pnscripts\Invoice\Validation\Syntax;
use RuntimeException;

/**
 * Resolves the official XSD entry point for a syntax.
 *
 * By default the schemas bundled in resources/schemas are used (see NOTICE for their
 * sources and licences). Pass another directory with the same layout to use your own copy.
 */
final readonly class SchemaLocator
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? dirname(__DIR__, 3) . '/resources/schemas', '/');
    }

    public function pathFor(Syntax $syntax): string
    {
        $path = $this->directory . '/' . match ($syntax) {
            Syntax::UblInvoice => 'ubl-2.1/maindoc/UBL-Invoice-2.1.xsd',
            Syntax::UblCreditNote => 'ubl-2.1/maindoc/UBL-CreditNote-2.1.xsd',
            Syntax::Cii => 'cii-d16b/uncefact/data/standard/CrossIndustryInvoice_100pD16B.xsd',
        };

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('XML schema not found at "%s".', $path));
        }

        return $path;
    }
}
