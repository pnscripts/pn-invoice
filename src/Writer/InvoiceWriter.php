<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Writer;

use DOMDocument;
use PnScripts\Invoice\Model\Invoice;

interface InvoiceWriter
{
    public function toDocument(Invoice $invoice): DOMDocument;

    /**
     * Serialises the invoice as a UTF-8 XML string.
     */
    public function write(Invoice $invoice): string;
}
