<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Rewriter;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Shared machinery for the entity rewriters.
 *
 * Every rewriter used to carry its own copy of rewriteField(), resultToText()
 * and isPlausible() — six near-identical implementations, differing only in a
 * byte cap and the per-field form-factor description. That duplication was not
 * theoretical: the Phase-10.4 refusal-pattern fix reached three of the six
 * copies and silently left `record_rewrite` on tl_content, tl_page and
 * tl_article able to persist clarification replies for months (issue #1).
 *
 * Subclasses now supply only what genuinely differs:
 *   - maxResultBytes()   the plausibility cap for their longest field
 *   - fieldShape()       how each field should read back
 *   - preservesHtml()    whether the fields carry markup
 *   - formFactorHint()   an extra sentence when the fields need one
 *
 * plus supports() and rewrite() from EntityRewriterInterface, which stay
 * per-entity because loading the record and choosing its fields is exactly
 * the part that is not shared.
 */
abstract class AbstractEntityRewriter implements EntityRewriterInterface
{
    use RefusalDetectionTrait;
    use UntrustedInputTrait;

    /**
     * A result shorter than this fraction of the source is treated as the model
     * having dropped content rather than transformed it.
     */
    protected const MIN_LENGTH_RATIO = 0.3;

    /**
     * The ratio check only applies from this source length upwards — short
     * fields legitimately shrink (a translated headline can halve in length).
     */
    protected const MIN_SOURCE_LENGTH_FOR_RATIO = 30;

    /**
     * Phase-9.4: very short inputs tempt some models into clarification replies
     * ("I need a headline to transform..."). Below this length the value is
     * passed through verbatim instead of being sent to the model at all.
     */
    protected const PASS_THROUGH_BELOW_CHARS = 4;

    public function __construct(
        protected readonly ContaoFramework $framework,
    ) {
    }

    /** Plausibility cap in bytes for this entity's longest rewriteable field. */
    abstract protected function maxResultBytes(): int;

    /** Describes how the given field should read back, e.g. "a single-line headline". */
    abstract protected function fieldShape(string $field): string;

    /** True when the rewriteable fields carry HTML that must survive intact. */
    protected function preservesHtml(): bool
    {
        return false;
    }

    /** Optional extra sentence appended to the form-factor rule. */
    protected function formFactorHint(): string
    {
        return '';
    }

    protected function rewriteField(
        string $field,
        string $original,
        string $instructions,
        PlatformInterface $platform,
        string $model,
    ): ?string {
        if (mb_strlen($original) < self::PASS_THROUGH_BELOW_CHARS) {
            return $original;
        }

        $messages = new MessageBag(
            Message::forSystem($this->buildSystemPrompt($field, $instructions)),
            Message::ofUser($this->wrapUntrustedInput($original)),
        );

        try {
            $result = $platform->invoke($model, $messages, ['temperature' => 0.3]);
        } catch (\Throwable) {
            // Platform error (rate limit, network, bad key): fail this field but
            // keep going for the rest. Surfaced as `skipped` to the operator.
            return null;
        }

        $rewritten = $this->stripInputWrapper(trim($this->resultToText($result)));
        $rewritten = $this->sanitiseForField($field, $rewritten);

        if (null === $rewritten) {
            return null;
        }

        return $this->isPlausible($rewritten, $original) ? $rewritten : null;
    }

    /**
     * Fields whose stored value may legitimately contain HTML.
     *
     * Everything not listed here is plain text — `tl_news.headline` is a
     * `varchar`, a `<script>` in it is not "unusual formatting", it is a model
     * that did something it was not asked to do.
     *
     * @return list<string>
     */
    protected function htmlFields(): array
    {
        return [];
    }

    /**
     * 🔴 M-3 (Audit 2026-09-02): LLM-Ausgabe ging bis dahin ungeprüft in die
     * Datenbank — validiert wurden nur Leerwert, Länge, Längenverhältnis und
     * einige Verweigerungsphrasen. Für ein HTML-Feld wie `tl_content.text` oder
     * `tl_faq.answer` heißt das: was der Anbieter zurückgibt, wird gespeichert.
     *
     * Zwei verschiedene Antworten, mit Absicht:
     *
     * - **Klartext-Feld mit Tags → abgelehnt**, nicht stillschweigend bereinigt.
     *   Ein `<script>` in einer Überschrift ist kein Formatierungsfehler, den man
     *   wegputzt — es ist ein Signal, und das gehört dem Operator gemeldet
     *   (`skipped`) statt weggeräumt. Stilles Reparieren verbirgt genau die
     *   Fälle, für die man hinsieht.
     * - **HTML-Feld → über Contaos eigene Regeln gefiltert.** `Input::stripTags()`
     *   mit `allowedTags`/`allowedAttributes` aus der Konfiguration ist exakt das,
     *   was Contao auf Backend-Eingaben anwendet. Eine eigene Liste hier wäre
     *   eine zweite Wahrheit, die von der Installation abweicht.
     */
    private function sanitiseForField(string $field, string $value): ?string
    {
        if ('' === $value) {
            return $value;
        }

        if (!\in_array($field, $this->htmlFields(), true)) {
            // strip_tags() entfernt nur Tag-artige Konstrukte; ein "5 < 6" im
            // Fließtext überlebt. Weicht das Ergebnis ab, war ein Tag drin.
            //
            // Bewusst VOR framework->initialize(): der Klartext-Pfad braucht
            // Contao nicht. Aufgefallen, weil ein Prüfskript die Methode ohne
            // gebootetes Framework rief und daran scheiterte — die Abhängigkeit
            // war echt und unnötig.
            return strip_tags($value) === $value ? $value : null;
        }

        $this->framework->initialize();

        /** @var Config $config */
        $config = $this->framework->getAdapter(Config::class);

        /** @var Input $input */
        $input = $this->framework->getAdapter(Input::class);

        return $input->stripTags(
            $value,
            (string) $config->get('allowedTags'),
            (string) $config->get('allowedAttributes'),
        );
    }

    protected function buildSystemPrompt(string $field, string $instructions): string
    {
        $shape = $this->fieldShape($field);

        $formFactor = 'Preserve the same form factor as the input.';
        if ($this->preservesHtml()) {
            $formFactor .= ' For HTML inputs: preserve tag structure and attributes exactly,'
                . ' only transform the visible text inside.';
        }
        if ('' !== $hint = $this->formFactorHint()) {
            $formFactor .= ' ' . $hint;
        }

        $verbatim = $this->preservesHtml()
            ? 'Keep proper nouns, brand names, dates, numbers, identifiers, URLs and HTML attributes verbatim'
            : 'Keep proper nouns, brand names, dates, numbers, and identifiers verbatim';

        return <<<SYSTEM
            You transform a single piece of editorial text according to the operator's instructions.

            Form factor of the input: {$shape}.

            Rules:
            - Return ONLY the transformed text, nothing else. No preamble, no commentary, no markdown wrappers, no surrounding quotes.
            - {$formFactor}
            - {$verbatim} unless the instructions explicitly say otherwise.
            - If the instructions cannot be applied (e.g. the input is empty or too short), return the input verbatim.
            - Do not add new factual claims. Stick to what the input says.

            {$this->untrustedInputRule()}

            Operator's instructions: {$instructions}
            SYSTEM;
    }

    /**
     * symfony/ai returns different result classes per provider (TextResult,
     * AssistantMessage, …); try the common surface, then fall back to casting.
     */
    protected function resultToText(mixed $result): string
    {
        if (\is_object($result) && method_exists($result, 'asText')) {
            return (string) $result->asText();
        }

        if (\is_object($result) && method_exists($result, '__toString')) {
            return (string) $result;
        }

        return \is_scalar($result) || null === $result ? (string) $result : '';
    }

    protected function isPlausible(string $rewritten, string $original): bool
    {
        if ('' === $rewritten) {
            return false;
        }

        if (\strlen($rewritten) > $this->maxResultBytes()) {
            return false;
        }

        if ($this->looksLikeRefusal($rewritten, $original)) {
            return false;
        }

        $sourceLen = \strlen($original);

        return !($sourceLen >= self::MIN_SOURCE_LENGTH_FOR_RATIO
            && \strlen($rewritten) < $sourceLen * self::MIN_LENGTH_RATIO);
    }
}
