<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class IndexNameResolver
{
    private ?bool $anyTargetsCache = null;

    public function __construct(
        private readonly MeiliRegistry $registry,
        private readonly ParameterBagInterface $bag,
        private readonly MeiliServiceConfig $cfg,
    ) {
    }

    /**
     * Global multilingual mode:
     * - explicit config switch OR
     * - any registered base index declares target locales
     */
    public function isMultiLingual(): bool
    {
        return $this->cfg->multiLingual || $this->anyIndexHasTargets();
    }

    /**
     * Per-base multilingual mode:
     * - explicit config switch OR
     * - this base index declares target locales
     */
    public function isMultiLingualFor(string $baseName, string $fallbackSource): bool
    {
        if ($this->cfg->multiLingual) {
            return true;
        }

        $cfg = $this->registry->settingsFor($baseName) ?? [];
        [$source, $targets] = $this->extractLocalesMeta($cfg, $fallbackSource);

        return $targets !== [];
    }

    public function passLocale(): bool
    {
        return $this->cfg->passLocale;
    }

    /**
     * Resolve locale policy for a base index key.
     *
     * Rules:
     *  1) Prefer registry metadata: ['locales' => ['source'=>..,'targets'=>[..]]]
     *  2) If multilingual (global/per-base) but no metadata targets, fall back to enabled_locales - source.
     *  3) If not multilingual, return only the source locale.
     *
     * @return array{source:string, targets:string[], all:string[]}
     */
    public function localesFor(string $baseName, string $fallbackSource): array
    {
        $fallbackSource = $this->normLocale($fallbackSource) ?? 'en';

        $cfg = $this->registry->settingsFor($baseName) ?? [];
        [$source, $targets] = $this->extractLocalesMeta($cfg, $fallbackSource);

        $isMlFor = $this->isMultiLingualFor($baseName, $fallbackSource);

        if ($isMlFor && $targets === []) {
            $enabled = $this->enabledLocales();
            $targets = array_values(array_diff($enabled, [$source]));
        }

        if (!$isMlFor) {
            $targets = [];
        }

        $all = array_values(array_unique(array_merge([$source], $targets)));

        return ['source' => $source, 'targets' => $targets, 'all' => $all];
    }

    /**
     * base + locale -> raw (unprefixed)
     */
    public function rawFor(string $baseName, ?string $locale, ?bool $isMultilingual = null): string
    {
        $locale = $this->normLocale($locale) ?? '';

        $ml = $isMultilingual ?? $this->isMultiLingual();
        if (!$ml || $locale === '') {
            return $baseName;
        }

        return sprintf('%s_%s', $baseName, $locale);
    }

    /**
     * raw -> uid (prefix applied once, centrally)
     */
    public function uidForRaw(string $rawName): string
    {
        return $this->registry->uidFor($rawName);
    }

    /**
     * base + locale -> uid
     */
    public function uidFor(string $baseName, ?string $locale, ?bool $isMultilingual = null): string
    {
        $raw = $this->rawFor($baseName, $locale, $isMultilingual);
        return $this->uidForRaw($raw);
    }

    // ---------------------------------------------------------------------

    private function anyIndexHasTargets(): bool
    {
        if ($this->anyTargetsCache !== null) {
            return $this->anyTargetsCache;
        }

        foreach ($this->registry->names() as $baseName) {
            $cfg = $this->registry->settingsFor($baseName) ?? [];
            [, $targets] = $this->extractLocalesMeta($cfg, $this->defaultLocale());
            if ($targets !== []) {
                return $this->anyTargetsCache = true;
            }
        }

        return $this->anyTargetsCache = false;
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array{0:string,1:string[]} source, targets
     */
    private function extractLocalesMeta(array $cfg, string $fallbackSource): array
    {
        $meta = $cfg['locales'] ?? null;
        if (!is_array($meta)) {
            return [$fallbackSource, []];
        }

        $source = $this->normLocale($meta['source'] ?? null) ?? $fallbackSource;

        $targets = $meta['targets'] ?? [];
        $targets = is_array($targets) ? $targets : [];
        $targets = array_values(array_unique(array_filter(array_map(
            fn($v) => $this->normLocale((string)$v),
            $targets
        ), fn($v) => is_string($v) && $v !== '' && $v !== $source)));

        return [$source, $targets];
    }

    private function normLocale(?string $locale): ?string
    {
        $locale = $locale !== null ? trim($locale) : null;
        if ($locale === '') {
            return null;
        }
        return strtolower($locale);
    }

    private function defaultLocale(): string
    {
        $v = $this->bag->has('kernel.default_locale') ? (string)$this->bag->get('kernel.default_locale') : 'en';
        return $this->normLocale($v) ?? 'en';
    }

    /**
     * @return string[]
     */
    private function enabledLocales(): array
    {
        foreach (['kernel.enabled_locales', 'enabled_locales'] as $param) {
            if (!$this->bag->has($param)) {
                continue;
            }
            $v = $this->bag->get($param);

            $enabled = null;
            if (is_array($v)) {
                $enabled = $v;
            } elseif (is_string($v) && trim($v) !== '') {
                $enabled = str_contains($v, '|') ? explode('|', $v) : explode(',', $v);
            }

            if ($enabled === null) {
                continue;
            }

            $enabled = array_values(array_unique(array_filter(array_map(
                fn($x) => $this->normLocale((string)$x),
                $enabled
            ), fn($x) => is_string($x) && $x !== '')));

            return $enabled;
        }

        return [];
    }
}
