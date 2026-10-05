<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

use DOMDocument;
use Pnscripts\Invoice\Validation\Syntax;

final class ViewFactory
{
    public static function create(DOMDocument $document, Syntax $syntax): InvoiceView
    {
        return $syntax === Syntax::Cii ? new CiiView($document) : new UblView($document, $syntax);
    }
}
