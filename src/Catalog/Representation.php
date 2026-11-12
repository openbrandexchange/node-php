<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Images\ImageInfo;
use Obx\Node\Support\Json;

/**
 * Builds the protocol representation of stored objects (without updated_at and without
 * rendition URLs, which are added at read time). The hash of this representation decides
 * whether a write is visible in /changes.
 */
final class Representation
{
    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function brand(array $row): array
    {
        $in = Json::decode((string) $row['input'], []);
        return [
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'name' => $in['name'] ?? $row['name'],
            'logo_url' => $in['logo_url'] ?? null,
            'website' => $in['website'] ?? null,
            'description' => $in['description'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function product(array $row): array
    {
        $in = Json::decode((string) $row['input'], []);
        return [
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'brand_id' => $row['brand_id'],
            'style_number' => $in['style_number'] ?? $row['style_number'],
            'name' => $in['name'] ?? [],
            'description' => $in['description'] ?? null,
            'item_type' => $in['item_type'] ?? null,
            'item_subtype' => $in['item_subtype'] ?? null,
            'gpc_brick' => $in['gpc_brick'] ?? null,
            'target_group' => $in['target_group'] ?? null,
            'season' => $in['season'] ?? null,
            'collection' => $in['collection'] ?? null,
            // 0.2 (protocol.md §5.9): every article data property is present, null when not
            // stated; fibres are always normalised (no recycled_*/organic_* keys).
            'materials' => ArticleData::normalizeMaterials($in['materials'] ?? null),
            'contains_animal_origin' => $in['contains_animal_origin'] ?? null,
            'footwear_materials' => $in['footwear_materials'] ?? null,
            'care' => $in['care'] ?? null,
            'country_of_origin' => $in['country_of_origin'] ?? null,
            'certifications' => $in['certifications'] ?? null,
            'dpp_url' => $in['dpp_url'] ?? null,
            'features' => isset($in['features']) && $in['features'] !== [] ? $in['features'] : null,
            'attributes' => $in['attributes'] ?? null,
            'created_at' => $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function variant(array $row, string $brandId): array
    {
        $in = Json::decode((string) $row['input'], []);
        $gtin = $in['gtin'] ?? null;
        return [
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'product_id' => $row['product_id'],
            'brand_id' => $brandId,
            'gtin' => $gtin !== null ? str_pad((string) $gtin, 14, '0', STR_PAD_LEFT) : null,
            'gtin_original' => $gtin,
            'sku' => $in['sku'] ?? null,
            'color' => [
                'code' => $in['color']['code'] ?? '',
                'name' => $in['color']['name'] ?? null,
                'supplier_color' => $in['color']['supplier_color'] ?? null,
                'family' => $in['color']['family'] ?? null,
            ],
            'size' => $in['size'] ?? null,
            'size_length' => $in['size_length'] ?? null,
            'size_system' => $in['size_system'] ?? null,
            'measurements' => isset($in['measurements']) && $in['measurements'] !== [] ? $in['measurements'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $in
     * @param list<string>|null $variantIds
     * @param array<string, array<string, mixed>> $files
     * @param array<string, array<string, mixed>|null> $blobs
     * @param array<string, mixed> $rights effective rights
     * @return array<string, mixed>
     */
    public static function asset(array $row, array $in, string $brandId, ?array $variantIds, array $files, array $blobs, array $rights): array
    {
        $orig = $blobs['original'];
        $renditions = [];
        foreach (['original', 'web', 'square', 'thumb'] as $name) {
            $b = $blobs[$name];
            $renditions[$name] = [
                'name' => $name,
                'content_type' => (string) $b['content_type'],
                'width' => (int) ($files[$name]['width'] ?? $b['width']),
                'height' => (int) ($files[$name]['height'] ?? $b['height']),
                'bytes' => (int) $b['bytes'],
                'sha256' => (string) $b['sha256'],
            ];
        }
        $filename = $in['filename'] ?? null;
        if ($filename === null || $filename === '') {
            $filename = $row['external_id'] . '.' . ImageInfo::extension((string) $orig['content_type']);
        }
        return [
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'product_id' => $row['product_id'],
            'brand_id' => $brandId,
            'media_type' => 'image',
            'color_code' => $in['color_code'] ?? null,
            'variant_ids' => $variantIds,
            'role' => $in['role'],
            'view' => $in['view'] ?? null,
            'position' => (int) $in['position'],
            'filename' => $filename,
            'alt_text' => $in['alt_text'] ?? null,
            'content_type' => (string) $orig['content_type'],
            'width' => $renditions['original']['width'],
            'height' => $renditions['original']['height'],
            'bytes' => (int) $orig['bytes'],
            'sha256' => (string) $orig['sha256'],
            'rights' => self::rights($rights),
            'provenance' => self::provenance($in),
            'renditions' => $renditions,
        ];
    }

    /**
     * Full Rights object from effective rights (node defaults merged with the asset's own fields).
     *
     * @param array<string, mixed> $rights
     * @return array<string, mixed>
     */
    public static function rights(array $rights): array
    {
        return [
            'license' => $rights['license'] ?? 'retailer-use',
            'license_text' => $rights['license_text'] ?? null,
            'channels' => array_values((array) ($rights['channels'] ?? [])),
            'territories' => $rights['territories'] ?? null,
            'embargo_until' => $rights['embargo_until'] ?? null,
            'expires_at' => $rights['expires_at'] ?? null,
            'credit' => $rights['credit'] ?? null,
            'model_release' => $rights['model_release'] ?? null,
        ];
    }

    /**
     * Provenance with the documented defaults applied (docs/ingest.md).
     *
     * @param array<string, mixed> $in AssetInput
     * @return array<string, mixed>
     */
    public static function provenance(array $in): array
    {
        $prov = (array) ($in['provenance'] ?? []);
        $ai = (bool) ($prov['ai_generated'] ?? false);
        return [
            'ai_generated' => $ai,
            'ai_edited' => (bool) ($prov['ai_edited'] ?? false),
            'source' => $prov['source'] ?? ($ai ? 'generated' : 'photo'),
            'c2pa' => $prov['c2pa'] ?? 'none',
            'c2pa_manifest_url' => $prov['c2pa_manifest_url'] ?? null,
            'generator' => $prov['generator'] ?? null,
        ];
    }
}
