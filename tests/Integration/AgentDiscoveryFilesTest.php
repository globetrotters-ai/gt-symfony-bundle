<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Client\FetchResult;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The four agent discovery files 0.7.0 serves at the apex: the root agent
 * specs, the ARD catalog and the Agent Skills index. Each is the published
 * copy, byte for byte, with the content type the Globetrotters bundle
 * builder gives it.
 */
final class AgentDiscoveryFilesTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function agentFiles(): iterable
    {
        yield 'agent.json' => ['agent.json', 'application/json; charset=utf-8'];
        yield 'AGENTS.md' => ['AGENTS.md', 'text/markdown; charset=utf-8'];
        yield 'ai-catalog.json' => ['.well-known/ai-catalog.json', 'application/json; charset=utf-8'];
        yield 'agent-skills index' => ['.well-known/agent-skills/index.json', 'application/json; charset=utf-8'];
    }

    #[DataProvider('agentFiles')]
    public function testServesThePublishedCopy(string $path, string $contentType): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        self::assertSame(0, $this->refresh()->getStatusCode());

        $client->request('GET', '/'.$path);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($contentType, $response->headers->get('Content-Type'));
        self::assertSame(static::BODIES[$path], $response->getContent());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    #[DataProvider('agentFiles')]
    public function testAMissingFileKeepsTheLastGoodBundle(string $path): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        // Same pull, validate, cache path as mcp.json: one file gone upstream
        // fails the whole refresh and the bundle already cached keeps serving.
        $this->fetcher()->on('/'.$path, FetchResult::http(404, ''));
        $this->refresh();

        $client->request('GET', '/llms.txt');
        self::assertSame(static::BODIES['llms.txt'], $client->getResponse()->getContent());
        $client->request('GET', '/'.$path);
        self::assertSame(static::BODIES[$path], $client->getResponse()->getContent());
    }

    public function testRobotsOmitsAgentmapWhileTheCatalogIsNotCached(): void
    {
        $client = $this->bootClient();
        // A bundle cached by 0.6.x, before the upgrade's first refresh.
        $files = static::BODIES;
        unset($files['.well-known/ai-catalog.json']);
        static::getContainer()->get(\Globetrotters\AiPresenceBundle\Cache\ArtefactCache::class)
            ->store($files, '2026-10-01-000000', time());

        $client->request('GET', '/robots.txt');
        $content = (string) $client->getResponse()->getContent();

        self::assertStringStartsWith(RobotsFilter::MARKER, $content);
        self::assertStringNotContainsString('Agentmap:', $content);
    }
}
