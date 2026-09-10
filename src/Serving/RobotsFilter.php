<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advertises the AI-crawler allow-list plus this site's own sitemap on
 * /robots.txt: decorates the response when the app serves one, and serves a
 * generated robots.txt when the app would 404. Only advertises once a bundle
 * is actually cached.
 *
 * Two things this block deliberately does not emit, both of which the
 * Globetrotters backend does emit in its own lanes:
 *
 * - **No `User-agent: *` group.** The backend owns the whole file; this bundle
 *   appends to one the app may already own, and a second wildcard group is a
 *   duplicate that can override the site's real crawl rules. It costs nothing
 *   under RFC 9309: a crawler with no matching group is unrestricted anyway, so
 *   an unnamed bot is as welcome without the group as with it. The generated
 *   lane omits it too, so both lanes emit one canonical block.
 * - **No `Agentmap:` line.** `Agentmap: /.well-known/ai-catalog.json` is
 *   root-relative and this bundle does not serve `ai-catalog.json`, so the line
 *   would advertise a 404 at the customer's own apex.
 *
 * Limitation (documented): a physical public/robots.txt is served by the web
 * server and never reaches the kernel, so it can't be decorated here.
 */
final class RobotsFilter implements EventSubscriberInterface
{
    public const MARKER = '# Globetrotters AI Presence';

    /**
     * The canonical AI user-agent registry, mirrored by hand from
     * gt-backend's
     * `libs/globetrotters-business/globetrotters/business/presence/services/ai_user_agents.py`
     * — names, order and vendor casing all follow it, because
     * `tests/Fixtures/robots-ai-user-agent-groups.txt` is generated from that
     * module and `RobotsFilterTest` fails on any drift.
     *
     * Naming them changes nothing a crawler may fetch: per RFC 9309 §2.2.1 a
     * crawler obeys only its own most-specific matching group, and an unnamed
     * one is unrestricted. The groups are signalling — they exist so a scanner
     * (Globetrotters' own readiness probe included) reads an explicit per-bot
     * welcome, which is why the list has to be the list scanners check.
     */
    private const AI_BOTS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-SearchBot',
        'Claude-User',
        'anthropic-ai',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Googlebot',
        'Bingbot',
        'Applebot',
        'Applebot-Extended',
        'CCBot',
        'meta-externalagent',
        'Amazonbot',
        'DuckAssistBot',
        'Bytespider',
        'cohere-ai',
    ];

    /**
     * Emitted inside *every* named group, never once at the top: a crawler
     * obeys only its own matching group, so a copy in one group never reaches
     * another. Values are the registry's — every entry invites search,
     * inference input and training, because being consumed by agents is the
     * point of the presence.
     */
    private const CONTENT_SIGNAL = 'Content-Signal: search=yes, ai-input=yes, ai-train=yes';

    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -20],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * Decorate an app-served robots.txt, or generate one when the app returns
     * an explicit 404 Response (a catch-all controller that returns rather than
     * throws — the thrown case is handled in onKernelException instead).
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('/robots.txt' !== $request->getPathInfo()) {
            return;
        }
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!$this->shouldAdvertise()) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();
        // Symfony's own ResponseListener runs at priority 0, above this one, and
        // its Response::prepare() has already emptied the body of a HEAD
        // response. Writing one back here would put bytes on a HEAD — and in
        // the 200 branch, an emptied body no longer carries the marker, so the
        // block would be appended to nothing.
        $isHead = 'HEAD' === $request->getMethod();
        $origin = $request->getSchemeAndHttpHost();

        if (200 === $status) {
            if ($isHead) {
                return;
            }
            $contentType = $response->headers->get('Content-Type');
            if (null !== $contentType && !str_starts_with($contentType, 'text/plain')) {
                return;
            }
            $content = $response->getContent();
            if (false === $content || str_contains($content, self::MARKER)) {
                return;
            }

            $response->setContent(rtrim($content, "\n")."\n\n".self::buildBlock($origin));
            BodyMetadata::invalidate($response, $request);

            return;
        }

        // The app served a plain 404 Response (never threw), so onKernelException
        // never fired — generate the robots.txt in its place.
        if (404 === $status) {
            $response->setStatusCode(200);
            $response->setContent($isHead ? '' : self::buildBlock($origin));
            $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            BodyMetadata::invalidate($response, $request);
        }
    }

    /**
     * Serve a generated robots.txt when the app has none.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('/robots.txt' !== $request->getPathInfo()) {
            return;
        }
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }
        if (!$this->shouldAdvertise()) {
            return;
        }

        // Without this the kernel would force the response status back to the
        // exception's 404 (see HttpKernel::handleThrowable).
        $event->allowCustomResponseCode();
        $event->setResponse(new Response(self::buildBlock($request->getSchemeAndHttpHost()), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]));
    }

    /**
     * `$origin` is the scheme-and-host the request arrived on — **this** site,
     * not the configured Globetrotters origin.
     *
     * A `Sitemap:` directive naming another host is ignored by Google and Bing
     * without cross-domain sitemap verification, so the old cross-host line was
     * inert rather than harmful; but in bundle mode the content is served from
     * the customer's apex, and that is where the sitemap belongs. The apex is
     * whatever the install answers on, so the request is the only thing that
     * knows it — no new setting, and a site reachable on several hosts gets the
     * right line on each.
     *
     * The host arrives in a client-controlled header, and reflecting it is safe
     * here for the usual reason: the value only ever reaches the client that
     * sent it, a crawler sends the real host, and Symfony has already rejected
     * a malformed one (`Request::getHost()`), plus any host outside
     * `trusted_hosts` when the application configures it.
     *
     * The line names ``/ai-sitemap.xml``, which {@see Router} serves from the
     * cache — never ``/sitemap.xml``, which is the site's own (see
     * {@see Sitemap}). robots.txt takes a list of ``Sitemap:`` directives, so
     * this one is additive to whatever the application already declares.
     */
    public static function buildBlock(string $origin): string
    {
        $block = self::MARKER."\n".self::aiUserAgentGroups();

        if ('' === $origin) {
            return rtrim($block, "\n")."\n";
        }

        return $block.self::sitemapDirective($origin)."\n";
    }

    private static function sitemapDirective(string $origin): string
    {
        return 'Sitemap: '.rtrim($origin, '/').'/'.Sitemap::PATH;
    }

    /**
     * One group per registry entry: `User-agent:`, `Allow: /`, its
     * `Content-Signal`, blank-line separated.
     *
     * The block genuinely *ends* with a blank line so a caller can append a
     * `Sitemap:` (or anything else) straight onto it without running the last
     * group into it. This is byte-for-byte the named-group half of what the
     * backend emits — see `tests/Fixtures/robots-ai-user-agent-groups.txt`.
     */
    public static function aiUserAgentGroups(): string
    {
        $lines = [];
        foreach (self::AI_BOTS as $bot) {
            $lines[] = 'User-agent: '.$bot;
            $lines[] = 'Allow: /';
            $lines[] = self::CONTENT_SIGNAL;
            $lines[] = '';
        }

        // The final '' only becomes the string's line terminator; the extra
        // newline is what makes the last separator a real blank line.
        return implode("\n", $lines)."\n";
    }

    private function shouldAdvertise(): bool
    {
        return $this->options->isConnected() && $this->cache->hasAny();
    }
}
