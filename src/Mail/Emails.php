<?php

declare(strict_types=1);

namespace Obx\Node\Mail;

use Obx\Node\Services\Settings;
use Obx\Node\Support\Lang;

/**
 * Renders the node's e-mails in the recipient's language. The strings are the admin-ui's
 * (keys email.* and glossary.portal in public/ui/locales/<lang>.json, vendored with the web
 * interface); built-in English copies of them are the fallback. Placeholders use {{name}}.
 *
 * Templates: invite, accessRequestReceived, accessRequestNew, accessApproved, accessRejected,
 * zipReady, passwordReset.
 */
final class Emails
{
    public const DEFAULTS = [
        'email.greeting' => 'Hello {{name}},',
        'email.greetingNoName' => 'Hello,',
        'email.signoff' => "Kind regards,\n{{brand}}",
        'email.footer' => 'You receive this email because of your account or request at {{portal}}.',
        'email.linkFallback' => 'Button not working? Copy this address into your browser: {{url}}',
        'email.invite.subject' => '{{brand}} invites you to {{portal}}',
        'email.invite.heading' => "You're invited",
        'email.invite.body' => '{{brand}} has created an account for you. There you can find their final product images and article data, and download them whenever you need.',
        'email.invite.button' => 'Choose your password',
        'email.invite.expiry' => 'This link works once and is valid until {{expires}}.',
        'email.invite.ignore' => 'Not expecting this? Then you can ignore this email.',
        'email.accessRequestReceived.subject' => 'We received your request for {{portal}}',
        'email.accessRequestReceived.heading' => 'Request received',
        'email.accessRequestReceived.body' => 'Thank you for your request to {{brand}}. We will check it and email you as soon as it has been reviewed.',
        'email.accessRequestNew.subject' => 'New access request from {{retailer}}',
        'email.accessRequestNew.body' => '{{contact}} from {{retailer}} has asked for access to {{portal}}.',
        'email.accessRequestNew.button' => 'Review the request',
        'email.accessApproved.subject' => 'Your access to {{portal}} is ready',
        'email.accessApproved.heading' => 'Welcome',
        'email.accessApproved.body' => '{{brand}} has approved your request.',
        'email.accessApproved.button' => 'Open the portal',
        'email.accessApproved.apiNote' => 'Your software can now collect its API key using the request number it received.',
        'email.accessRejected.subject' => 'About your request for {{portal}}',
        'email.accessRejected.heading' => 'Your request',
        'email.accessRejected.body' => 'Unfortunately {{brand}} cannot give you access at the moment.',
        'email.accessRejected.reason' => 'Explanation: {{reason}}',
        'email.accessRejected.contact' => 'Questions? Reply to this email or contact {{brand}}.',
        'email.zipReady.subject' => 'Your download from {{portal}} is ready',
        'email.zipReady.heading' => 'Your download is ready',
        'email.zipReady.body' => 'The zip you asked for ({{files}} files) is ready.',
        'email.zipReady.button' => 'Download',
        'email.zipReady.expiry' => 'The file is available until {{expires}}.',
        'email.passwordReset.subject' => 'Choose a new password for {{portal}}',
        'email.passwordReset.heading' => 'New password',
        'email.passwordReset.body' => 'Someone asked to reset the password of your account. Choose a new one with the button below.',
        'email.passwordReset.button' => 'Choose a new password',
        'email.passwordReset.expiry' => 'This link works once and is valid for one hour.',
        'email.passwordReset.ignore' => "Didn't ask for this? Then you can ignore this email; your password stays the same.",
    ];

    /** @var array<string, array<string, mixed>> */
    private array $locales = [];
    /** @var array<string, string>|null */
    private static ?array $glossaryPortal = null;

    public function __construct(private Settings $settings, private ?string $localesDir)
    {
    }

    /** @return array<string, mixed> */
    private function locale(string $lang): array
    {
        if (!isset($this->locales[$lang])) {
            $data = [];
            if ($this->localesDir !== null && preg_match('/^[a-z]{2}$/', $lang)) {
                $file = $this->localesDir . '/' . $lang . '.json';
                if (is_file($file)) {
                    $decoded = json_decode((string) file_get_contents($file), true);
                    $data = is_array($decoded) ? $decoded : [];
                }
            }
            $this->locales[$lang] = $data;
        }
        return $this->locales[$lang];
    }

    public function has(string $key, string $lang): bool
    {
        return $this->lookup($this->locale($lang), $key) !== null || isset(self::DEFAULTS[$key]);
    }

    /** @param array<string, mixed> $vars */
    public function t(string $key, string $lang, array $vars = []): string
    {
        $text = $this->lookup($this->locale($lang), $key)
            ?? $this->lookup($this->locale('en'), $key)
            ?? self::DEFAULTS[$key] ?? $key;
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{{' . $k . '}}'] = (string) $v;
            $repl['{{ ' . $k . ' }}'] = (string) $v;
        }
        return strtr($text, $repl);
    }

    /** @param array<string, mixed> $data */
    private function lookup(array $data, string $key): ?string
    {
        if (isset($data[$key]) && is_string($data[$key])) {
            return $data[$key];
        }
        $node = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return is_string($node) && $node !== '' ? $node : null;
    }

    /** The local word for the portal (glossary), unless the brand chose its own. */
    public function portalWord(string $lang): string
    {
        $branding = $this->settings->all()['branding'];
        $own = Lang::pick(is_array($branding['portal_name'] ?? null) ? $branding['portal_name'] : null, $lang);
        if ($own !== null) {
            return $own;
        }
        if (self::$glossaryPortal === null) {
            $g = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/resources/glossary.json'), true);
            self::$glossaryPortal = is_array($g['terms']['portal'] ?? null) ? $g['terms']['portal'] : [];
        }
        return $this->lookup($this->locale($lang), 'glossary.portal')
            ?? self::$glossaryPortal[$lang] ?? Lang::PORTAL_WORD[$lang] ?? Lang::PORTAL_WORD['en'];
    }

    /** "<Brand> · <local word>", the retailer portal name in $lang. */
    public function portalName(string $lang): string
    {
        return $this->settings->displayName() . ' · ' . $this->portalWord($lang);
    }

    /** A date in the recipient's language (UTC). */
    public static function date(int $ts, string $lang): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter($lang, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, 'UTC');
            $s = $f->format($ts);
            if (is_string($s)) {
                return $s . ' UTC';
            }
        }
        return gmdate('Y-m-d H:i', $ts) . ' UTC';
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $extra keys (under email.<template>.) rendered after the body
     * @param list<string> $after keys rendered after the button
     * @return array{subject: string, text: string, html: string}
     */
    public function render(string $template, string $lang, array $vars, ?string $buttonUrl = null, array $extra = [], array $after = [], ?string $recipientName = null): array
    {
        $lang = Lang::ui($lang);
        $vars += ['portal' => $this->portalName($lang), 'brand' => $this->settings->displayName()];
        $k = static fn (string $part): string => 'email.' . $template . '.' . $part;
        $subject = $this->t($k('subject'), $lang, $vars);
        $heading = $this->lookup($this->locale($lang), $k('heading')) !== null || isset(self::DEFAULTS[$k('heading')]) ? $this->t($k('heading'), $lang, $vars) : null;
        $greeting = $recipientName !== null && $recipientName !== ''
            ? $this->t('email.greeting', $lang, ['name' => $recipientName])
            : $this->t('email.greetingNoName', $lang);
        $lines = [$this->t($k('body'), $lang, $vars)];
        foreach ($extra as $p) {
            $lines[] = $this->t($k($p), $lang, $vars);
        }
        $afterLines = array_map(fn (string $p) => $this->t($k($p), $lang, $vars), $after);
        $button = $buttonUrl !== null ? $this->t($k('button'), $lang, $vars) : null;
        $signoff = $this->t('email.signoff', $lang, $vars);
        $footer = $this->t('email.footer', $lang, $vars);

        $text = $greeting . "\n\n" . implode("\n\n", $lines) . "\n\n";
        if ($buttonUrl !== null) {
            $text .= $button . ': ' . $buttonUrl . "\n\n";
        }
        foreach ($afterLines as $l) {
            $text .= $l . "\n\n";
        }
        $text .= $signoff . "\n\n-- \n" . $footer . "\nWorks with Open Brand Exchange\n";

        $accent = (string) ($this->settings->all()['branding']['accent_color'] ?? '') ?: '#2f5d62';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $p = static fn (string $s, string $style = 'margin:0 0 16px;font-size:16px;line-height:1.5'): string => '<p style="' . $style . '">' . nl2br($e($s)) . '</p>';
        $html = '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f4f4f2;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1d1b">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px" cellpadding="0" cellspacing="0"><tr><td>'
            . $p((string) $vars['portal'], 'margin:0 0 24px;font-weight:600;font-size:15px;color:' . $e($accent))
            . ($heading !== null ? '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3">' . $e($heading) . '</h1>' : '')
            . $p($greeting);
        foreach ($lines as $l) {
            $html .= $p($l);
        }
        if ($buttonUrl !== null) {
            $html .= '<p style="margin:24px 0"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:' . $e($accent) . ';color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600">' . $e((string) $button) . '</a></p>'
                . $p($this->t('email.linkFallback', $lang, ['url' => $buttonUrl]), 'margin:0 0 16px;font-size:13px;color:#6b6b66;word-break:break-all');
        }
        foreach ($afterLines as $l) {
            $html .= $p($l, 'margin:0 0 16px;font-size:14px;line-height:1.5;color:#4a4a45');
        }
        $html .= $p($signoff)
            . '</td></tr></table>'
            . $p($footer, 'margin:16px 0 0;font-size:12px;color:#8a8a84')
            . $p('Works with Open Brand Exchange', 'margin:4px 0 0;font-size:11px;color:#a5a59f')
            . '</td></tr></table></body></html>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }
}
