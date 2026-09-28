<?php

declare(strict_types=1);

namespace Obx\Node\Db;

/** The database was written by a newer release than the code that opened it. */
final class SchemaTooNew extends \RuntimeException
{
}
