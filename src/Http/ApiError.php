<?php

declare(strict_types=1);

namespace Obx\Node\Http;

/**
 * An error that becomes an application/problem+json response with an obx_error code.
 */
final class ApiError extends \RuntimeException
{
    public const STATUS = [
        'invalid_request' => 400,
        'validation_failed' => 422,
        'unauthorized' => 401,
        'invalid_token' => 401,
        'access_revoked' => 401,
        'insufficient_scope' => 403,
        'forbidden' => 403,
        'not_found' => 404,
        'method_not_allowed' => 405,
        'conflict' => 409,
        'precondition_failed' => 412,
        'payload_too_large' => 413,
        'unsupported_media_type' => 415,
        'rate_limited' => 429,
        'limit_exceeded' => 400,
        'cursor_invalid' => 400,
        'cursor_expired' => 410,
        'export_expired' => 410,
        'original_not_allowed' => 403,
        'invalid_gtin' => 422,
        'gtin_conflict' => 409,
        'position_conflict' => 409,
        'checksum_mismatch' => 422,
        'blob_missing' => 422,
        'upload_incomplete' => 409,
        'unknown_parent' => 422,
        'files_missing' => 422,
        'import_invalid' => 422,
        'workbook_invalid' => 422,
        'workbook_unsafe' => 422,
        'workbook_sheet_missing' => 422,
        'workbook_too_large' => 422,
        'mass_unpublish_refused' => 409,
        'unsafe_url' => 422,
        'csrf_failed' => 403,
        'invalid_credentials' => 401,
        'account_blocked' => 403,
        'setup_required' => 409,
        'setup_completed' => 409,
        'invite_invalid' => 400,
        'not_implemented' => 501,
        'internal_error' => 500,
        'service_unavailable' => 503,
    ];

    private const TITLES = [
        'invalid_request' => 'Invalid request',
        'validation_failed' => 'Validation failed',
        'unauthorized' => 'Authentication required',
        'invalid_token' => 'Invalid or expired token',
        'access_revoked' => 'Access revoked',
        'insufficient_scope' => 'Insufficient scope',
        'forbidden' => 'Forbidden',
        'not_found' => 'Not found',
        'method_not_allowed' => 'Method not allowed',
        'conflict' => 'Conflict',
        'precondition_failed' => 'Precondition failed',
        'payload_too_large' => 'Payload too large',
        'unsupported_media_type' => 'Unsupported media type',
        'rate_limited' => 'Too many requests',
        'limit_exceeded' => 'Limit exceeded',
        'cursor_invalid' => 'Invalid cursor',
        'cursor_expired' => 'Cursor expired',
        'export_expired' => 'Export expired',
        'original_not_allowed' => 'Original not allowed',
        'invalid_gtin' => 'Invalid GTIN',
        'gtin_conflict' => 'GTIN conflict',
        'position_conflict' => 'Position conflict',
        'checksum_mismatch' => 'Checksum mismatch',
        'blob_missing' => 'Blob missing',
        'upload_incomplete' => 'Upload incomplete',
        'unknown_parent' => 'Unknown parent',
        'files_missing' => 'Files missing',
        'import_invalid' => 'Import invalid',
        'workbook_invalid' => 'Workbook cannot be read',
        'workbook_unsafe' => 'Workbook has macros or external links',
        'workbook_sheet_missing' => 'Workbook sheet missing',
        'workbook_too_large' => 'Workbook too large',
        'mass_unpublish_refused' => 'Mass unpublish refused',
        'unsafe_url' => 'Unsafe URL',
        'csrf_failed' => 'CSRF check failed',
        'invalid_credentials' => 'Invalid credentials',
        'account_blocked' => 'Account blocked',
        'setup_required' => 'Setup required',
        'setup_completed' => 'Setup already completed',
        'invite_invalid' => 'Invitation invalid',
        'not_implemented' => 'Not implemented',
        'internal_error' => 'Internal error',
        'service_unavailable' => 'Service unavailable',
    ];

    /** @var array<int, array{pointer: ?string, obx_error: string, detail: string}> */
    public array $errors;
    /** @var array<string, string> */
    public array $headers;
    public int $status;

    /**
     * @param array<int, array{pointer?: ?string, obx_error?: string, detail: string}> $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $obxError,
        string $detail = '',
        array $errors = [],
        ?int $status = null,
        array $headers = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($detail !== '' ? $detail : (self::TITLES[$obxError] ?? $obxError));
        $this->status = $status ?? (self::STATUS[$obxError] ?? 500);
        $this->errors = array_map(static fn (array $e): array => [
            'pointer' => $e['pointer'] ?? null,
            'obx_error' => $e['obx_error'] ?? $obxError,
            'detail' => $e['detail'],
        ], $errors);
        $this->headers = $headers;
    }

    public static function validation(string $pointer, string $detail, string $code = 'validation_failed'): self
    {
        return new self($code, $detail, [['pointer' => $pointer, 'obx_error' => $code, 'detail' => $detail]]);
    }

    public static function title(string $code): string
    {
        return self::TITLES[$code] ?? $code;
    }

    /** @return array<string, mixed> */
    public function problem(?string $instance = null): array
    {
        $p = [
            'type' => 'https://openbrandexchange.com/errors/' . $this->obxError,
            'title' => self::title($this->obxError),
            'status' => $this->status,
            'detail' => $this->getMessage(),
            'instance' => $instance,
            'obx_error' => $this->obxError,
        ];
        if ($this->errors !== []) {
            $p['errors'] = $this->errors;
        }
        if ($this->retryAfter !== null) {
            $p['retry_after'] = $this->retryAfter;
        }
        return $p;
    }
}
