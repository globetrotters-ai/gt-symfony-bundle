<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Unit\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Serving\RobotsFilter;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Settings\RobotsOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RobotsFilterTest extends TestCase
{
    private const BASE_URL = 'https://nantes.globetrotters.ai';

    /** The origin Request::create() gives a path-only URI — this site, not Globetrotters. */
    private const REQUEST_ORIGIN = 'http://localhost';

    private function filter(bool $connected = true, bool $cached = true): RobotsFilter
    {
        $pool = new ArrayAdapter();
        $cache = new ArtefactCache($pool);
        if ($cached) {
            $cache->store(['llms.txt' => 'x'], 'v1', 0);
        }

        return new RobotsFilter(new Options($pool, $connected ? self::BASE_URL : '', 'daily', '/'), $cache);
    }

    private function responseEvent(RobotsFilter $filter, string $uri, Response $response): Response
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $filter->onKernelResponse($event);

        return $event->getResponse();
    }

    private function exceptionEvent(RobotsFilter $filter, \Throwable $throwable, string $uri = '/robots.txt', string $method = 'GET'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, $method),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        $filter->onKernelException($event);

        return $event;
    }

    /**
     * The canonical copy is generated from the backend registry, not typed:
     *
     *     cd gt-backend/libs/globetrotters-business && uv run python -c \
     *       "from globetrotters.business.presence.services.ai_user_agents \
     *        import render_robots_groups; print(render_robots_groups(), end='')"
     *
     * Regenerate the fixture that way when the registry gains a name; this
     * assertion is what turns a drifting hand-typed copy into a red build.
     */
    public function testAiUserAgentGroupsMatchTheCanonicalFixture(): void
    {
        self::assertSame(self::canonicalFixture(), RobotsFilter::aiUserAgentGroups(aiTrain: true));
    }

    /**
     * With both opt-ins and no rules of the site's own to inherit, the block
     * is exactly what the backend registry renders.
     */
    public function testBothOptInsReproduceTheRegistryBlock(): void
    {
        $expected = RobotsFilter::MARKER."\n".self::canonicalFixture().'Sitemap: '.self::REQUEST_ORIGIN."/ai-sitemap.xml\n";

        self::assertSame($expected, RobotsFilter::buildBlock(self::REQUEST_ORIGIN, '', new RobotsOptions(RobotsOptions::ALLOW_ALL, true)));
    }

    public function testEveryNamedGroupCarriesItsOwnContentSignal(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN);

        // A crawler obeys only its own most-specific group (RFC 9309 §2.2.1),
        // so one signal line per group is the only placement that reaches
        // every named agent.
        self::assertSame(substr_count($block, 'User-agent: '), substr_count($block, 'Content-Signal: '));
    }

    public function testBuildBlockIsTheMarkerTheGroupsAndTheSitemap(): void
    {
        $expected = RobotsFilter::MARKER."\n"
            .RobotsFilter::aiUserAgentGroups(aiTrain: false)
            .'Sitemap: '.self::REQUEST_ORIGIN."/ai-sitemap.xml\n";

        self::assertSame($expected, RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    /**
     * Training is a statement about the customer's whole site, not about the
     * presence; the block never makes it on their behalf.
     */
    public function testTrainingIsNeverSignalledUnlessOptedInto(): void
    {
        self::assertStringNotContainsString('ai-train', RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
        self::assertStringNotContainsString('ai-train', RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: *\nDisallow: /admin\n"));
        self::assertStringContainsString(
            'Content-Signal: search=yes, ai-input=yes, ai-train=yes',
            RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: *\nDisallow: /admin\n", new RobotsOptions(aiTrain: true)),
        );
    }

    /**
     * The property the decorate lane must hold: appending the block changes
     * nothing any registry agent may fetch. Checked against an RFC 9309
     * evaluator written independently of the parser under test — keeping the
     * site's text intact is not enough if the effective policy moves.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('siteRobots')]
    public function testDecoratingNeverChangesWhatAnAgentMayFetch(string $existing): void
    {
        $decorated = rtrim($existing, "\n")."\n\n".RobotsFilter::buildBlock(self::REQUEST_ORIGIN, $existing);

        foreach (self::registry() as $agent) {
            foreach (['/', '/admin', '/admin/users', '/wp-admin/', '/wp-admin/admin-ajax.php', '/private/x', '/page.html'] as $path) {
                self::assertSame(
                    self::allows($existing, $agent, $path),
                    self::allows($decorated, $agent, $path),
                    \sprintf('%s fetching %s', $agent, $path),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function siteRobots(): iterable
    {
        yield 'wildcard restriction' => ["User-agent: *\nDisallow: /admin\n"];
        yield 'wordpress default' => ["User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n"];
        yield 'full-site disallow' => ["User-agent: *\nDisallow: /\n"];
        yield 'named group beside a wildcard' => ["User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n"];
        yield 'duplicate matching groups' => ["User-agent: GPTBot\nDisallow: /private\n\nUser-agent: *\nAllow: /\n\nUser-agent: gptbot\nDisallow: /admin\n"];
        yield 'multi-agent group' => ["User-agent: ClaudeBot\nUser-agent: CCBot\nDisallow: /\n"];
        yield 'named block and no wildcard' => ["User-agent: GPTBot\nDisallow: /\n"];
        yield 'wildcard with crawl-delay' => ["User-agent: *\nCrawl-delay: 10\nDisallow: /admin\n"];
        yield 'comments and a sitemap' => ["# house rules\nSitemap: https://x.test/sitemap.xml\nUser-agent: * # everyone\nDisallow: /admin # back office\n"];
        yield 'empty file' => [''];
    }

    public function testAgentsTheSiteAlreadyNamesAreNeverAdded(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: GPTBot\nDisallow: /private\n\nUser-agent: gptbot\nDisallow: /admin\n\nUser-agent: *\nDisallow: /tmp\n");

        // A group appended for GPTBot would merge with the site's two, and its
        // Allow: / would outrank their Disallow lines.
        self::assertDoesNotMatchRegularExpression('~^User-agent: GPTBot$~mi', $block);
        self::assertStringContainsString('User-agent: ClaudeBot', $block);
    }

    public function testInheritedRulesAreCarriedOnceInOneSharedGroup(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: *\nCrawl-delay: 10\nDisallow: /admin\n");

        self::assertStringContainsString("User-agent: GPTBot\nUser-agent: OAI-SearchBot\n", $block);
        self::assertStringContainsString("User-agent: cohere-ai\nCrawl-delay: 10\nDisallow: /admin\nContent-Signal: search=yes, ai-input=yes\n\n", $block);
        self::assertSame(1, substr_count($block, 'Disallow: /admin'));
        self::assertStringNotContainsString('Allow: /', $block);
    }

    public function testTheSitesOwnContentSignalIsInheritedInsteadOfOurs(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: *\nContent-Signal: search=yes, ai-train=no\nAllow: /\n", new RobotsOptions(aiTrain: true));

        self::assertStringContainsString('Content-Signal: search=yes, ai-train=no', $block);
        self::assertSame(1, substr_count($block, 'Content-Signal:'));
    }

    /**
     * Apple documents that Applebot follows Googlebot's group when the file
     * names Googlebot and not Applebot — an Applebot group would take it off
     * those rules, so none is added.
     */
    public function testApplebotStaysOnGooglebotsRulesWhenTheSiteNamesOnlyGooglebot(): void
    {
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN, "User-agent: Googlebot\nDisallow: /private\n");

        self::assertDoesNotMatchRegularExpression('~^User-agent: (Applebot|Googlebot)$~m', $block);
        self::assertStringContainsString('User-agent: Applebot-Extended', $block);
    }

    /**
     * allow_all grants crawl permission; it does not speak for the site on
     * content use. A training refusal declared on the wildcard group must
     * reach every agent's own group, or it becomes "no preference" for them.
     */
    public function testAllowAllKeepsTheSitesOwnContentSignal(): void
    {
        $existing = "User-agent: *\nContent-Signal: search=yes,ai-train=no\nDisallow: /admin\n";
        $block = RobotsFilter::buildBlock(self::REQUEST_ORIGIN, $existing, new RobotsOptions(RobotsOptions::ALLOW_ALL, true));

        self::assertStringContainsString("User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes,ai-train=no\n\n", $block);
        self::assertSame(substr_count($block, 'User-agent: '), substr_count($block, 'Content-Signal: search=yes,ai-train=no'));
        self::assertStringNotContainsString('ai-input=yes', $block);
    }

    public function testAllowAllIsTheExplicitOptInToOverrideWildcardRules(): void
    {
        $existing = "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n";
        $decorated = $existing."\n".RobotsFilter::buildBlock(self::REQUEST_ORIGIN, $existing, new RobotsOptions(RobotsOptions::ALLOW_ALL));

        self::assertTrue(self::allows($decorated, 'ClaudeBot', '/admin'), 'the grant it opts into');
        self::assertFalse(self::allows($decorated, 'Mozilla', '/admin'), 'unnamed agents keep the wildcard rules');
        self::assertFalse(self::allows($decorated, 'GPTBot', '/page.html'), 'a group the site wrote is never overridden');
    }

    public function testBuildBlockTrimsATrailingSlashFromTheOrigin(): void
    {
        self::assertStringContainsString(
            'Sitemap: https://example.test/ai-sitemap.xml',
            RobotsFilter::buildBlock('https://example.test/'),
        );
    }

    /**
     * The decorate path appends to a robots.txt the app may already own,
     * wildcard group included; a second one is a duplicate that can override
     * the site's real crawl rules. Both lanes emit the same block, so the
     * guard belongs on the block itself.
     */
    public function testEmitsNoWildcardGroup(): void
    {
        self::assertStringNotContainsString('User-agent: *', RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    /**
     * The backend emits `Agentmap: /.well-known/ai-catalog.json` in both its
     * lanes. That path is root-relative and this bundle does not serve
     * ai-catalog.json, so the line would advertise a 404 at the customer's
     * own apex. Adding it requires serving the artefact first.
     */
    public function testEmitsNoAgentmapLine(): void
    {
        self::assertStringNotContainsString('Agentmap:', RobotsFilter::buildBlock(self::REQUEST_ORIGIN));
    }

    public function testBuildBlockWithoutBaseUrlOmitsSitemap(): void
    {
        self::assertStringNotContainsString('Sitemap:', RobotsFilter::buildBlock(''));
    }

    public function testDecoratesAppServedRobots(): void
    {
        $response = new Response("User-agent: *\nDisallow: /admin\n", 200, ['Content-Type' => 'text/plain']);
        $decorated = $this->responseEvent($this->filter(), '/robots.txt', $response);

        $content = (string) $decorated->getContent();
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin\n\n# Globetrotters AI Presence\n", $content);
        self::assertStringContainsString("User-agent: cohere-ai\nDisallow: /admin\nContent-Signal: search=yes, ai-input=yes\n", $content);
        self::assertStringContainsString('Sitemap: '.self::REQUEST_ORIGIN.'/ai-sitemap.xml', $content);
        // The app's own wildcard group is the only one in the file.
        self::assertSame(1, substr_count($content, 'User-agent: *'));
    }

    public function testSitemapLineNamesTheRequestHostNotTheGlobetrottersOrigin(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://apex.example/robots.txt'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->filter()->onKernelResponse($event);

        $content = (string) $response->getContent();
        self::assertStringContainsString('Sitemap: https://apex.example/ai-sitemap.xml', $content);
        self::assertStringNotContainsString(self::BASE_URL, $content);
    }

    public function testGeneratedRobotsAlsoNamesTheRequestHost(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), 'https://apex.example/robots.txt');

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertStringContainsString('Sitemap: https://apex.example/ai-sitemap.xml', (string) $response->getContent());
    }

    public function testDecorationRemovesStaleBodyMetadata(): void
    {
        $response = new Response("User-agent: *\n", 200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => '14',
            'ETag' => '"robots-v1"',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'Content-MD5' => 'old-md5',
            'Digest' => 'sha-256=old',
            'Content-Digest' => 'sha-256=:old:',
            'Repr-Digest' => 'sha-256=:old:',
        ]);
        $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame('"'.hash('sha256', (string) $response->getContent()).'"', $response->getEtag());
        foreach (['Content-Length', 'Last-Modified', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest'] as $header) {
            self::assertFalse($response->headers->has($header), $header.' must not describe the undecorated body');
        }
    }

    public function testDoesNotDecorateTwice(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $filter = $this->filter();
        $this->responseEvent($filter, '/robots.txt', $response);
        $this->responseEvent($filter, '/robots.txt', $response);

        self::assertSame(1, substr_count((string) $response->getContent(), RobotsFilter::MARKER));
    }

    public function testSkipsWhenNotConnected(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(connected: false), '/robots.txt', $response);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testSkipsWhenCacheEmpty(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(cached: false), '/robots.txt', $response);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testSkipsOtherPaths(): void
    {
        $response = new Response('hello', 200, ['Content-Type' => 'text/plain']);
        $this->responseEvent($this->filter(), '/hello.txt', $response);

        self::assertSame('hello', $response->getContent());
    }

    public function testSkipsNonPlainTextResponses(): void
    {
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame('<html></html>', $response->getContent());
    }

    public function testGeneratesRobotsWhenAppReturnsExplicit404Response(): void
    {
        // A catch-all controller returns a 404 Response instead of throwing, so
        // onKernelException never fires — onKernelResponse must still generate.
        $response = new Response('Not Found', 404, ['Content-Type' => 'text/html']);
        $generated = $this->responseEvent($this->filter(), '/robots.txt', $response);

        self::assertSame(200, $generated->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $generated->headers->get('Content-Type'));
        self::assertStringStartsWith(RobotsFilter::MARKER, (string) $generated->getContent());
    }

    public function testDoesNotGenerateOn404WhenNotAdvertising(): void
    {
        $response = new Response('Not Found', 404);
        $result = $this->responseEvent($this->filter(cached: false), '/robots.txt', $response);

        self::assertSame(404, $result->getStatusCode());
        self::assertSame('Not Found', $result->getContent());
    }

    /**
     * Capture runs before ResponseListener (0) empties a HEAD body; decoration
     * runs after CacheAttributeListener (-10) stamps late validators, and
     * before ConditionalGetSubscriber (-64) revalidates the final tag.
     */
    public function testSubscribesEitherSideOfSymfonysOwnResponseListeners(): void
    {
        self::assertSame([
            ['captureHeadRepresentation', RobotsFilter::CAPTURE_PRIORITY],
            ['onKernelResponse', RobotsFilter::PRIORITY],
        ], RobotsFilter::getSubscribedEvents()[\Symfony\Component\HttpKernel\KernelEvents::RESPONSE]);
        self::assertGreaterThan(0, RobotsFilter::CAPTURE_PRIORITY);
        self::assertLessThan(-10, RobotsFilter::PRIORITY);
        self::assertGreaterThan(\Globetrotters\AiPresenceBundle\Serving\ConditionalGetSubscriber::PRIORITY, RobotsFilter::PRIORITY);
    }

    /**
     * A HEAD stands for the GET: no body, but the decorated GET's entity-tag
     * and length — never the application's, and never a digest of the
     * emptied body.
     */
    public function testHeadCarriesTheDecoratedGetsMetadataAndNoBody(): void
    {
        $app = static fn (): Response => new Response("User-agent: *\nDisallow: /admin\n", 200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => '31',
            'ETag' => '"robots-v1"',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
        ]);
        $filter = $this->filter();

        $get = $this->throughTheKernelLifecycle($filter, 'GET', $app());
        $head = $this->throughTheKernelLifecycle($filter, 'HEAD', $app());

        self::assertSame('', $head->getContent());
        self::assertSame($get->getEtag(), $head->getEtag());
        self::assertSame('"'.hash('sha256', (string) $get->getContent()).'"', $head->getEtag());
        self::assertSame((string) \strlen((string) $get->getContent()), $head->headers->get('Content-Length'));
        self::assertFalse($head->headers->has('Last-Modified'));
    }

    public function testHeadNeverPairsContentLengthWithTransferEncoding(): void
    {
        $response = $this->throughTheKernelLifecycle($this->filter(), 'HEAD', new Response("User-agent: *\n", 200, [
            'Content-Type' => 'text/plain',
            'Transfer-Encoding' => 'chunked',
            'ETag' => '"robots-v1"',
        ]));

        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('Content-Length'));
        self::assertNotSame('"robots-v1"', $response->getEtag(), 'still describes the decorated GET');
    }

    public function testHeadOnAReturned404GeneratesTheGetsLengthAndNoBody(): void
    {
        $filter = $this->filter();

        $get = $this->throughTheKernelLifecycle($filter, 'GET', new Response('Not Found', 404, ['Content-Type' => 'text/html']));
        $head = $this->throughTheKernelLifecycle($filter, 'HEAD', new Response('Not Found', 404, ['Content-Type' => 'text/html']));

        self::assertSame(200, $head->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $head->headers->get('Content-Type'));
        self::assertSame('', $head->getContent());
        self::assertSame((string) \strlen((string) $get->getContent()), $head->headers->get('Content-Length'));
    }

    /**
     * Without the captured body there is no representation to describe, and
     * the emptied one must not stand in for it.
     */
    public function testNeverDescribesAHeadFromItsEmptiedBody(): void
    {
        $response = new Response('', 200, ['Content-Type' => 'text/plain', 'ETag' => '"robots-v1"']);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/robots.txt', 'HEAD'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->filter()->onKernelResponse($event);

        self::assertSame('', $response->getContent());
        self::assertSame('"robots-v1"', $response->getEtag());
    }

    public function testDoesNotDecorateOnPost(): void
    {
        $response = new Response("User-agent: *\n", 200, ['Content-Type' => 'text/plain']);
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/robots.txt', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $this->filter()->onKernelResponse($event);

        self::assertStringNotContainsString(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testServesGeneratedRobotsOn404(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException());

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringStartsWith(RobotsFilter::MARKER, (string) $response->getContent());
    }

    public function testDoesNotServeOnOtherExceptions(): void
    {
        $event = $this->exceptionEvent($this->filter(), new AccessDeniedHttpException());

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnOtherPaths(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), '/missing');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeOnPost(): void
    {
        $event = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), '/robots.txt', 'POST');

        self::assertNull($event->getResponse());
    }

    public function testDoesNotServeWhenCacheEmpty(): void
    {
        $event = $this->exceptionEvent($this->filter(cached: false), new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testAGeneratedHeadCarriesTheGeneratedGetsLength(): void
    {
        $get = $this->exceptionEvent($this->filter(), new NotFoundHttpException())->getResponse();
        $head = $this->exceptionEvent($this->filter(), new NotFoundHttpException(), '/robots.txt', 'HEAD')->getResponse();

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertFalse($get->headers->has('Content-Length'), 'the server measures a GET body');
        self::assertSame((string) \strlen((string) $get->getContent()), $head->headers->get('Content-Length'));
    }

    /**
     * The two kernel.response listeners with Symfony's ResponseListener
     * between them, as HttpKernel dispatches them.
     */
    private function throughTheKernelLifecycle(RobotsFilter $filter, string $method, Response $response): Response
    {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/robots.txt', $method),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $filter->captureHeadRepresentation($event);
        $response->prepare($event->getRequest());
        $filter->onKernelResponse($event);

        return $response;
    }

    private static function canonicalFixture(): string
    {
        $fixture = file_get_contents(__DIR__.'/../../Fixtures/robots-ai-user-agent-groups.txt');
        self::assertIsString($fixture);

        return $fixture;
    }

    /**
     * @return list<string>
     */
    private static function registry(): array
    {
        preg_match_all('~^User-agent: (.+)$~m', self::canonicalFixture(), $matches);

        return $matches[1];
    }

    /**
     * A deliberately small RFC 9309 evaluator, independent of RobotsPolicy: the
     * groups naming the agent's product token (combined), else the ``*`` groups,
     * else allowed; the longest matching rule wins and Allow wins a tie.
     */
    private static function allows(string $robots, string $agent, string $path): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $inMembers = false;
        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $raw) {
            $line = trim(explode('#', $raw, 2)[0]);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ('user-agent' === $field) {
                if ($inMembers) {
                    $groups[] = [$agents, $rules];
                    [$agents, $rules, $inMembers] = [[], [], false];
                }
                $agents[] = strtolower($value);
            } elseif ('sitemap' !== $field && [] !== $agents) {
                $inMembers = true;
                if (\in_array($field, ['allow', 'disallow'], true)) {
                    $rules[] = [$field, $value];
                }
            }
        }
        if ([] !== $agents) {
            $groups[] = [$agents, $rules];
        }

        $token = strtolower($agent);
        $named = null;
        $wildcard = null;
        foreach ($groups as [$names, $groupRules]) {
            if (\in_array($token, $names, true)) {
                $named = array_merge($named ?? [], $groupRules);
            }
            if (\in_array('*', $names, true)) {
                $wildcard = array_merge($wildcard ?? [], $groupRules);
            }
        }

        $best = -1;
        $allowed = true;
        foreach ($named ?? $wildcard ?? [] as [$directive, $pattern]) {
            if ('' === $pattern) {
                continue;
            }
            $regex = '~^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')).'~';
            if (1 === preg_match($regex, $path) && (\strlen($pattern) > $best || (\strlen($pattern) === $best && 'allow' === $directive))) {
                $best = \strlen($pattern);
                $allowed = 'allow' === $directive;
            }
        }

        return $allowed;
    }
}
