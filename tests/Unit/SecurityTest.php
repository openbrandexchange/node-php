<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Security\SafeHttp;
use Obx\Node\Services\Webhooks;
use Obx\Node\Support\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testWebhookSignatureMatchesTheSpecVector(): void
    {
        $v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/spec/examples/webhook-signature-vector.json'), true);
        $this->assertSame($v['header'], Webhooks::signature($v['secret'], $v['timestamp'], $v['raw_body']));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'loopback' => ['127.0.0.1', true],
            'private 10' => ['10.1.2.3', true],
            'private 172' => ['172.20.0.1', true],
            'private 192' => ['192.168.1.1', true],
            'metadata' => ['169.254.169.254', true],
            'cgnat' => ['100.64.0.1', true],
            'multicast' => ['224.0.0.1', true],
            'unspecified' => ['0.0.0.0', true],
            'v6 loopback' => ['::1', true],
            'v6 unique local' => ['fd00::1', true],
            'v6 link local' => ['fe80::1', true],
            'v4 mapped private' => ['::ffff:10.0.0.1', true],
            'public v4' => ['93.184.216.34', false],
            'public v6' => ['2a00:1450:4001:80b::200e', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testPrivateAddressDetection(string $ip, bool $private): void
    {
        $this->assertSame($private, SafeHttp::isPrivate($ip));
    }

    public function testUnsafeUrlsAreRefused(): void
    {
        $http = new SafeHttp(false);
        foreach (['https://127.0.0.1/x.csv', 'http://example.com/x', 'ftp://example.com/x', 'https://user:pw@example.com/', 'https://[::1]/'] as $url) {
            try {
                $http->check($url);
                $this->fail($url . ' should be unsafe');
            } catch (ApiError $e) {
                $this->assertSame('unsafe_url', $e->obxError, $url);
            }
        }
        $dev = new SafeHttp(true);
        $this->assertSame('127.0.0.1', $dev->check('http://127.0.0.1:8080/x')[2]);
        foreach (['https://169.254.169.254/latest/meta-data', 'https://[fe80::1]/hook', 'https://0.0.0.0/'] as $url) {
            try {
                $dev->check($url);
                $this->fail($url . ' must stay blocked in development mode');
            } catch (ApiError $e) {
                $this->assertSame('unsafe_url', $e->obxError);
            }
        }
    }

    public function testEncryptionSealingAndTokens(): void
    {
        $c = new Crypto('a-secret-key-that-is-long-enough-123456');
        $sealed = $c->encrypt('whsec_abc');
        $this->assertStringNotContainsString('whsec_abc', $sealed);
        $this->assertSame('whsec_abc', $c->decrypt($sealed));
        $other = new Crypto('another-secret-key-that-is-long-enough-12');
        $this->assertNull($other->decrypt($sealed));
        $token = $c->seal('cursor', ['p' => 'l', 's' => 5]);
        $this->assertSame(['p' => 'l', 's' => 5], $c->unseal('cursor', $token));
        $this->assertNull($other->unseal('cursor', $token), 'a cursor from another node is rejected');
        $this->assertNull($c->unseal('url', $token), 'purposes do not mix');
        $this->assertStringStartsWith('obxi_', Crypto::token('obxi_'));
        $this->assertNotSame(Crypto::token(), Crypto::token());
        $hash = Crypto::hashPassword('correct horse battery');
        $this->assertTrue(Crypto::verifyPassword('correct horse battery', $hash));
        $this->assertFalse(Crypto::verifyPassword('wrong', $hash));
        $this->assertFalse(Crypto::verifyPassword('anything', null));
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testLogsNeverContainSecrets(): void
    {
        $line = Log::scrub('token obxi_AbCdEf123 and Bearer xyz.abc url /x?exp=1&act=c:1&sig=SIGNATURE and whsec_supersecret');
        $this->assertStringNotContainsString('AbCdEf123', $line);
        $this->assertStringNotContainsString('xyz.abc', $line);
        $this->assertStringNotContainsString('SIGNATURE', $line);
        $this->assertStringNotContainsString('supersecret', $line);
    }
}
