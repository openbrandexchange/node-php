<?php

declare(strict_types=1);

namespace Obx\Node\Mail;

use Obx\Node\Services\Settings;
use Obx\Node\Support\Lang;

/**
 * Renders the node's e-mails (invite, access request, access approved/rejected, zip ready,
 * password reset) in the recipient's language. Strings come from the admin-ui locale files
 * vendored in the UI directory (locales/<lang>.json, keys email.*), with built-in English as the
 * fallback. Placeholders: {{name}} (i18next style) and {name} are both accepted.
 */
final class Emails
{
    public const DEFAULTS = [
        'email.common.greeting' => 'Hello {{name}},',
        'email.common.greeting_anonymous' => 'Hello,',
        'email.common.ignore' => 'If you did not expect this e-mail, you can ignore it.',
        'email.common.footer' => 'Works with Open Brand Exchange',
        'email.common.link_hint' => 'If the button does not work, copy this link into your browser:',
        'email.invite.subject' => 'Your invitation to {{portal}}',
        'email.invite.body' => '{{brand}} invited you to {{portal}}. Choose a password to get started.',
        'email.invite.button' => 'Choose a password',
        'email.invite.expires' => 'This link works once and expires in {{days}} days.',
        'email.access_request.subject' => '{{retailer}} requested access to {{portal}}',
        'email.access_request.body' => '{{contact}} of {{retailer}} requested access to {{portal}}. Approve or reject the request in your admin.',
        'email.access_request.button' => 'Review the request',
        'email.access_approved.subject' => 'Your access to {{portal}} is approved',
        'email.access_approved.body' => '{{brand}} approved your request for {{retailer}}.',
        'email.access_approved.portal' => 'You will receive a separate e-mail with a link to choose your password.',
        'email.access_approved.api' => 'Your software can now collect its API key for your software from the request status link.',
        'email.access_approved.button' => 'Open {{portal}}',
        'email.access_rejected.subject' => 'Your request for {{portal}}',
        'email.access_rejected.body' => '{{brand}} did not approve your request for {{retailer}}.',
        'email.access_rejected.reason' => 'Reason: {{reason}}',
        'email.zip_ready.subject' => 'Your download from {{portal}} is ready',
        'email.zip_ready.body' => 'Your zip file with {{files}} images is ready.',
        'email.zip_ready.button' => 'Download',
        'email.zip_ready.expires' => 'The file is kept for {{days}} days.',
        'email.password_reset.subject' => 'Choose a new password for {{portal}}',
        'email.password_reset.body' => 'Someone asked to reset the password of your account. Choose a new password with the button below.',
        'email.password_reset.button' => 'Choose a new password',
        'email.password_reset.expires' => 'This link works once and expires in {{hours}} hour.',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $locales = [];

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

    public function t(string $key, string $lang, array $vars = []): string
    {
        $text = $this->lookup($this->locale($lang), $key)
            ?? $this->lookup($this->locale('en'), $key)
            ?? self::DEFAULTS[$key] ?? $key;
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{{' . $k . '}}'] = (string) $v;
            $repl['{{ ' . $k . ' }}'] = (string) $v;
            $repl['{' . $k . '}'] = (string) $v;
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

    /** "<Brand> · <local word>", the retailer portal name in $lang. */
    public function portalName(string $lang): string
    {
        $branding = $this->settings->all()['branding'];
        $word = Lang::pick(is_array($branding['portal_word'] ?? null) ? $branding['portal_word'] : null, $lang)
            ?? $this->lookup($this->locale($lang), 'portal.word')
            ?? Lang::PORTAL_WORD[$lang] ?? Lang::PORTAL_WORD['en'];
        return $this->settings->displayName() . ' · ' . $word;
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $paragraphs extra keys rendered as paragraphs after the body
     * @return array{subject: string, text: string, html: string}
     */
    public function render(string $template, string $lang, array $vars, ?string $buttonUrl = null, array $paragraphs = [], ?string $recipientName = null): array
    {
        $lang = Lang::ui($lang);
        $vars += ['portal' => $this->portalName($lang), 'brand' => $this->settings->displayName()];
        $subject = $this->t('email.' . $template . '.subject', $lang, $vars);
        $greeting = $recipientName !== null && $recipientName !== ''
            ? $this->t('email.common.greeting', $lang, ['name' => $recipientName])
            : $this->t('email.common.greeting_anonymous', $lang);
        $lines = [$this->t('email.' . $template . '.body', $lang, $vars)];
        foreach ($paragraphs as $p) {
            $lines[] = $this->t($p, $lang, $vars);
        }
        $button = $buttonUrl !== null ? $this->t('email.' . $template . '.button', $lang, $vars) : null;
        $footer = $this->t('email.common.footer', $lang);
        $text = $greeting . "\n\n" . implode("\n\n", $lines) . "\n\n";
        if ($buttonUrl !== null) {
            $text .= $button . ': ' . $buttonUrl . "\n\n";
        }
        $text .= $this->t('email.common.ignore', $lang) . "\n\n-- \n" . $vars['portal'] . "\n" . $footer . "\n";

        $accent = (string) ($this->settings->all()['branding']['accent_color'] ?? '') ?: '#2f5d62';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f4f4f2;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d1d1b">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px" cellpadding="0" cellspacing="0"><tr><td>'
            . '<p style="margin:0 0 24px;font-weight:600;font-size:15px;color:' . $e($accent) . '">' . $e($vars['portal']) . '</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.5">' . $e($greeting) . '</p>';
        foreach ($lines as $l) {
            $html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.5">' . nl2br($e($l)) . '</p>';
        }
        if ($buttonUrl !== null) {
            $html .= '<p style="margin:24px 0"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:' . $e($accent) . ';color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600">' . $e((string) $button) . '</a></p>'
                . '<p style="margin:0 0 16px;font-size:13px;color:#6b6b66">' . $e($this->t('email.common.link_hint', $lang)) . '<br><span style="word-break:break-all">' . $e($buttonUrl) . '</span></p>';
        }
        $html .= '<p style="margin:24px 0 0;font-size:13px;color:#6b6b66">' . $e($this->t('email.common.ignore', $lang)) . '</p>'
            . '</td></tr></table>'
            . '<p style="margin:16px 0 0;font-size:12px;color:#8a8a84">' . $e($footer) . '</p>'
            . '</td></tr></table></body></html>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }
}
