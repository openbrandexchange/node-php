<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Http\ApiError;
use Obx\Node\Http\Multipart;
use PHPUnit\Framework\TestCase;

final class MultipartTest extends TestCase
{
    public function testRepeatedFilesAndFieldsSurvive(): void
    {
        $b = 'XyZ';
        $big = random_bytes(3 * 1024 * 1024 + 17);
        $body = "preamble\r\n--$b\r\nContent-Disposition: form-data; name=\"images\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\nAAA\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"images\"; filename=\"b.png\"\r\n\r\n" . $big . "\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"options\"\r\n\r\n{\"dry_run\":true}\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"csv\"; filename*=UTF-8''caf%C3%A9.csv\r\n\r\na,b\r\n1,2\r\n--$b--\r\n";
        $in = fopen('php://memory', 'w+b');
        fwrite($in, $body);
        rewind($in);
        [$fields, $files] = Multipart::parse($in, 'multipart/form-data; boundary="' . $b . '"', sys_get_temp_dir() . '/obx-mp-test', 100 * 1024 * 1024);
        $this->assertSame(['options' => '{"dry_run":true}'], $fields);
        $this->assertSame(['a.png', 'b.png'], array_column($files['images'], 'name'));
        $this->assertSame('AAA', file_get_contents($files['images'][0]['tmp']));
        $this->assertSame($big, file_get_contents($files['images'][1]['tmp']));
        $this->assertSame('image/png', $files['images'][0]['type']);
        $this->assertSame('café.csv', $files['csv'][0]['name']);
        $this->assertSame("a,b\r\n1,2", file_get_contents($files['csv'][0]['tmp']));
        foreach ($files as $list) {
            foreach ($list as $f) {
                unlink($f['tmp']);
            }
        }
    }

    /** A part without a file name is a field, except an Excel workbook (import-format.md §5.1). */
    public function testAWorkbookPartWithoutAFileNameIsAFile(): void
    {
        $b = 'b';
        $body = "--$b\r\nContent-Disposition: form-data; name=\"csv\"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\nPK\x03\x04data\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"assets_csv\"\r\nContent-Type: text/csv\r\n\r\nfile\r\n--$b--\r\n";
        $in = fopen('php://memory', 'w+b');
        fwrite($in, $body);
        rewind($in);
        [$fields, $files] = Multipart::parse($in, 'multipart/form-data; boundary=' . $b, sys_get_temp_dir() . '/obx-mp-test', 100000);
        $this->assertSame(['assets_csv' => 'file'], $fields);
        $this->assertSame('', $files['csv'][0]['name']);
        $this->assertSame("PK\x03\x04data", file_get_contents($files['csv'][0]['tmp']));
        unlink($files['csv'][0]['tmp']);
    }

    public function testLimitsAndMalformedBodies(): void
    {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, "--b\r\nContent-Disposition: form-data; name=\"x\"; filename=\"x\"\r\n\r\n" . str_repeat('a', 5000) . "\r\n--b--\r\n");
        rewind($in);
        try {
            Multipart::parse($in, 'multipart/form-data; boundary=b', sys_get_temp_dir(), 1000);
            $this->fail('too large');
        } catch (ApiError $e) {
            $this->assertSame('payload_too_large', $e->obxError);
        }
        $in = fopen('php://memory', 'w+b');
        fwrite($in, "--b\r\nContent-Disposition: form-data; name=\"x\"\r\n\r\nunterminated");
        rewind($in);
        $this->expectException(ApiError::class);
        Multipart::parse($in, 'multipart/form-data; boundary=b', sys_get_temp_dir(), 100000);
    }
}
