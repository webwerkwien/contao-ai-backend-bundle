<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Widget;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;

/**
 * The field rights of the back end ("Erlaubte Felder", `alexf`) for the chat's writes.
 *
 * Contao's rule, identical in 5.3, 5.7 and 6.0: a field is editable when it is not
 * excluded, or when the user is granted it. `DataContainer::isFieldExcluded()` counts
 * every field with an input as excluded unless its DCA says `'exclude' => false`, so in
 * practice an editor needs each field granted in their group. `DC_Table` checks exactly
 * this before it shows or saves a field (`isFieldExcluded() && !isGranted(alexf)`); the
 * record voters do not, which is why this check exists next to DataContainerAccess.
 *
 * Until v0.17.0 the chat checked module and record access and a fixed field list per
 * tool, but not these rights (only `language_link` did). An editor whose group grants
 * no page field could publish, rename or re-language pages through the chat.
 *
 * Creating follows the back end too: a new record gets its defaults without any right,
 * so a value equal to the field's default needs none. Any other value does.
 */
class FieldPermission
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string> the fields of $fields this user may not write
     */
    public function denied(BackendUser $user, string $table, array $fields): array
    {
        if ($user->isAdmin || [] === $fields) {
            return [];
        }

        $this->loadDca($table);

        // Closed when the rule cannot be read: without a DCA `isFieldExcluded()` answers
        // false for every field, and a field the DCA does not know cannot be judged.
        $definitions = $GLOBALS['TL_DCA'][$table]['fields'] ?? null;

        if (!\is_array($definitions)) {
            throw new ToolAccessDeniedException(\sprintf('Die Feldrechte für %s lassen sich nicht prüfen (keine DCA geladen). Es wurde nichts geschrieben.', $table));
        }

        return array_values(array_filter(
            $fields,
            fn (string $field): bool => !isset($definitions[$field])
                || (DataContainer::isFieldExcluded($table, $field)
                    && !$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, $table . '::' . $field)),
        ));
    }

    /**
     * @param array<string, scalar|null> $values field => value of a record being created
     *
     * @return list<string> the fields whose value needs a right this user lacks
     */
    public function deniedOnCreate(BackendUser $user, string $table, array $values): array
    {
        if ($user->isAdmin || [] === $values) {
            return [];
        }

        $this->loadDca($table);

        $set = array_keys(array_filter(
            $values,
            // null is not sent at all (AbstractCoreCommandTool::runCommand() drops it)
            fn (mixed $value, string $field): bool => null !== $value && !$this->isDefault($table, $field, $value),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $this->denied($user, $table, $set);
    }

    /**
     * @param list<string> $fields
     *
     * @throws ToolAccessDeniedException naming every field without a right
     */
    public function assertWritable(BackendUser $user, string $table, array $fields): void
    {
        $this->refuse($table, $this->denied($user, $table, $fields));
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @throws ToolAccessDeniedException naming every field without a right
     */
    public function assertCreatable(BackendUser $user, string $table, array $values): void
    {
        $this->refuse($table, $this->deniedOnCreate($user, $table, $values));
    }

    /**
     * The fields of the table that hold a value with a unit (`inputUnit`: a content
     * element's headline and its level). A write may name the unit as `<field>_unit`,
     * which the core folds into the field (v0.19.2, see AbstractCoreCommandTool::writeFields()).
     *
     * @return list<string>
     */
    public function inputUnitFields(string $table): array
    {
        $this->loadDca($table);

        $definitions = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        return \is_array($definitions)
            ? array_map('strval', array_keys(array_filter(
                $definitions,
                static fn (mixed $definition): bool => \is_array($definition) && 'inputUnit' === ($definition['inputType'] ?? null),
            )))
            : [];
    }

    /**
     * The fields of the table whose widget stores a list: an image size
     * (`[width, height, mode]`) and the multiple choices. A model sends their value as a
     * list, which goes to the core as JSON (v1.0.0, see AbstractCoreCommandTool::writeFields()).
     *
     * @return list<string>
     */
    public function listFields(string $table): array
    {
        $this->loadDca($table);

        $definitions = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

        return \is_array($definitions)
            ? array_map('strval', array_keys(array_filter(
                $definitions,
                static fn (mixed $definition): bool => \is_array($definition) && self::storesList($definition),
            )))
            : [];
    }

    /**
     * Whether a field stores a flat list of plain values, the one shape a list from the
     * model is turned into. The core decodes JSON for exactly the fields its
     * `StructuredFields::storesArray()` names, so this is a part of that rule, never
     * more (ListFieldsMatchCoreTest): `eval.csv` stores a comma-separated string,
     * `fileTree` takes UUIDs, and the wizards that store rows or pairs are left out.
     *
     * @param array<mixed> $definition
     */
    public static function storesList(array $definition): bool
    {
        $type = $definition['inputType'] ?? null;

        if ('fileTree' === $type) {
            return false;
        }

        if ('imageSize' === $type || 'listWizard' === $type) {
            return true;
        }

        $eval = \is_array($definition['eval'] ?? null) ? $definition['eval'] : [];

        return (bool) ($eval['multiple'] ?? false) && !isset($eval['csv']);
    }

    /**
     * @param list<string> $denied
     */
    private function refuse(string $table, array $denied): void
    {
        if ([] === $denied) {
            return;
        }

        $known   = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $unknown = array_values(array_filter($denied, static fn (string $field): bool => !isset($known[$field])));
        $locked  = array_values(array_diff($denied, $unknown));
        $name    = static fn (string $field): string => $table . '.' . $field;
        $reasons = [];

        if ([] !== $locked) {
            $reasons[] = \sprintf(
                'Keine Berechtigung für %s: %s. Diese Felder sind für diesen Benutzer nicht freigegeben (Benutzergruppe, „Erlaubte Felder").',
                1 === \count($locked) ? 'das Feld' : 'die Felder',
                implode(', ', array_map($name, $locked)),
            );
        }

        if ([] !== $unknown) {
            $reasons[] = \sprintf('Unbekannt in dieser Installation: %s.', implode(', ', array_map($name, $unknown)));
        }

        throw new ToolAccessDeniedException(implode(' ', $reasons) . ' Es wurde nichts geschrieben.');
    }

    /**
     * A value counts as the default when it equals the DCA `default` or, without one, the
     * empty value of the column. A closure default depends on a data container this path
     * has not, so it never counts as met.
     */
    private function isDefault(string $table, string $field, mixed $value): bool
    {
        $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? [];

        if (\array_key_exists('default', $definition)) {
            $default = $definition['default'];

            return !$default instanceof \Closure && \is_scalar($default) && (string) $default === (string) $value;
        }

        // The column's own default is what the insert writes (tl_page.type 'regular',
        // tl_content.type 'text', tl_article.inColumn 'main').
        $sql = $definition['sql'] ?? [];

        if (\is_array($sql) && \array_key_exists('default', $sql) && \is_scalar($sql['default']) && !\is_bool($sql['default'])) {
            return (string) $sql['default'] === (string) $value;
        }

        if (\is_string($sql) && preg_match("/\\bdefault\\s+'([^']*)'/i", $sql, $match)) {
            return $match[1] === (string) $value;
        }

        // A boolean column: the chat sends false as '', '0' or 0 and true as '1' or 1. Its
        // default is false unless the column says otherwise (tl_page.useSSL is true).
        if (\is_array($sql) && 'boolean' === ($sql['type'] ?? null)) {
            return \in_array($value, empty($sql['default']) ? [null, false, 0, '0', ''] : [true, 1, '1'], true);
        }

        return (string) Widget::getEmptyValueByFieldType($sql) === (string) $value;
    }

    private function loadDca(string $table): void
    {
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
    }
}
