<?php

declare(strict_types=1);

namespace Obx\Node\Import;

use Obx\Node\Catalog\Validator;

/**
 * Parsers of the canonical import format: image file names, materials grammar, care codes,
 * booleans and keys (docs/import-format.md).
 */
final class Formats
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];

    public const CARE_CODES = [
        'wash_95', 'wash_70', 'wash_60', 'wash_60_mild', 'wash_50', 'wash_50_mild', 'wash_40', 'wash_40_mild', 'wash_40_very_mild',
        'wash_30', 'wash_30_mild', 'wash_30_very_mild', 'hand_wash', 'do_not_wash',
        'bleach_any', 'bleach_oxygen_only', 'do_not_bleach',
        'tumble_dry_normal', 'tumble_dry_low', 'do_not_tumble_dry',
        'line_dry', 'drip_line_dry', 'dry_flat', 'drip_dry_flat', 'line_dry_shade', 'drip_line_dry_shade', 'dry_flat_shade', 'drip_dry_flat_shade',
        'iron_high', 'iron_medium', 'iron_low', 'do_not_iron',
        'dry_clean_p', 'dry_clean_p_mild', 'dry_clean_p_very_mild', 'dry_clean_f', 'dry_clean_f_mild', 'dry_clean_f_very_mild', 'do_not_dry_clean',
        'wet_clean_w', 'wet_clean_w_mild', 'wet_clean_w_very_mild', 'do_not_wet_clean',
    ];

    public static function isImageName(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Parses {style}_{color}_{position}[_{qualifier}…].{ext} from the right.
     *
     * @return array{style: string, color: string, position: int, role: string, view: ?string}|null
     */
    public static function parseFilename(string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return null;
        }
        $stem = substr($filename, 0, -(strlen($ext) + 1));
        $parts = explode('_', $stem);
        $role = null;
        $view = null;
        while (count($parts) > 3) {
            $last = strtolower((string) end($parts));
            // Two-part qualifier keys first (size_chart, tilted_left, tilted_right).
            if (count($parts) > 4) {
                $two = strtolower($parts[count($parts) - 2] . '_' . $last);
                if (in_array($two, Validator::ROLES, true) || in_array($two, Validator::VIEWS, true)) {
                    array_pop($parts);
                    array_pop($parts);
                    if (in_array($two, Validator::ROLES, true)) {
                        $role ??= $two;
                    } else {
                        $view ??= $two;
                    }
                    continue;
                }
            }
            $isRole = in_array($last, Validator::ROLES, true);
            $isView = in_array($last, Validator::VIEWS, true);
            if (!$isRole && !$isView) {
                break;
            }
            array_pop($parts);
            if ($last === 'detail') {
                $role ??= 'detail';
                $view ??= 'detail';
            } elseif ($isRole && $role === null) {
                $role = $last;
            } elseif ($isView && $view === null) {
                $view = $last;
            }
        }
        if (count($parts) < 3) {
            return null;
        }
        $pos = (string) array_pop($parts);
        $color = (string) array_pop($parts);
        $style = implode('_', $parts);
        if (!preg_match('/^[1-9][0-9]{0,2}$/', $pos) || $color === '' || $style === '') {
            return null;
        }
        return ['style' => $style, 'color' => $color, 'position' => (int) $pos, 'role' => $role ?? 'packshot', 'view' => $view];
    }

    /**
     * materials := part (";" part)*, part := [name ":"] fiber ("," fiber)*, fiber := number "%" key
     *
     * @param list<string> $warnings
     * @return list<array<string, mixed>>|null null when the text cannot be parsed
     */
    public static function parseMaterials(string $text, array &$warnings): ?array
    {
        $out = [];
        foreach (explode(';', $text) as $partText) {
            $partText = trim($partText);
            if ($partText === '') {
                continue;
            }
            $name = 'main';
            if (preg_match('/^([^:%]+):(.*)$/s', $partText, $m)) {
                $name = self::key($m[1]);
                $partText = $m[2];
            }
            if ($name === '') {
                return null;
            }
            $fibers = [];
            $sum = 0.0;
            foreach (explode(',', $partText) as $f) {
                $f = trim($f);
                if ($f === '') {
                    continue;
                }
                if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*%\s*(.+)$/u', $f, $fm)) {
                    return null;
                }
                $pct = (float) str_replace(',', '.', $fm[1]);
                $key = self::key($fm[2]);
                if ($key === '' || $pct <= 0 || $pct > 100) {
                    return null;
                }
                $fibers[] = ['fiber' => $key, 'percentage' => Validator::number($pct)];
                $sum += $pct;
            }
            if ($fibers === []) {
                return null;
            }
            if (abs($sum - 100) > 0.01) {
                $warnings[] = 'Percentages of "' . $name . '" add up to ' . $sum . ', not 100.';
            }
            $out[] = ['part' => $name, 'fibers' => $fibers];
        }
        return $out === [] ? null : $out;
    }

    /** Lowercase key with "_" ("Organic Cotton" -> organic_cotton). */
    public static function key(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim($s, '_');
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $s) ? $s : '';
    }

    public static function bool(string $v): ?bool
    {
        $v = strtolower(trim($v));
        return match ($v) {
            'true', 'yes', 'ja', '1', 'y', 'j' => true,
            'false', 'no', 'nee', '0', 'n' => false,
            default => null,
        };
    }
}
