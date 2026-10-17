<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Serving\Router;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The discovery-file aliases 0.9.1 answers: ``/agents.md`` serves the cached
 * ``AGENTS.md`` and ``/.well-known/agent-skills`` (with or without its trailing
 * slash) the cached Agent Skills index — same bytes, same headers, no
 * redirect — and a hit is reported under the path actually requested.
 */
final class DiscoveryAliasesTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function aliases(): iterable
    {
        yield 'agents.md' => ['/agents.md', 'AGENTS.md', 'text/markdown; charset=utf-8'];
        yield 'agent-skills' => ['/.well-known/agent-skills', '.well-known/agent-skills/index.json', 'application/json; charset=utf-8'];
        yield 'agent-skills/' => ['/.well-known/agent-skills/', '.well-known/agent-skills/index.json', 'application/json; charset=utf-8'];
    }

    #[DataProvider('aliases')]
    public function testServesTheArtefactItAliases(string $alias, string $artefact, string $contentType): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/'.$artefact);
        $canonical = $client->getResponse()->headers->all();
        $client->request('GET', $alias);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($contentType, $response->headers->get('Content-Type'));
        self::assertSame(static::BODIES[$artefact], $response->getContent());
        unset($canonical['date']);
        $served = $response->headers->all();
        unset($served['date']);
        self::assertSame($canonical, $served);
    }

    #[DataProvider('aliases')]
    public function testReportsTheRequestedPath(string $alias, string $artefact): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', $alias, server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        ]);

        $events = $this->buffer()->claim(10);
        self::assertSame([$alias], array_map(static fn (Event $event): string => $event->path(), $events));
        self::assertSame(\strlen(static::BODIES[$artefact]), $events[0]->bytes());
    }

    #[DataProvider('aliases')]
    public function testTheApplicationAnswersWhenNoBundleIsCached(string $alias): void
    {
        $client = $this->bootClient();

        $client->request('GET', $alias);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertFalse($client->getRequest()->attributes->has(Router::ATTRIBUTE_PATH));
    }

    public function testNoAliasIsListed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        foreach (['/ai-sitemap.xml', '/.well-known/api-catalog', '/'] as $path) {
            $client->request('GET', 'http://apex.example'.$path);
            $response = $client->getResponse();
            $listed = $response->getContent().implode("\n", $response->headers->all('link'));

            self::assertStringNotContainsString('/agents.md', $listed, $path);
            self::assertDoesNotMatchRegularExpression('#/\.well-known/agent-skills(?!/index\.json)#', $listed, $path);
        }
    }
}
