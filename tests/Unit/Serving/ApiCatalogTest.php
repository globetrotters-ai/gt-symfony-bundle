<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\ApiCatalog;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ApiCatalogTest extends TestCase
{
    private const BASE_URL = 'https://acme.globetrotters.ai';

    /**
     * @param array<string, string> $files
     */
    private function catalog(array $files = []): ApiCatalog
    {
        $cache = new ArtefactCache(new ArrayAdapter(), self::BASE_URL);
        if ([] !== $files) {
            $cache->store($files, 'v1', 0);
        }

        return new ApiCatalog($cache);
    }

    /**
     * @return array<string, string>
     */
    private function fullSet(): array
    {
        return array_fill_keys(ContentTypes::paths(), '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testRendersAnRfc9727LinksetAnchoredOnTheRequestOrigin(): void
    {
        $body = $this->catalog($this->fullSet())->render('https://www.acme-guide.example');

        self::assertSame([
            'linkset' => [[
                'anchor' => 'https://www.acme-guide.example/',
                'service-doc' => [
                    ['href' => 'https://www.acme-guide.example/llms.txt', 'type' => 'text/plain'],
                ],
                'service-desc' => [
                    ['href' => 'https://www.acme-guide.example/.well-known/mcp.json', 'type' => 'application/json'],
                    ['href' => 'https://www.acme-guide.example/.well-known/agent-card.json', 'type' => 'application/json'],
                    ['href' => 'https://www.acme-guide.example/.well-known/ai-catalog.json', 'type' => 'application/json'],
                ],
            ]],
        ], $this->decode($body));
    }

    public function testNeverNamesTheGlobetrottersHost(): void
    {
        $body = $this->catalog($this->fullSet())->render('https://www.acme-guide.example');

        self::assertStringNotContainsString('globetrotters.ai', $body);
        // Slashes stay readable: the document is read by agents and people alike.
        self::assertStringNotContainsString('\/', $body);
    }

    public function testATrailingSlashOnTheOriginIsNotDoubled(): void
    {
        $linkset = $this->decode($this->catalog($this->fullSet())->render('https://apex.example/'));

        self::assertSame('https://apex.example/', $linkset['linkset'][0]['anchor']);
    }

    public function testListsOnlyTheTargetsTheCacheHolds(): void
    {
        $files = $this->fullSet();
        unset($files['.well-known/agent-card.json'], $files['.well-known/ai-catalog.json']);

        $linkset = $this->decode($this->catalog($files)->render('https://apex.example'));

        self::assertSame(
            [['href' => 'https://apex.example/.well-known/mcp.json', 'type' => 'application/json']],
            $linkset['linkset'][0]['service-desc'],
        );
    }

    public function testARelationWithNothingServedIsOmitted(): void
    {
        $linkset = $this->decode($this->catalog(['llms.txt' => 'llms'])->render('https://apex.example'));

        self::assertArrayHasKey('service-doc', $linkset['linkset'][0]);
        self::assertArrayNotHasKey('service-desc', $linkset['linkset'][0]);
    }

    public function testNothingIsRenderedWithoutATarget(): void
    {
        self::assertSame('', $this->catalog()->render('https://apex.example'));
        // Artefacts the catalog does not name do not make it servable.
        self::assertSame('', $this->catalog(['schema.json' => '{}'])->render('https://apex.example'));
    }

    public function testAnyOneTargetMakesItServable(): void
    {
        self::assertNotSame('', $this->catalog(['llms.txt' => 'llms'])->render('https://apex.example'));
        self::assertNotSame('', $this->catalog(['.well-known/mcp.json' => '{}'])->render('https://apex.example'));
    }

    public function testItemNamesTheMcpServerFromTheCachedCard(): void
    {
        $files = $this->fullSet();
        $files['.well-known/mcp.json'] = '{"name":"ai.globetrotters/acme-guide","remotes":[{"type":"streamable-http","url":"https://acme-guide.globetrotters.ai/mcp"},{"type":"sse","url":"https://acme-guide.globetrotters.ai/sse"}]}';

        $linkset = $this->decode($this->catalog($files)->render('https://www.acme-guide.example'));

        self::assertSame(
            [['href' => 'https://acme-guide.globetrotters.ai/mcp', 'type' => 'application/json']],
            $linkset['linkset'][0]['item'],
        );
        // service-doc and service-desc are untouched, and item comes last.
        self::assertSame(['anchor', 'service-doc', 'service-desc', 'item'], array_keys($linkset['linkset'][0]));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function noMcpRemote(): iterable
    {
        yield 'card not cached' => [['llms.txt' => 'llms']];
        yield 'no remotes' => [['.well-known/mcp.json' => '{"name":"x"}']];
        yield 'empty remotes' => [['.well-known/mcp.json' => '{"remotes":[]}']];
        yield 'remote without url' => [['.well-known/mcp.json' => '{"remotes":[{"type":"streamable-http"}]}']];
        yield 'empty url' => [['.well-known/mcp.json' => '{"remotes":[{"url":""}]}']];
        yield 'not an object' => [['.well-known/mcp.json' => '"mcp"']];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('noMcpRemote')]
    public function testItemIsOmittedWithoutAnMcpRemote(array $files): void
    {
        $linkset = $this->decode($this->catalog($files)->render('https://apex.example'));

        self::assertArrayNotHasKey('item', $linkset['linkset'][0]);
    }

    public function testEveryTargetIsAnArtefactThisBundleServes(): void
    {
        foreach (ApiCatalog::RELS as $path => [$rel]) {
            self::assertTrue(ContentTypes::has($path), $path);
            self::assertContains($rel, ['service-doc', 'service-desc'], $path);
        }
    }
}
