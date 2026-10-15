<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\ApiCatalog;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ApiCatalogTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

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
        $body = $this->catalog($this->fullSet())->render('https://www.provenceguide.com');

        self::assertSame([
            'linkset' => [[
                'anchor' => 'https://www.provenceguide.com/',
                'service-doc' => [
                    ['href' => 'https://www.provenceguide.com/llms.txt', 'type' => 'text/plain'],
                ],
                'service-desc' => [
                    ['href' => 'https://www.provenceguide.com/.well-known/mcp.json', 'type' => 'application/json'],
                    ['href' => 'https://www.provenceguide.com/.well-known/agent-card.json', 'type' => 'application/json'],
                    ['href' => 'https://www.provenceguide.com/.well-known/ai-catalog.json', 'type' => 'application/json'],
                ],
            ]],
        ], $this->decode($body));
    }

    public function testNeverNamesTheGlobetrottersHost(): void
    {
        $body = $this->catalog($this->fullSet())->render('https://www.provenceguide.com');

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

    public function testEveryTargetIsAnArtefactThisBundleServes(): void
    {
        foreach (ApiCatalog::RELS as $path => [$rel]) {
            self::assertTrue(ContentTypes::has($path), $path);
            self::assertContains($rel, ['service-doc', 'service-desc'], $path);
        }
    }
}
