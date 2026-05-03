<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Server-side text-rewrite primitive. Implementors are tagged with
 * `contao_ai_backend.entity_rewriter` and consumed by RecordRewriteTool via
 * tagged-iterator injection. Each implementation supports exactly one Contao
 * record table (e.g. tl_news, tl_calendar_events) and is responsible for:
 *
 *   1. Loading the record
 *   2. Identifying which text fields are rewriteable
 *   3. Calling the inner LLM platform per field with the user's instructions
 *   4. Validating the LLM result (non-empty, sane length, …)
 *   5. Returning a field map suitable for passing to the regular *_update
 *      command pipeline so the audit-trail (tl_version + --operator) stays
 *      identical to a manual edit
 *
 * Rationale: LLM-orchestrated rewrites for "alle News in Archiv X auf
 * Englisch umschreiben" require N round-trips per field per record — way
 * past the rate-limit and context budget. Server-side macro shifts the
 * fan-out to the rewriter; the outer tool call sees a single result row.
 *
 * Audit-trail: the rewriter does NOT write back. It returns the new field
 * values; RecordRewriteTool re-routes them through the existing
 * NewsUpdateCommand (or analogous) with --operator so tl_version stays
 * stamped with the actual Contao backend user instead of "anthropic-bot".
 */
interface EntityRewriterInterface
{
    public function supports(string $table): bool;

    /**
     * @param int                $id            Record ID in the supported table
     * @param string             $instructions  Operator's natural-language rewrite directive
     *   (e.g. "auf Englisch in Du-Form", "kürzer und mit professionellem Ton")
     * @param PlatformInterface  $platform      Pre-configured LLM platform with the user's API key
     * @param string             $model         Model identifier (e.g. claude-sonnet-4-5-…)
     *
     * @return array{
     *   id: int,
     *   table: string,
     *   fields: array<string, string>,
     *   skipped: array<string, string>,
     * }  fields = field-name => new value (ready for *_update --set);
     *    skipped = field-name => reason (empty source, validation failure, …)
     *
     * @throws \RuntimeException on missing source record or platform error
     */
    public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array;
}
