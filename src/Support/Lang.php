<?php

declare(strict_types=1);

namespace Obx\Node\Support;

/**
 * Language helpers shared by the whole node: the 25 UI languages, Accept-Language negotiation
 * and the LocalizedText fallback rule (user's language -> en -> first available).
 */
final class Lang
{
    public const UI_LANGUAGES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt',
        'lv', 'mt', 'nl', 'nb', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
    ];

    /** The local word for the retailer portal ("<Brand> · <word>"), overridable by the brand. */
    public const PORTAL_WORD = [
        'bg' => 'Портал за търговци',
        'cs' => 'Portál pro prodejce',
        'da' => 'Forhandlerportal',
        'de' => 'Händlerportal',
        'el' => 'Πύλη μεταπωλητών',
        'en' => 'Brand portal',
        'es' => 'Portal para distribuidores',
        'et' => 'Edasimüüjate portaal',
        'fi' => 'Jälleenmyyjäportaali',
        'fr' => 'Espace revendeurs',
        'ga' => 'Tairseach miondíoltóirí',
        'hr' => 'Portal za trgovce',
        'hu' => 'Viszonteladói portál',
        'it' => 'Area rivenditori',
        'lt' => 'Prekybininkų portalas',
        'lv' => 'Tirgotāju portāls',
        'mt' => 'Portal għall-bejjiegħa',
        'nb' => 'Forhandlerportal',
        'nl' => 'Beeldbank',
        'pl' => 'Portal dla sprzedawców',
        'pt' => 'Portal de revendedores',
        'ro' => 'Portal pentru comercianți',
        'sk' => 'Portál pre predajcov',
        'sl' => 'Portal za trgovce',
        'sv' => 'Återförsäljarportal',
    ];

    /**
     * LocalizedText fallback: $lang, then its base language, then en, then the first available.
     *
     * @param array<string, string>|null $text
     */
    public static function pick(?array $text, ?string $lang): ?string
    {
        if ($text === null || $text === []) {
            return null;
        }
        $candidates = [];
        if ($lang !== null && $lang !== '') {
            $candidates[] = $lang;
            $base = strtolower(explode('-', $lang)[0]);
            $candidates[] = $base;
            if ($base === 'no' || $base === 'nn') {
                $candidates[] = 'nb';
            }
        }
        $candidates[] = 'en';
        foreach ($candidates as $c) {
            if (isset($text[$c]) && $text[$c] !== '') {
                return $text[$c];
            }
        }
        foreach ($text as $v) {
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** Best UI language for an Accept-Language header; en when nothing matches. */
    public static function negotiate(?string $acceptLanguage, array $supported = self::UI_LANGUAGES): string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return 'en';
        }
        $prefs = [];
        foreach (explode(',', $acceptLanguage) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $b) {
                if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($tag !== '' && $tag !== '*') {
                $prefs[] = [$tag, $q, $i];
            }
        }
        usort($prefs, static fn ($a, $b) => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
        foreach ($prefs as [$tag]) {
            $base = explode('-', $tag)[0];
            if ($base === 'no' || $base === 'nn') {
                $base = 'nb';
            }
            if (in_array($base, $supported, true)) {
                return $base;
            }
        }
        return 'en';
    }

    /** Normalises a UI language choice to one of the supported languages. */
    public static function ui(?string $lang): string
    {
        if ($lang === null) {
            return 'en';
        }
        $base = strtolower(explode('-', $lang)[0]);
        if ($base === 'no' || $base === 'nn') {
            $base = 'nb';
        }
        return in_array($base, self::UI_LANGUAGES, true) ? $base : 'en';
    }
}
