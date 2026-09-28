<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

/**
 * Who is reading the catalog: a retailer (via an API client or a portal account) or a brand
 * admin previewing the portal. Visibility is the same single rule for everyone; the grant only
 * narrows the brands and decides originals.
 */
final class Viewer
{
    /**
     * @param list<string>|null $brandIds grant brands (null = all published)
     * @param string $actor signed-URL actor tag: "c:<client id>" (API) or "v:<user id>" (portal display)
     */
    public function __construct(
        public readonly ?string $retailerId,
        public readonly ?array $brandIds,
        public readonly bool $originalAllowed,
        public readonly string $actor,
        public readonly bool $preview = false,
        public readonly ?string $retailerName = null,
        public readonly ?string $actorName = null,
    ) {
    }

    public static function preview(bool $originals = true, string $actor = 'p'): self
    {
        return new self(null, null, $originals, $actor, true);
    }
}
