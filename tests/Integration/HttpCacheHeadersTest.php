<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use Globetrotters\AiPresenceBundle\Tests\Support\TempDirectory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\Esi;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The headers have to survive an application running Symfony's own reverse
 * proxy, and this is checked rather than assumed.
 *
 * ``Surrogate-Control`` is the one at risk: HttpCache consumes it for ESI/SSI.
 * It only does so when the value carries a ``content="…ESI/1.0…"`` capability
 * token (``AbstractSurrogate::needsParsing()``), so a plain ``no-store`` is not
 * touched and reaches an upstream CDN intact — but if that ever changed, apex
 * hit counts would silently go low behind every customer's edge, which is
 * exactly the failure this feature exists to remove.
 */
final class HttpCacheHeadersTest extends IntegrationTestCase
{
    private ?string $storeDir = null;

    protected function tearDown(): void
    {
        TempDirectory::remove($this->storeDir);
        $this->storeDir = null;

        parent::tearDown();
    }

    public function testBothNoStoreHeadersSurviveSymfonysReverseProxy(): void
    {
        $cache = $this->httpCache();

        foreach (ContentTypes::paths() as $path) {
            $response = $cache->handle(Request::create('/'.$path), HttpKernelInterface::MAIN_REQUEST, false);

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'), $path);
            self::assertSame('no-store', $response->headers->get('Surrogate-Control'), $path);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
            self::assertSame(ContentTypes::forPath($path), $response->headers->get('Content-Type'), $path);
        }
    }

    public function testTheProxyStoresNothingSoTheApplicationAlwaysRuns(): void
    {
        // A shared TTL in front of the origin is the failure mode: the
        // application never executes, so nothing is captured and the count is
        // silently low by an amount nobody can measure.
        $store = new Store($this->storeDir());
        $cache = $this->httpCache($store);
        $request = Request::create('/llms.txt');

        $cache->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertNull($store->lookup($request));

        $second = $cache->handle(Request::create('/llms.txt'), HttpKernelInterface::MAIN_REQUEST, false);
        self::assertSame(static::BODIES['llms.txt'], $second->getContent());
    }

    private function httpCache(?Store $store = null): HttpCache
    {
        $client = $this->bootClient();
        $client->disableReboot();
        $this->serveRequiredFiles();
        $this->refresh();

        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        return new HttpCache($kernel, $store ?? new Store($this->storeDir()), new Esi());
    }

    private function storeDir(): string
    {
        return $this->storeDir ??= TempDirectory::make('gtaip-httpcache');
    }
}
