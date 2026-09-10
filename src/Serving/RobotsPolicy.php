<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * What an application's own robots.txt already says, read the way RFC 9309
 * reads it — exactly as much as {@see RobotsFilter} needs to append to the file
 * without changing what any crawler may fetch.
 *
 * Two facts matter. A crawler obeys only the group(s) naming it (§2.2.1), and
 * falls back to the ``*`` group(s) only when none does; groups naming the same
 * agent are combined. So a group appended for an agent the file already names
 * would *merge into* the site's own rules for it — an ``Allow: /`` there beats
 * the site's ``Disallow: /``, because equal-length matches go to Allow — and a
 * group appended for an agent it does not name *replaces* the ``*`` rules that
 * agent was obeying. This class reports which agents are named, and the member
 * lines of the ``*`` group that every other agent is on.
 *
 * Grouping follows the RFC rather than blank lines: consecutive
 * ``User-agent:`` lines share a group, and the next ``User-agent:`` after any
 * member line starts a new one. ``Sitemap:`` is a global record, not a group
 * member.
 */
final class RobotsPolicy
{
    /**
     * @param array<string, true> $namedAgents   lowercased product tokens with a group of their own
     * @param list<string>        $wildcardLines member lines of every ``*`` group, in file order
     */
    private function __construct(
        private readonly array $namedAgents,
        private readonly array $wildcardLines,
    ) {
    }

    public static function parse(string $robots): self
    {
        if (str_starts_with($robots, "\xEF\xBB\xBF")) {
            $robots = substr($robots, 3);
        }

        $named = [];
        $wildcard = [];
        /** @var list<string> $agents agents of the group being read */
        $agents = [];
        $inMembers = false;

        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $raw) {
            $line = trim(explode('#', $raw, 2)[0]);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));

            if ('user-agent' === $field) {
                if ($inMembers) {
                    $agents = [];
                    $inMembers = false;
                }
                $token = self::productToken($value);
                if ('' !== $token) {
                    $agents[] = $token;
                    if ('*' !== $token) {
                        $named[$token] = true;
                    }
                }
                continue;
            }
            if ('sitemap' === $field || [] === $agents) {
                continue;
            }

            $inMembers = true;
            if (\in_array('*', $agents, true)) {
                $wildcard[] = $line;
            }
        }

        return new self($named, $wildcard);
    }

    /**
     * Whether the file gives this agent a group of its own (case-insensitive,
     * as crawlers match it).
     */
    public function names(string $agent): bool
    {
        return isset($this->namedAgents[strtolower($agent)]);
    }

    /**
     * The ``*`` group's member lines — rules, ``Crawl-delay``, any signal —
     * verbatim, minus comments. Empty when the file has no wildcard group, in
     * which case an agent without a group of its own is unrestricted.
     *
     * @return list<string>
     */
    public function wildcardLines(): array
    {
        return $this->wildcardLines;
    }

    /**
     * The matchable part of a ``User-agent:`` value: the product token, before
     * any version or comment (``GPTBot/1.1`` names ``gptbot``).
     */
    private static function productToken(string $value): string
    {
        return 1 === preg_match('~^[^\s/]+~', trim($value), $match) ? strtolower($match[0]) : '';
    }
}
