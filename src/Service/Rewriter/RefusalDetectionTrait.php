<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

/**
 * Detects LLM clarification/refusal replies that must not be persisted as
 * editorial content.
 *
 * Short source values tempt some models to answer with "I'm ready to transform
 * the text, please provide…" instead of transforming anything. Without this
 * guard that sentence is written into the record — which is exactly what
 * happened to a news headline on 2026-05-08 (see Phase 10.4 findings).
 *
 * The pattern lives here rather than in each rewriter because it had already
 * drifted: the Phase-10.4 extension (Anthropic-style "I'm ready to / Happy to /
 * Sure,") was applied to News, Event and Faq but never to Content, Page and
 * Article, leaving `record_rewrite` on those tables able to persist refusal
 * text months after the bug was considered fixed. One definition, six users.
 *
 * Refusal phrasing is provider-specific and needs maintaining: Anthropic tends
 * towards "I'm ready / Happy to / Sure", OpenAI towards "I need / Please
 * provide". Extend the pattern here when a new variant shows up in the wild.
 */
trait RefusalDetectionTrait
{
    /**
     * Only applied when the reply is at least 1.5× the source length — a
     * genuine transformation stays roughly in the same size range, while a
     * clarification reply balloons a short input into a full sentence. Keeps
     * legitimate rewrites that merely start with "I" from being rejected.
     */
    private const REFUSAL_LENGTH_FACTOR = 1.5;

    private const REFUSAL_PATTERN = '/^('
        . 'I (need|require|don\'t see|do not see|cannot|am unable|am ready|am happy|am glad|notice|see that|will need)'
        . '|I\'m (ready|happy|glad|sorry|going to)'
        . '|I\'d (be (happy|glad)|like|love)'
        . '|I\'ll (need|gladly|happily|be happy)'
        . '|Ready to|Happy to|Sure[,!.]|Of course[,!.]?'
        . '|Please (provide|share|give|specify|clarify)'
        . '|Could you (provide|share|give|specify|please|clarify)'
        . '|It (seems|appears|looks)\s+(like|that)'
        . '|The (input|text|content|headline|teaser)\s+(is|appears|seems)'
        . '|Ich (brauche|benötige|kann|sehe|bin (bereit|gerne|froh))'
        . '|Bitte (geben|stellen|teilen|liefern|senden|nennen)'
        . ')/i';

    private function looksLikeRefusal(string $rewritten, string $original): bool
    {
        if (\strlen($rewritten) < \strlen($original) * self::REFUSAL_LENGTH_FACTOR) {
            return false;
        }

        return 1 === preg_match(self::REFUSAL_PATTERN, $rewritten);
    }
}
