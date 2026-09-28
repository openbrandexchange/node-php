<?php

declare(strict_types=1);

namespace Obx\Node;

/** The configuration has mistakes; the message lists all of them. */
final class ConfigInvalid extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("The portal's configuration has " . (count($problems) === 1 ? 'a problem' : count($problems) . ' problems') . ":\n- " . implode("\n- ", $problems));
    }
}
