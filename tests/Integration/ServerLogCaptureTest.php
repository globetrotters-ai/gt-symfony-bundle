<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Integration;

use Globetrotters\AiPresenceBundle\Analytics\Event;
use Globetrotters\AiPresenceBundle\Serving\ContentTypes;

/**
 * Capture end to end: a real kernel, a catch-all antagonist controller in
 * front, and the buffer read back off disk the way an out-of-process flush
 * would read it.
 */
final class ServerLogCaptureTest extends IntegrationTestCase
{
    protected static bool $withReporting = true;

    public function testCapturesOneEventPerServedArtefactRequest(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        foreach (ContentTypes::paths() as $path) {
            $client->request('GET', '/'.$path, server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
                'REMOTE_ADDR' => '160.79.104.10',
            ]);
        }

        $events = $this->buffer()->claim(50);
        self::assertCount(\count(ContentTypes::paths()), $events);

        self::assertSame(
            array_map(static fn (string $path): string => '/'.$path, ContentTypes::paths()),
            array_map(static fn (Event $event): string => $event->path(), $events),
        );

        $first = $events[0];
        self::assertSame(200, $first->status());
        self::assertSame(\strlen(static::BODIES['llms.txt']), $first->bytes());
        self::assertSame('160.79.104.10', $first->ip());
        self::assertStringContainsString('ClaudeBot/1.0', $first->ua());
        self::assertSame('', $first->referer());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first->ts());
    }

    public function testEventIdsAreUniquePerRequest(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        foreach (range(1, 5) as $ignored) {
            $client->request('GET', '/llms.txt');
        }

        $ids = array_map(static fn (Event $event): string => $event->id(), $this->buffer()->claim(50));

        self::assertCount(5, $ids);
        self::assertSame($ids, array_unique($ids), 'the id is the backend dedupe key');
    }

    public function testReportsTheCanonicalPathRatherThanTheRequestUri(): void
    {
        // The backend matches the six paths exactly and drops /llms.txt?v=2 at
        // ingest, so reporting the request URI would silently lose the hit.
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/llms.txt?v=2&utm_source=x');

        self::assertSame('/llms.txt', $this->buffer()->claim(1)[0]->path());
    }

    public function testRecordsTheRefererWhenTheAgentSendsOne(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('GET', '/ai.json', server: ['HTTP_REFERER' => 'https://example.test/from']);

        self::assertSame('https://example.test/from', $this->buffer()->claim(1)[0]->referer());
    }

    public function testCapturesNothingForPathsTheApplicationServed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        // The homepage in particular: it is deliberately out of scope, because
        // it is served by a page cache or the web server before the application
        // loads, so its completeness would depend on the customer's nginx.conf.
        $client->request('GET', '/llms-full.txt');
        self::assertSame('ANTAGONIST', $client->getResponse()->getContent());

        $client->request('GET', '/');
        self::assertStringContainsString('Homepage', (string) $client->getResponse()->getContent());

        self::assertSame(0, $this->buffer()->count());
    }

    public function testCapturesNothingOnAColdCache(): void
    {
        // No bundle pulled yet, so the app serves the path and there is no
        // artefact hit to report.
        $client = $this->bootClient();

        $client->request('GET', '/llms.txt');

        self::assertSame(0, $this->buffer()->count());
    }

    public function testAHeadRequestReportsTheArtefactSizeItWouldHaveServed(): void
    {
        $client = $this->bootClient();
        $this->serveRequiredFiles();
        $this->refresh();

        $client->request('HEAD', '/llms.txt');

        $event = $this->buffer()->claim(1)[0];
        self::assertSame(200, $event->status());
        self::assertSame(\strlen(static::BODIES['llms.txt']), $event->bytes());
    }
}
