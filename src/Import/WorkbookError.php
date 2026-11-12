<?php

declare(strict_types=1);

namespace Obx\Node\Import;

/**
 * A workbook that cannot be imported (docs/import-format.md §5.5): workbook_invalid,
 * workbook_unsafe, workbook_sheet_missing or workbook_too_large. The import reports it as one
 * ImportRowError with row and column null and imports nothing of that file.
 */
final class WorkbookError extends \RuntimeException
{
    public function __construct(public readonly string $obxError, string $detail)
    {
        parent::__construct($detail);
    }
}
