<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Serving;

/**
 * What an application's own robots.txt already says, read the way crawlers
 * read it — exactly as much as {@see RobotsFilter} needs to append to the file
 * without changing what any crawler may fetch.
 *
 * Two facts matter. A crawler obeys only the group(s) naming it, and falls
 * back to the ``*`` group(s) only when none does; groups naming the same agent
 * are combined. So a group appended for an agent the file already names would
 * *merge into* the site's own rules for it — an ``Allow: /`` there beats the
 * site's ``Disallow: /``, because equal-length matches go to Allow — and a
 * group appended for an agent it does not name *replaces* the ``*`` rules that
 * agent was obeying. This class reports which agents are named, and the member
 * lines of the ``*`` group that every other agent is on.
 *
 * **Only an Allow or Disallow line ends a user-agent section.** Google's spec
 * ("Rules other than allow, disallow, and user-agent are ignored by the
 * robots.txt parser") and its reference parser (google/robotstxt, where only
 * HandleAllow and HandleDisallow set the group separator) both group this way.
 * RFC 9309's grammar has no place for other records inside a group and leaves
 * them to the crawler, so Google's reading is the one matched here. A
 * Content-Signal, Crawl-delay, Sitemap or unknown line between two User-agent
 * lines therefore leaves them in one group, sharing the rules that follow.
 * Those non-rule lines are still kept as the group's members — a signal is part
 * of what an agent inheriting the ``*`` group should carry — but they never
 * decide where a group ends. Blank lines do not end one either, and
 * ``Sitemap:`` is a global record that belongs to no group.
 */
final class RobotsPolicy
{
    /**
     * @param array<string, true> $namedAgents   lowercased product tokens with a group of their own
     * @param list<string>        $wildcardLines member lines of every ``*`` group, in file order
     * @param bool                $endsOpen      whether the file stops inside a section no rule has closed
     */
    private function __construct(
        private readonly array $namedAgents,
        private readonly array $wildcardLines,
        private readonly bool $endsOpen,
    ) {
    }

    public static function parse(string $robots): self
    {
        if (str_starts_with($robots, "\xEF\xBB\xBF")) {
            $robots = substr($robots, 3);
        }

        $named = [];
        /** @var list<array{0: list<string>, 1: list<string>}> $sections */
        $sections = [];
        /** @var list<string> $agents agents of the section being read */
        $agents = [];
        /** @var list<string> $members its member lines so far */
        $members = [];
        $seenRule = false;

        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $raw) {
            $line = trim(explode('#', $raw, 2)[0]);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));

            if ('user-agent' === $field) {
                if ($seenRule) {
                    $sections[] = [$agents, $members];
                    [$agents, $members, $seenRule] = [[], [], false];
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

            $members[] = $line;
            if ('allow' === $field || 'disallow' === $field) {
                $seenRule = true;
            }
        }
        $sections[] = [$agents, $members];

        // Members are assigned once a section is complete, because a line can
        // come before one of its agents: in "User-agent: GPTBot / Crawl-delay: 5
        // / User-agent: * / Disallow: /x", the delay is the wildcard's too.
        $wildcard = [];
        foreach ($sections as [$sectionAgents, $sectionMembers]) {
            if (\in_array('*', $sectionAgents, true)) {
                array_push($wildcard, ...$sectionMembers);
            }
        }

        return new self($named, $wildcard, [] !== $agents && !$seenRule);
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
     * Whether the file stops inside a user-agent section no Allow or Disallow
     * line has closed. Anything appended to such a file joins that section: its
     * User-agent lines read as more agents of the site's group, and the site's
     * agents take on the appended rules.
     */
    public function endsInsideAGroup(): bool
    {
        return $this->endsOpen;
    }

    /**
     * The matchable part of a ``User-agent:`` value. RFC 9309 product tokens
     * are letters, "-" and "_", and Google stops matching at the first other
     * character — a version, a digit, a stray "*" — so ``GPTBot/1.1`` and
     * ``GPTBot2`` both name ``gptbot``. A "*" alone or followed by whitespace is
     * the wildcard, as Google reads it; ``*bot`` names nothing.
     */
    private static function productToken(string $value): string
    {
        $value = trim($value);
        if (1 === preg_match('~^\*(\s|$)~', $value)) {
            return '*';
        }

        return 1 === preg_match('~^[A-Za-z_-]+~', $value, $match) ? strtolower($match[0]) : '';
    }
}
