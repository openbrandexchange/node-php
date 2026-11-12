<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Catalog\ArticleData;
use Obx\Node\Catalog\Viewer;
use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Import\Csv;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;
use Obx\Node\Support\Str;
use Obx\Node\Zip\ZipWriter;

/**
 * Portal downloads: zips of images (streamed or built in the background), article data CSV.
 */
final class Downloads
{
    public const SYNC_ZIP_MAX = 400;
    public const KEEP_DAYS = 7;

    public function __construct(private App $app)
    {
    }

    /** Picks the rendition for a download variant; null when the asset lacks it. */
    public static function renditionFor(string $variant, Viewer $v): string
    {
        if ($variant === 'original' && !$v->originalAllowed) {
            throw new ApiError('original_not_allowed', 'Original files are not available to you; choose web or square.');
        }
        return $variant;
    }

    public static function defaultVariant(?string $requested, Viewer $v): string
    {
        if ($requested === null || $requested === '') {
            return $v->originalAllowed ? 'original' : 'web';
        }
        if (!in_array($requested, ['original', 'web', 'square'], true)) {
            throw ApiError::validation('/variant', 'original, web or square.');
        }
        return self::renditionFor($requested, $v);
    }

    /**
     * Visible assets of the given products, ordered for zips.
     *
     * @param list<string> $productIds
     * @return list<array<string, mixed>> asset rows joined with style_number
     */
    public function assetsOf(array $productIds, Viewer $v): array
    {
        if ($productIds === []) {
            return [];
        }
        $brandIds = $this->app->reader->brandIds($v);
        if ($brandIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            $rows = $this->app->db->all(
                'SELECT a.*, p.style_number AS style_number FROM obx_assets a JOIN obx_products p ON p.id = a.product_id
                 WHERE a.visible = 1 AND p.visible = 1 AND (a.expires_ts IS NULL OR a.expires_ts > ?) AND a.product_id IN (' . Db::in($chunk) . ')
                 AND p.brand_id IN (' . Db::in($brandIds) . ')',
                array_merge([Clock::time()], $chunk, $brandIds)
            );
            foreach ($rows as $r) {
                $out[] = $r;
            }
        }
        $order = array_flip($productIds);
        usort($out, static fn ($a, $b) => ($order[$a['product_id']] ?? 0) <=> ($order[$b['product_id']] ?? 0) ?: \Obx\Node\Catalog\Writer::assetOrderCmp($a, $b));
        return $out;
    }

    /**
     * File path, name and size of one asset's rendition.
     *
     * @param array<string, mixed> $asset
     * @return array{path: string, name: string, bytes: int, sha: string, type: string}
     */
    public function file(array $asset, string $rendition): array
    {
        $rep = Json::decode((string) $asset['rep'], []);
        $r = $rep['renditions'][$rendition] ?? null;
        if ($r === null) {
            throw new ApiError('not_found', 'This file is not available.');
        }
        $path = $this->app->blobs->path((string) $r['sha256']);
        if ($path === null) {
            throw new ApiError('not_found', 'This file is not available.');
        }
        return [
            'path' => $path,
            'name' => Str::renditionFilename((string) $rep['filename'], $rendition),
            'bytes' => (int) $r['bytes'],
            'sha' => (string) $r['sha256'],
            'type' => (string) $r['content_type'],
        ];
    }

    /**
     * Streams a zip of the given assets. $folders puts files in <style_number>/.
     *
     * @param list<array<string, mixed>> $assets
     * @param callable(ZipWriter): void|null $extra adds more entries (e.g. data.csv)
     */
    public function zipResponse(array $assets, string $rendition, bool $folders, string $filename, ?callable $extra = null): \Obx\Node\Http\Response
    {
        $files = [];
        foreach ($assets as $a) {
            $f = $this->file($a, $rendition);
            $files[] = [($folders ? Str::safeFilename((string) $a['style_number']) . '/' : '') . $f['name'], $f['path'], $a];
        }
        $res = new \Obx\Node\Http\Response(200);
        $res->header('Content-Type', 'application/zip');
        $res->header('Content-Disposition', Str::contentDisposition('attachment', $filename));
        $res->header('Cache-Control', 'private, no-store');
        $res->stream = function () use ($files, $extra): void {
            $out = fopen('php://output', 'wb');
            $zip = new ZipWriter($out);
            $entries = [];
            foreach ($files as [$name, $path, $a]) {
                $unique = $zip->uniqueName($name);
                $zip->addFile($unique, $path);
                $entries[] = [$unique, $a];
            }
            if ($entries !== []) {
                $zip->addString('assets.csv', $this->assetsCsv($entries));
            }
            if ($extra !== null) {
                $extra($zip);
            }
            $zip->finish();
            fclose($out);
        };
        return $res;
    }

    /**
     * The assets.csv of a portal download (import-format.md §2.3): one row per image in the zip
     * with its effective rights, embargo and AI provenance in the import format's columns, so they
     * travel with the files. Cells are formula-escaped like the article data CSV.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $entries name in the zip, asset row
     */
    public function assetsCsv(array $entries): string
    {
        $langs = [];
        $reps = [];
        foreach ($entries as [$name, $a]) {
            $rep = Json::decode((string) $a['rep'], []);
            foreach (array_keys((array) ($rep['alt_text'] ?? [])) as $l) {
                $langs[(string) $l] = true;
            }
            $reps[] = [$name, $rep, (string) $a['style_number']];
        }
        ksort($langs, SORT_STRING);
        $langs = array_keys($langs);
        $header = ['file', 'style_number', 'color_code', 'position', 'role', 'view'];
        foreach ($langs as $l) {
            $header[] = 'alt_text:' . $l;
        }
        array_push($header, 'license', 'license_text', 'channels', 'territories', 'embargo_until', 'expires_at', 'credit', 'model_release', 'ai_generated', 'ai_edited', 'source', 'c2pa', 'generator');
        $bool = static fn (mixed $b): string => $b === null ? '' : ((bool) $b ? 'true' : 'false');
        $out = Csv::line($header, ',');
        foreach ($reps as [$name, $rep, $style]) {
            $r = (array) ($rep['rights'] ?? []);
            $p = (array) ($rep['provenance'] ?? []);
            $cells = [basename($name), $style, (string) ($rep['color_code'] ?? ''), (string) ($rep['position'] ?? ''), (string) ($rep['role'] ?? ''), (string) ($rep['view'] ?? '')];
            foreach ($langs as $l) {
                $cells[] = (string) ($rep['alt_text'][$l] ?? '');
            }
            array_push(
                $cells,
                (string) ($r['license'] ?? ''),
                (string) ($r['license_text'] ?? ''),
                implode('|', (array) ($r['channels'] ?? [])),
                implode('|', (array) ($r['territories'] ?? [])),
                (string) ($r['embargo_until'] ?? ''),
                (string) ($r['expires_at'] ?? ''),
                (string) ($r['credit'] ?? ''),
                $bool($r['model_release'] ?? null),
                $bool($p['ai_generated'] ?? false),
                $bool($p['ai_edited'] ?? false),
                (string) ($p['source'] ?? ''),
                (string) ($p['c2pa'] ?? ''),
                (string) ($p['generator'] ?? '')
            );
            $out .= Csv::line($cells, ',');
        }
        return $out;
    }

    // ================================================================ CSV

    /**
     * Canonical CSV (import columns + image_files) of the given visible products.
     *
     * @param list<string> $productIds
     */
    public function csv(array $productIds, Viewer $v, string $delimiter): string
    {
        $langs = $this->app->settings->languages();
        $rows = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach ($this->app->db->all('SELECT p.*, b.name AS brand_name FROM obx_products p JOIN obx_brands b ON b.id = p.brand_id WHERE p.id IN (' . Db::in($chunk) . ')', $chunk) as $r) {
                $rows[(string) $r['id']] = $r;
            }
        }
        $details = [];
        $ordered = [];
        foreach ($productIds as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
            }
        }
        foreach ($this->app->reader->details($ordered, $v) as $d) {
            $details[] = $d;
        }
        $attrKeys = [];
        $otherFeatures = [];
        foreach ($details as $d) {
            foreach (array_keys((array) ($d['attributes'] ?? [])) as $k) {
                $attrKeys[$k] = true;
            }
            foreach (array_keys((array) ($d['features'] ?? [])) as $k) {
                if (!isset(ArticleData::FEATURES[$k])) {
                    $otherFeatures[$k] = true;
                }
            }
        }
        ksort($attrKeys);
        ksort($otherFeatures, SORT_STRING);
        // Columns of import-format.md §1.2 in its order: a feature column for every registry key
        // and for every other key the products have (sorted), attr:<key>, then image_files.
        $featureKeys = array_merge(array_keys(ArticleData::FEATURES), array_map('strval', array_keys($otherFeatures)));
        $header = ['brand', 'product_id', 'style_number'];
        foreach ($langs as $l) {
            $header[] = 'name:' . $l;
        }
        foreach ($langs as $l) {
            $header[] = 'description:' . $l;
        }
        array_push($header, 'item_type', 'item_subtype', 'target_group', 'season', 'collection', 'gpc_brick', 'materials',
            'contains_animal_origin', 'footwear_materials', 'care', 'country_of_origin', 'certifications', 'dpp_url', 'status');
        foreach ($featureKeys as $k) {
            $header[] = 'feature:' . $k;
        }
        foreach (array_keys($attrKeys) as $k) {
            $header[] = 'attr:' . $k;
        }
        array_push($header, 'variant_id', 'gtin', 'sku', 'color_code');
        foreach ($langs as $l) {
            $header[] = 'color_name:' . $l;
        }
        array_push($header, 'color_family', 'supplier_color', 'size', 'size_length', 'size_system', ...ArticleData::MEASUREMENTS);
        array_push($header, 'variant_status', 'image_files');
        $variantColumns = 4 + count($langs) + 5 + count(ArticleData::MEASUREMENTS) + 1;
        $out = "\xEF\xBB\xBF" . Csv::line($header, $delimiter);
        foreach ($details as $d) {
            $row = $rows[$d['id']];
            $base = [(string) $row['brand_name'], (string) $d['external_id'], (string) $d['style_number']];
            foreach ($langs as $l) {
                $base[] = $d['name'][$l] ?? '';
            }
            foreach ($langs as $l) {
                $base[] = $d['description'][$l] ?? '';
            }
            $animal = $d['contains_animal_origin'] ?? null;
            array_push(
                $base,
                (string) ($d['item_type'] ?? ''),
                (string) ($d['item_subtype'] ?? ''),
                (string) ($d['target_group'] ?? ''),
                (string) ($d['season'] ?? ''),
                (string) ($d['collection'] ?? ''),
                (string) ($d['gpc_brick'] ?? ''),
                self::materialsText($d['materials'] ?? null),
                $animal === null ? '' : ($animal ? 'yes' : 'no'),
                ArticleData::formatFootwear($d['footwear_materials'] ?? null),
                implode('|', array_map(static fn ($c) => $c['code'], (array) ($d['care'] ?? []))),
                (string) ($d['country_of_origin'] ?? ''),
                ArticleData::formatCertifications($d['certifications'] ?? null),
                (string) ($d['dpp_url'] ?? ''),
                'published',
            );
            foreach ($featureKeys as $k) {
                $base[] = ArticleData::formatFeature($d['features'][$k] ?? null);
            }
            foreach (array_keys($attrKeys) as $k) {
                $val = $d['attributes'][$k] ?? '';
                $base[] = is_array($val) ? (string) (\Obx\Node\Support\Lang::pick($val, $langs[0]) ?? '') : (is_bool($val) ? ($val ? 'true' : 'false') : (string) $val);
            }
            $imagesByColor = [];
            foreach ($d['assets'] as $a) {
                $imagesByColor[(string) ($a['color_code'] ?? '')][] = $a['filename'];
            }
            if ($d['variants'] === []) {
                $out .= Csv::line(array_merge($base, array_fill(0, $variantColumns, ''), [implode('|', array_merge($imagesByColor[''] ?? []))]), $delimiter);
                continue;
            }
            foreach ($d['variants'] as $var) {
                $cells = $base;
                $cells[] = (string) $var['external_id'];
                $cells[] = (string) ($var['gtin_original'] ?? '');
                $cells[] = (string) ($var['sku'] ?? '');
                $cells[] = (string) $var['color']['code'];
                foreach ($langs as $l) {
                    $cells[] = $var['color']['name'][$l] ?? '';
                }
                $cells[] = (string) ($var['color']['family'] ?? '');
                $cells[] = (string) ($var['color']['supplier_color'] ?? '');
                $cells[] = (string) ($var['size'] ?? '');
                $cells[] = (string) ($var['size_length'] ?? '');
                $cells[] = (string) ($var['size_system'] ?? '');
                foreach (ArticleData::MEASUREMENTS as $k) {
                    $cells[] = ArticleData::formatMeasurement($var['measurements'] ?? null, $k);
                }
                $cells[] = 'published';
                $cells[] = implode('|', array_merge($imagesByColor[(string) $var['color']['code']] ?? [], $imagesByColor[''] ?? []));
                $out .= Csv::line($cells, $delimiter);
            }
        }
        return $out;
    }

    /**
     * The materials grammar of import-format.md §1.4, with the fibre flags "(recycled)" and
     * "(organic)".
     *
     * @param list<array<string, mixed>>|null $materials
     */
    public static function materialsText(?array $materials): string
    {
        return ArticleData::formatMaterials($materials);
    }

    // ================================================================ background jobs

    /** @param array<string, mixed> $d @return array<string, mixed> DownloadJob */
    public function json(array $d): array
    {
        $status = $d['status'];
        if ($status === 'succeeded' && $d['expires_ts'] !== null && (int) $d['expires_ts'] < Clock::time()) {
            $status = 'expired';
        }
        return [
            'id' => $d['id'],
            'status' => $status,
            'source' => $d['source'],
            'variant' => $d['variant'],
            'include_data_csv' => (bool) $d['include_csv'],
            'created_at' => $d['created_at'],
            'finished_at' => $d['finished_at'],
            'expires_at' => $d['expires_at'],
            'progress' => ['done' => (int) $d['progress_done'], 'total' => $d['progress_total'] !== null ? (int) $d['progress_total'] : null],
            'file_url' => $status === 'succeeded' ? $this->app->baseUrl() . '/admin/api/portal/download-jobs/' . $d['id'] . '/file' : null,
            'bytes' => $d['bytes'] !== null ? (int) $d['bytes'] : null,
            'files' => $d['files'] !== null ? (int) $d['files'] : null,
            'error' => $d['error'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string> $productIds resolved, visible product ids
     * @return array<string, mixed> DownloadJob
     */
    public function createJob(array $user, ?string $retailerId, string $source, string $variant, bool $includeCsv, array $productIds): array
    {
        $running = (int) $this->app->db->value("SELECT COUNT(*) FROM obx_download_jobs WHERE user_id = ? AND status IN ('queued', 'running')", [$user['id']]);
        if ($running >= 2) {
            throw new ApiError('conflict', 'You already have two downloads being prepared. Wait until one is ready.');
        }
        $row = [
            'id' => Ids::new('dlj'),
            'user_id' => $user['id'],
            'retailer_id' => $retailerId,
            'status' => 'queued',
            'source' => $source,
            'variant' => $variant,
            'include_csv' => $includeCsv ? 1 : 0,
            'payload' => Json::encode(['product_ids' => $productIds]),
            'progress_done' => 0,
            'progress_total' => count($productIds),
            'bytes' => null,
            'files' => null,
            'error' => null,
            'created_at' => Clock::iso(),
            'created_ts' => Clock::time(),
            'finished_at' => null,
            'expires_at' => null,
            'expires_ts' => null,
        ];
        $this->app->db->insert('obx_download_jobs', $row);
        $this->app->jobs->create('download', 'user', (string) $user['id'], ['download_id' => $row['id']]);
        return $this->json($row);
    }

    public function path(string $id): string
    {
        return $this->app->config->dataDir() . '/downloads/' . $id . '.zip';
    }

    /** Viewer for the owner of a download job (re-checked when the job runs). */
    private function viewerFor(array $d): Viewer
    {
        $user = $this->app->db->one("SELECT * FROM obx_users WHERE id = ? AND status = 'active'", [$d['user_id']]);
        if ($user === null) {
            throw new ApiError('forbidden', 'The account no longer exists.');
        }
        if ($user['role'] === 'admin') {
            return Viewer::preview(true);
        }
        $retailer = $this->app->db->one("SELECT * FROM obx_retailers WHERE id = ? AND status = 'active'", [$user['retailer_id']]);
        if ($retailer === null) {
            throw new ApiError('forbidden', 'Access was revoked.');
        }
        return $this->app->grants->portalViewer($retailer, (string) $user['id'], (string) $user['name']);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     */
    public function build(array $job, array $payload): ?array
    {
        $id = (string) $payload['download_id'];
        $d = $this->app->db->one('SELECT * FROM obx_download_jobs WHERE id = ?', [$id]);
        if ($d === null) {
            return null;
        }
        $this->app->db->update('obx_download_jobs', ['status' => 'running'], ['id' => $id]);
        $v = $this->viewerFor($d);
        $variant = (string) $d['variant'];
        if ($variant === 'original' && !$v->originalAllowed) {
            throw new ApiError('original_not_allowed', 'Original files are no longer available to you.');
        }
        $productIds = (array) (Json::decode((string) $d['payload'], [])['product_ids'] ?? []);
        $dir = dirname($this->path($id));
        @mkdir($dir, 0770, true);
        $tmp = $this->path($id) . '.part';
        $fh = fopen($tmp, 'wb');
        $zip = new ZipWriter($fh);
        $files = 0;
        $entries = [];
        foreach (array_chunk($productIds, 50) as $n => $chunk) {
            foreach ($this->assetsOf($chunk, $v) as $a) {
                try {
                    $f = $this->file($a, $variant);
                } catch (ApiError) {
                    continue;
                }
                $unique = $zip->uniqueName(Str::safeFilename((string) $a['style_number']) . '/' . $f['name']);
                $zip->addFile($unique, $f['path']);
                $entries[] = [$unique, $a];
                $files++;
            }
            $this->app->db->update('obx_download_jobs', ['progress_done' => min(count($productIds), ($n + 1) * 50)], ['id' => $id]);
            $this->app->jobs->progress((string) $job['id'], min(count($productIds), ($n + 1) * 50), count($productIds));
        }
        if ($entries !== []) {
            $zip->addString('assets.csv', $this->assetsCsv($entries));
        }
        if ((int) $d['include_csv'] === 1 && ($v->preview || $this->app->settings->get('portal', 'allow_article_data_export'))) {
            $zip->addString('data.csv', $this->csv($productIds, $v, ','));
        }
        $zip->finish();
        fclose($fh);
        rename($tmp, $this->path($id));
        $expires = Clock::time() + self::KEEP_DAYS * 86400;
        $this->app->db->update('obx_download_jobs', [
            'status' => 'succeeded',
            'finished_at' => Clock::iso(),
            'expires_at' => Clock::iso((float) $expires),
            'expires_ts' => $expires,
            'bytes' => (int) filesize($this->path($id)),
            'files' => $files,
            'progress_done' => count($productIds),
        ], ['id' => $id]);
        $user = $this->app->db->one('SELECT * FROM obx_users WHERE id = ?', [$d['user_id']]);
        if ($user !== null) {
            $lang = (string) $user['language'];
            $mail = $this->app->emails->render('zipReady', $lang, ['files' => $files, 'expires' => \Obx\Node\Mail\Emails::date($expires, $lang)], $this->app->publicUrl() . '/downloads', [], ['expiry'], (string) $user['name']);
            $this->app->mailer->send((string) $user['email'], $mail['subject'], $mail['text'], $mail['html']);
        }
        return ['files' => $files];
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $problem */
    public function fail(array $job, array $problem): void
    {
        $payload = Json::decode((string) $job['payload'], []);
        $id = (string) ($payload['download_id'] ?? '');
        @unlink($this->path($id) . '.part');
        $this->app->db->update('obx_download_jobs', ['status' => 'failed', 'finished_at' => Clock::iso(), 'error' => (string) ($problem['detail'] ?? 'The download could not be prepared.')], ['id' => $id]);
    }

    public function expire(): void
    {
        foreach ($this->app->db->all("SELECT id FROM obx_download_jobs WHERE status = 'succeeded' AND expires_ts < ?", [Clock::time()]) as $d) {
            @unlink($this->path((string) $d['id']));
            $this->app->db->update('obx_download_jobs', ['status' => 'expired'], ['id' => $d['id']]);
        }
        $this->app->db->run('DELETE FROM obx_download_jobs WHERE created_ts < ?', [Clock::time() - 30 * 86400]);
    }
}
