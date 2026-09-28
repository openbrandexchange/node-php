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
     * Parses {style}_{color}_{position}[_{qualifier}…].{ext} from the right (import-format.md
     * §2.1): qualifiers are popped while more than three parts remain (a two-part key such as
     * size_chart first), then applied right to left, each filling only what is still empty, so
     * the rightmost role or view wins; "other" is the view next to another role.
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
        $qualifiers = []; // left to right
        while (true) {
            $n = count($parts);
            if ($n > 4) {
                $two = strtolower($parts[$n - 2] . '_' . $parts[$n - 1]);
                if (self::isQualifier($two)) {
                    array_splice($parts, -2);
                    array_unshift($qualifiers, $two);
                    continue;
                }
            }
            if ($n > 3 && self::isQualifier(strtolower($parts[$n - 1]))) {
                array_unshift($qualifiers, strtolower((string) array_pop($parts)));
                continue;
            }
            break;
        }
        if (count($parts) < 3) {
            return null;
        }
        $pos = (string) array_pop($parts);
        $color = (string) array_pop($parts);
        $style = implode('_', $parts);
        // @phpstan-ignore identical.alwaysFalse (defensive: keep refusing "STYLE__1.jpg" if the parsing above changes)
        if (!preg_match('/^[1-9][0-9]{0,2}$/', $pos) || $color === '' || $style === '') {
            return null;
        }
        [$role, $view] = self::roleAndView($qualifiers);
        return ['style' => $style, 'color' => $color, 'position' => (int) $pos, 'role' => $role ?? 'packshot', 'view' => $view];
    }

    private static function isQualifier(string $key): bool
    {
        return in_array($key, Validator::ROLES, true) || in_array($key, Validator::VIEWS, true);
    }

    /**
     * @param list<string> $qualifiers left to right
     * @return array{0: ?string, 1: ?string} role and view
     */
    private static function roleAndView(array $qualifiers): array
    {
        $otherIsView = false;
        foreach ($qualifiers as $q) {
            if ($q !== 'other' && in_array($q, Validator::ROLES, true)) {
                $otherIsView = true;
            }
        }
        $role = null;
        $view = null;
        foreach (array_reverse($qualifiers) as $q) {
            if ($q === 'detail') {
                $role ??= 'detail';
                $view ??= 'detail';
            } elseif ($q === 'other') {
                if ($otherIsView) {
                    $view ??= 'other';
                } else {
                    $role ??= 'other';
                }
            } elseif (in_array($q, Validator::ROLES, true)) {
                $role ??= $q;
            } else {
                $view ??= $q;
            }
        }
        return [$role, $view];
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
            // Fibres are separated by commas; a comma between digits is a decimal comma ("87,5%").
            foreach (preg_split('/(?<!\d),|,(?!\d)/', $partText) ?: [] as $f) {
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
