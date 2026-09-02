<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Webwerkwien\ContaoAiBackendBundle\Exception\AiConfigException;

/**
 * The list of AI providers, derived from what is installed instead of maintained.
 *
 * `composer require symfony/ai-mistral-platform` and Mistral is in the dropdown.
 * No bridge class, no `options` entry, no code change — the form asks for
 * whatever that bridge's own factory signature demands.
 *
 * Same principle the core bundle already applies with `wrapped_commands()`:
 * a hand-kept list is the one that goes stale, and it goes stale quietly.
 *
 * ## Explicit bridges still win
 *
 * A service implementing {@see PlatformBridgeInterface} overrides the derived
 * entry for its key. That is how `anthropic` and `openai` keep their curated
 * default model — the factory signature knows the endpoint, not which model a
 * given site should spend money on.
 */
class PlatformRegistry
{
    /** @var array<string, PlatformDescriptor>|null */
    private ?array $descriptors = null;

    /**
     * Display names for the providers we actually have an opinion about.
     * Anything else falls back to the bridge's own key — a missing label is
     * cosmetic, and inventing one would be a claim we cannot back.
     */
    private const LABELS = [
        'anthropic'     => 'Anthropic (Claude)',
        'openai'        => 'OpenAI (GPT)',
        'gemini'        => 'Google Gemini',
        'mistral'       => 'Mistral',
        'openrouter'    => 'OpenRouter',
        'ollama'        => 'Ollama (selbst gehostet)',
        'lmstudio'      => 'LM Studio (selbst gehostet)',
        'dockermodelrunner' => 'Docker Model Runner (selbst gehostet)',
        'generic'       => 'Generic (OpenAI-kompatibel)',
        'openresponses' => 'OpenAI Responses (kompatibel)',
        'cerebras'      => 'Cerebras',
        'deepseek'      => 'DeepSeek',
        'perplexity'    => 'Perplexity',
        'cohere'        => 'Cohere',
    ];

    /**
     * Packages that match the naming pattern but are not provider bridges.
     * `symfony/ai-platform` is the base package; the others compose or decorate
     * other platforms and have nothing to ask a user for.
     */
    private const NOT_A_PROVIDER = [
        'symfony/ai-platform',
        'symfony/ai-cache-platform',
        'symfony/ai-failover-platform',
        'symfony/ai-models-dev-platform',
    ];

    /**
     * @param iterable<PlatformBridgeInterface> $explicitBridges
     */
    public function __construct(
        #[TaggedIterator('contao_ai_backend.platform_bridge')]
        private readonly iterable $explicitBridges = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Why a package that looks like a bridge did not become one.
     *
     * M-1: every rejection below used to be a bare `return null`. A rename
     * upstream — `PlatformFactory` became `Factory` in symfony/ai 0.13 — would
     * therefore empty the dropdown in production without a single log line. That
     * exact rename already cost an hour this morning, and only a counter in a
     * throwaway script revealed it. On a customer install there is no counter.
     */
    private function skip(string $package, string $reason): null
    {
        $this->logger->warning('contao-ai-backend: platform package skipped', [
            'package' => $package,
            'reason'  => $reason,
        ]);

        return null;
    }

    /**
     * @return array<string, PlatformDescriptor> keyed by platform key
     */
    public function all(): array
    {
        if (null === $this->descriptors) {
            $this->descriptors = $this->discover();
        }

        return $this->descriptors;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): PlatformDescriptor
    {
        $all = $this->all();

        if (!isset($all[$key])) {
            throw new AiConfigException(\sprintf(
                'Unbekannte KI-Plattform "%s". Verfügbar: %s',
                $key,
                implode(', ', array_keys($all)) ?: '(keine installiert)',
            ));
        }

        return $all[$key];
    }

    /**
     * Options for the DCA select: key => label.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $out = [];

        foreach ($this->all() as $key => $descriptor) {
            $out[$key] = $descriptor->label;
        }

        asort($out);

        return $out;
    }

    /**
     * Build a platform for one user's stored credentials.
     *
     * Arguments are passed by name, so every parameter we do not care about
     * (`cacheRetention`, `region`, `completionsPath`, …) keeps the bridge's own
     * default. That is deliberate: those are the bridge's business, and naming
     * them here would freeze today's signature into our code.
     */
    public function createPlatform(
        string $key,
        #[\SensitiveParameter] string $apiKey = '',
        ?string $baseUrl = null,
    ): PlatformInterface {
        $descriptor = $this->get($key);

        // A hand-written bridge only builds the platform when no installed
        // package can — otherwise the package's factory wins.
        //
        // 🎯 The first draft of this class did the opposite and let any bridge
        // take over. That would have made a stored base URL silently ineffective
        // for `anthropic` and `openai`, because PlatformBridgeInterface takes
        // nothing but a key. A field that accepts input and changes nothing is
        // worse than a field that is not there.
        if (!$descriptor->isDerived()) {
            foreach ($this->explicitBridges as $bridge) {
                if ($bridge->getName() === $key) {
                    return $bridge->createPlatform($apiKey);
                }
            }
        }

        if ($descriptor->apiKeyRequired && '' === $apiKey) {
            throw new AiConfigException(\sprintf(
                'Die Plattform "%s" braucht einen API-Schlüssel.',
                $descriptor->label,
            ));
        }

        if ($descriptor->baseUrlRequired && (null === $baseUrl || '' === $baseUrl)) {
            throw new AiConfigException(\sprintf(
                'Die Plattform "%s" braucht eine Endpunkt-Adresse.',
                $descriptor->label,
            ));
        }

        // 🔴 H-3 (Fable review, 2026-09-02). Both branches below used to *skip*
        // silently when the provider had no such parameter. Concretely: OpenAI's
        // factory takes no `baseUrl`, so a user could enter a gateway address,
        // save without error, and have every request — key included — go to
        // api.openai.com anyway.
        //
        // That is verbatim the failure this class already guards against a few
        // lines up for bridge classes, where the comment reads *"A field that
        // accepts input and changes nothing is worse than a field that is not
        // there."* I wrote that sentence and then left the derived path open.
        if ('' !== $apiKey && !$descriptor->acceptsApiKey()) {
            throw new AiConfigException(\sprintf(
                'Die Plattform "%s" nimmt keinen API-Schlüssel. Leere das Feld, sonst bleibt es wirkungslos.',
                $descriptor->label,
            ));
        }

        if (null !== $baseUrl && '' !== $baseUrl && !$descriptor->acceptsBaseUrl()) {
            throw new AiConfigException(\sprintf(
                'Die Plattform "%s" hat einen festen Endpunkt und nimmt keine eigene Adresse. Leere das Feld, sonst bleibt es wirkungslos.',
                $descriptor->label,
            ));
        }

        $args = [];

        if ($descriptor->acceptsApiKey() && '' !== $apiKey) {
            $args[$descriptor->apiKeyParam] = $apiKey;
        }

        if ($descriptor->acceptsBaseUrl() && null !== $baseUrl && '' !== $baseUrl) {
            $args[$descriptor->baseUrlParam] = $baseUrl;
        }

        if (null === $descriptor->factoryClass) {
            throw new AiConfigException(\sprintf(
                'Für "%s" gibt es weder ein installiertes Bridge-Paket noch eine Bridge-Klasse.',
                $descriptor->label,
            ));
        }

        /** @var callable $factory */
        $factory = [$descriptor->factoryClass, 'createPlatform'];

        // Calling foreign code by reflection with named arguments. `describe()`
        // filters out signatures we cannot satisfy, but that check reads today's
        // upstream; a future release can add a required parameter at any time.
        // Without this, such a change surfaces as an uncaught ArgumentCountError
        // — an HTTP 500 with a stack trace instead of a sentence.
        try {
            return $factory(...$args);
        } catch (\Throwable $e) {
            throw new AiConfigException(\sprintf(
                'Die Plattform "%s" ließ sich nicht aufbauen (%s: %s).',
                $descriptor->label,
                $e::class,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * @return array<string, PlatformDescriptor>
     */
    private function discover(): array
    {
        $found = [];

        foreach ($this->installedPlatformPackages() as $package) {
            $descriptor = $this->describe($package);

            if (null !== $descriptor) {
                $found[$descriptor->key] = $descriptor;
            }
        }

        // An explicit bridge may name a key the derived scan did not produce
        // (a hand-written platform, or one whose package is absent). It must
        // still appear, otherwise adding a bridge class would silently do
        // nothing — the failure mode this class exists to remove.
        foreach ($this->explicitBridges as $bridge) {
            $key = $bridge->getName();

            if (isset($found[$key])) {
                $found[$key] = new PlatformDescriptor(
                    key:             $key,
                    label:           $found[$key]->label,
                    factoryClass:    $found[$key]->factoryClass,
                    package:         $found[$key]->package,
                    apiKeyParam:     $found[$key]->apiKeyParam,
                    apiKeyRequired:  $found[$key]->apiKeyRequired,
                    baseUrlParam:    $found[$key]->baseUrlParam,
                    baseUrlRequired: $found[$key]->baseUrlRequired,
                    baseUrlDefault:  $found[$key]->baseUrlDefault,
                    defaultModel:    $bridge->getDefaultModel(),
                );
                continue;
            }

            $found[$key] = new PlatformDescriptor(
                key:            $key,
                label:          self::LABELS[$key] ?? $key,
                factoryClass:   null,
                package:        '(bridge class)',
                apiKeyParam:    'apiKey',
                apiKeyRequired: true,
                defaultModel:   $bridge->getDefaultModel(),
            );
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function installedPlatformPackages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (!str_starts_with($package, 'symfony/ai-') || !str_ends_with($package, '-platform')) {
                continue;
            }

            if (\in_array($package, self::NOT_A_PROVIDER, true)) {
                continue;
            }

            $packages[] = $package;
        }

        sort($packages);

        return $packages;
    }

    private function describe(string $package): ?PlatformDescriptor
    {
        $namespace = $this->namespaceOf($package);

        if (null === $namespace) {
            return $this->skip($package, 'no PSR-4 namespace in the package composer.json');
        }

        $class = $namespace.'Factory';

        if (!class_exists($class)) {
            return $this->skip($package, \sprintf('%s does not exist', $class));
        }

        if (!method_exists($class, 'createPlatform')) {
            return $this->skip($package, \sprintf('%s has no createPlatform()', $class));
        }

        $key            = null;
        $apiKeyParam    = null;
        $apiKeyRequired = false;
        $baseUrlParam   = null;
        $baseUrlRequired = false;
        $baseUrlDefault = null;

        foreach ((new \ReflectionMethod($class, 'createPlatform'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $type = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            if ('string' !== $type) {
                continue;
            }

            $name     = $parameter->getName();
            $lowered  = strtolower($name);
            $hasValue = $parameter->isDefaultValueAvailable() || $parameter->allowsNull();
            $default  = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            // H-4: a scalar we neither recognise nor can fill. Cartesia's factory
            // wants `string $version` next to the key; offering it would put a
            // provider in the dropdown that ends in ArgumentCountError — an
            // HTTP 500, because the controller only catches AiConfigException.
            if (!$hasValue && 'name' !== $lowered && !str_contains($lowered, 'apikey')
                && 'endpoint' !== $lowered && !str_contains($lowered, 'baseurl')
            ) {
                return $this->skip($package, \sprintf(
                    'createPlatform() requires "%s", which this bundle cannot supply',
                    $name,
                ));
            }

            if ('name' === $lowered) {
                // The bridge's own canonical key — the value already stored in
                // tl_user.ai_platform. Read, never guessed.
                if (\is_string($default) && '' !== $default) {
                    $key = $default;
                }
                continue;
            }

            if (str_contains($lowered, 'apikey')) {
                $apiKeyParam    = $name;
                $apiKeyRequired = !$hasValue;
                continue;
            }

            if ('endpoint' === $lowered || str_contains($lowered, 'baseurl')) {
                $baseUrlParam    = $name;
                $baseUrlRequired = !$hasValue;
                $baseUrlDefault  = \is_string($default) ? $default : null;
            }
        }

        if (null === $key) {
            return $this->skip($package, 'createPlatform() has no `name` default to use as the platform key');
        }

        // M-2: a bridge with neither a key nor an endpoint has nothing a user
        // could fill in. Bedrock takes its credentials from the AWS environment
        // and TransformersPHP runs in-process — both are application settings,
        // not per-user ones, and this bundle stores per user. Showing them means
        // offering a choice that cannot be configured.
        if (null === $apiKeyParam && null === $baseUrlParam) {
            return $this->skip($package, 'nothing to configure per user (neither an API key nor an endpoint)');
        }

        return new PlatformDescriptor(
            key:             $key,
            label:           self::LABELS[$key] ?? $key,
            factoryClass:    $class,
            package:         $package,
            apiKeyParam:     $apiKeyParam,
            apiKeyRequired:  $apiKeyRequired,
            baseUrlParam:    $baseUrlParam,
            baseUrlRequired: $baseUrlRequired,
            baseUrlDefault:  $baseUrlDefault,
        );
    }

    /**
     * PSR-4 prefix from the package's own composer.json.
     *
     * Deriving it from the package name would be a guess that mostly works —
     * `ai-lm-studio-platform` is `LmStudio`, `ai-open-ai-platform` is `OpenAi`
     * — and "mostly works" is how a registry starts lying.
     */
    private function namespaceOf(string $package): ?string
    {
        $path = InstalledVersions::getInstallPath($package);

        if (null === $path || !is_file($path.'/composer.json')) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path.'/composer.json'), true);

        if (!\is_array($manifest)) {
            return null;
        }

        $psr4 = $manifest['autoload']['psr-4'] ?? [];

        if (!\is_array($psr4) || [] === $psr4) {
            return null;
        }

        return (string) array_key_first($psr4);
    }
}
