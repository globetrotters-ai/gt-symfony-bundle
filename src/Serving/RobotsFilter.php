<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

use Globetrotters\AiPresenceBundle\Cache\ArtefactCache;
use Globetrotters\AiPresenceBundle\Settings\Options;
use Globetrotters\AiPresenceBundle\Settings\RobotsOptions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advertises the AI-crawler registry plus this site's own sitemap on
 * /robots.txt: decorates the response when the app serves one, and serves a
 * generated robots.txt when the app would 404. Only advertises once a bundle
 * is actually cached.
 *
 * **Decorating never changes what a crawler may fetch, by default.** Naming an
 * agent is not neutral: under RFC 9309 a crawler obeys only the group(s)
 * naming it and falls back to ``User-agent: *`` only when none does. So a
 * named group carrying ``Allow: /`` would release that agent from every
 * wildcard restriction the site has (a WordPress ``Disallow: /wp-admin/``, a
 * staging site's ``Disallow: /``), and one appended for an agent the site
 * already names would merge into — and outrank — the site's own rules for it.
 * Hence, with {@see RobotsOptions} at its defaults:
 *
 * - agents the site's file already names are **never** added — its own group
 *   stays the only one that applies to them;
 * - every other registry agent inherits the site's ``*`` member lines verbatim,
 *   so the rules it obeys are the rules it was obeying;
 * - ``Content-Signal`` says ``search=yes, ai-input=yes`` and nothing about
 *   training, unless the site's own wildcard group already carries a signal,
 *   which is inherited instead.
 *
 * ``ai_agents: allow_all`` and ``ai_train: true`` are the explicit opt-ins for
 * a broader grant; with both, and a site file with no wildcard rules, the
 * output is byte-for-byte the backend registry block.
 *
 * Two things this block deliberately does not emit, both of which the
 * Globetrotters backend does emit in its own lanes:
 *
 * - **No `User-agent: *` group.** The backend owns the whole file; this bundle
 *   appends to one the app may already own, and a second wildcard group would
 *   be combined with the site's and change its rules.
 * - **No `Agentmap:` line.** `Agentmap: /.well-known/ai-catalog.json` is
 *   root-relative and this bundle does not serve `ai-catalog.json`, so the line
 *   would advertise a 404 at the customer's own apex.
 *
 * **HEAD.** Symfony's ResponseListener (kernel.response, priority 0) empties a
 * HEAD body in ``Response::prepare()`` before the decoration below runs. The
 * decoration stays after it on purpose — it must follow listeners such as
 * ``CacheAttributeListener`` (-10) that stamp an application's ``ETag`` and
 * ``Last-Modified`` late — so the body is captured first, at
 * {@see self::CAPTURE_PRIORITY}, and a HEAD response is described from that
 * copy: the same entity-tag and length as the decorated GET, and no body.
 *
 * Limitation (documented): a physical public/robots.txt is served by the web
 * server and never reaches the kernel, so it can't be decorated here.
 */
final class RobotsFilter implements EventSubscriberInterface
{
    public const MARKER = '# Globetrotters AI Presence';

    /**
     * Decoration and metadata: after CacheAttributeListener (-10), which adds
     * an application's cache validators late, and before
     * {@see ConditionalGetSubscriber} (-64), which revalidates the final tag.
     */
    public const PRIORITY = -20;

    /**
     * The lowest priority that still runs before ResponseListener (0) empties a
     * HEAD body, so the representation a HEAD stands for can be kept.
     */
    public const CAPTURE_PRIORITY = 1;

    private const ATTRIBUTE_HEAD_BODY = '_gt_robots_head_body';

    /**
     * The canonical AI user-agent registry, mirrored by hand from
     * gt-backend's
     * `libs/globetrotters-business/globetrotters/business/presence/services/ai_user_agents.py`
     * — names, order and vendor casing all follow it, because
     * `tests/Fixtures/robots-ai-user-agent-groups.txt` is generated from that
     * module and `RobotsFilterTest` fails on any drift.
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
     * Agents that read another agent's group when the file names none of their
     * own — beyond RFC 9309, but documented by the vendor, so for them "no group
     * of its own" does not mean "on the wildcard rules". Applebot follows
     * Googlebot's instructions when robots.txt names Googlebot but not Applebot
     * (Apple's "About Applebot" page); adding an Applebot group there would take
     * it off the rules the site gave Googlebot.
     */
    private const FALLBACK_GROUPS = ['Applebot' => 'Googlebot'];

    /**
     * Emitted inside *every* named group, never once at the top: a crawler
     * obeys only its own matching group, so a copy in one group never reaches
     * another. Search and inference input are what an AI presence exists for;
     * training is a separate statement about the whole site, made only through
     * ``robots.ai_train`` (the registry's own block carries it — see
     * {@see self::CONTENT_SIGNAL_WITH_TRAINING}).
     */
    private const CONTENT_SIGNAL = 'Content-Signal: search=yes, ai-input=yes';
    private const CONTENT_SIGNAL_WITH_TRAINING = 'Content-Signal: search=yes, ai-input=yes, ai-train=yes';

    public function __construct(
        private readonly Options $options,
        private readonly ArtefactCache $cache,
        private readonly RobotsOptions $robots = new RobotsOptions(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => [
                ['captureHeadRepresentation', self::CAPTURE_PRIORITY],
                ['onKernelResponse', self::PRIORITY],
            ],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * Keep the body a HEAD response stands for, before Symfony drops it.
     */
    public function captureHeadRepresentation(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('HEAD' !== $request->getMethod() || '/robots.txt' !== $request->getPathInfo()) {
            return;
        }

        $content = $event->getResponse()->getContent();
        if (false !== $content) {
            $request->attributes->set(self::ATTRIBUTE_HEAD_BODY, $content);
        }
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
        $isHead = 'HEAD' === $request->getMethod();
        // What a GET carries. On HEAD the body is already gone, so it is the
        // copy captured before ResponseListener ran — never the empty string,
        // whose digest describes nothing a GET would ever send.
        $representation = $isHead ? $request->attributes->get(self::ATTRIBUTE_HEAD_BODY) : $response->getContent();
        $origin = $request->getSchemeAndHttpHost();
        $status = $response->getStatusCode();

        if (200 === $status) {
            $contentType = $response->headers->get('Content-Type');
            if (null !== $contentType && !str_starts_with($contentType, 'text/plain')) {
                return;
            }
            if (!\is_string($representation) || str_contains($representation, self::MARKER)) {
                return;
            }

            $decorated = rtrim($representation, "\n")."\n\n".self::buildBlock($origin, $representation, $this->robots);
            self::rewrite($response, $request, $decorated, $isHead);

            return;
        }

        // The app served a plain 404 Response (never threw), so onKernelException
        // never fired — generate the robots.txt in its place.
        if (404 === $status) {
            $response->setStatusCode(200);
            $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
            self::rewrite($response, $request, self::buildBlock($origin, '', $this->robots), $isHead);
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

        $block = self::buildBlock($request->getSchemeAndHttpHost(), '', $this->robots);
        $response = new Response($block, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        if ('HEAD' === $request->getMethod()) {
            // prepare() is about to drop the body and keep only this length —
            // the GET's, as a HEAD response should describe.
            $response->headers->set('Content-Length', (string) \strlen($block));
        }

        // Without this the kernel would force the response status back to the
        // exception's 404 (see HttpKernel::handleThrowable).
        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }

    /**
     * The block appended to (or generated as) robots.txt.
     *
     * `$origin` is the scheme-and-host the request arrived on — **this** site,
     * not the configured Globetrotters origin. `$existing` is the site's own
     * robots.txt, '' when there is none; see the class docblock for how it
     * shapes the groups.
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
    public static function buildBlock(string $origin, string $existing = '', RobotsOptions $options = new RobotsOptions()): string
    {
        $site = RobotsPolicy::parse($existing);
        $agents = array_values(array_filter(
            self::AI_BOTS,
            static fn (string $agent): bool => !self::isGovernedBySite($agent, $site),
        ));

        $block = self::MARKER."\n".self::groups($agents, $site, $options);

        if ('' === $origin) {
            return rtrim($block, "\n")."\n";
        }

        return $block.self::sitemapDirective($origin)."\n";
    }

    /**
     * One group per registry entry: `User-agent:`, `Allow: /`, its
     * `Content-Signal`, blank-line separated.
     *
     * The block genuinely *ends* with a blank line so a caller can append a
     * `Sitemap:` (or anything else) straight onto it without running the last
     * group into it. With ``$aiTrain`` this is byte-for-byte the named-group
     * half of what the backend emits — see
     * `tests/Fixtures/robots-ai-user-agent-groups.txt`.
     *
     * @param list<string> $agents
     */
    public static function aiUserAgentGroups(bool $aiTrain, array $agents = self::AI_BOTS): string
    {
        return self::perAgentGroups($agents, [self::contentSignal($aiTrain)]);
    }

    /**
     * @param list<string> $agents
     * @param list<string> $signal the Content-Signal line(s) each group carries
     */
    private static function perAgentGroups(array $agents, array $signal): string
    {
        $lines = [];
        foreach ($agents as $agent) {
            $lines[] = 'User-agent: '.$agent;
            $lines[] = 'Allow: /';
            array_push($lines, ...$signal);
            $lines[] = '';
        }

        // The final '' only becomes the string's line terminator; the extra
        // newline is what makes the last separator a real blank line.
        return [] === $lines ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param list<string> $agents registry agents the site's file leaves to its wildcard rules
     */
    private static function groups(array $agents, RobotsPolicy $site, RobotsOptions $options): string
    {
        if ([] === $agents) {
            return '';
        }

        $inherited = $site->wildcardLines();
        if ($options->allowsAll() || [] === $inherited) {
            // Nothing to inherit: no wildcard group, or an explicit allow_all.
            // A Content-Signal the wildcard group declares still speaks for the
            // site — allow_all opts into crawl permission, not into replacing
            // the site's own statement of how its content may be used.
            $siteSignal = array_values(array_filter(
                $inherited,
                static fn (string $line): bool => self::carries([$line], ['content-signal']),
            ));

            return self::perAgentGroups($agents, [] !== $siteSignal ? $siteSignal : [self::contentSignal($options->aiTrain())]);
        }

        // One group naming every agent, carrying the site's wildcard lines
        // once. RFC 9309 lets a group start with several User-agent lines, and
        // a copy per agent would multiply a long file twentyfold towards the
        // size crawlers stop reading at — past which a truncated group would be
        // *more* permissive than the rules it was copied from.
        $lines = array_map(static fn (string $agent): string => 'User-agent: '.$agent, $agents);
        if (!self::carries($inherited, ['allow', 'disallow'])) {
            // A group needs a rule; this one is equivalent to having none.
            $lines[] = 'Allow: /';
        }
        array_push($lines, ...$inherited);
        if (!self::carries($inherited, ['content-signal'])) {
            $lines[] = self::contentSignal($options->aiTrain());
        }

        return implode("\n", $lines)."\n\n";
    }

    private static function isGovernedBySite(string $agent, RobotsPolicy $site): bool
    {
        if ($site->names($agent)) {
            return true;
        }

        $fallback = self::FALLBACK_GROUPS[$agent] ?? null;

        return null !== $fallback && $site->names($fallback);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $fields lowercased field names
     */
    private static function carries(array $lines, array $fields): bool
    {
        foreach ($lines as $line) {
            if (\in_array(strtolower(trim(explode(':', $line, 2)[0])), $fields, true)) {
                return true;
            }
        }

        return false;
    }

    private static function contentSignal(bool $aiTrain): string
    {
        return $aiTrain ? self::CONTENT_SIGNAL_WITH_TRAINING : self::CONTENT_SIGNAL;
    }

    /**
     * Put the new representation on the response and make every piece of
     * metadata describe it. A HEAD keeps its empty body but carries the GET's
     * entity-tag and length.
     */
    private static function rewrite(Response $response, Request $request, string $representation, bool $isHead): void
    {
        if (!$isHead) {
            $response->setContent($representation);
        }

        BodyMetadata::invalidate($response, $request, $representation);

        // Not beside Transfer-Encoding, which prepare() strips Content-Length
        // for and which the GET would be framed by instead.
        if ($isHead && !$response->headers->has('Transfer-Encoding')) {
            $response->headers->set('Content-Length', (string) \strlen($representation));
        }
    }

    private static function sitemapDirective(string $origin): string
    {
        return 'Sitemap: '.rtrim($origin, '/').'/'.Sitemap::PATH;
    }

    private function shouldAdvertise(): bool
    {
        return $this->options->isConnected() && $this->cache->hasAny();
    }
}
