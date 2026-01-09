<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Survos\BabelBundle\Service\TranslatableIndex;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\MeiliBundle\Model\IndexTarget;

/**
 * Computes which indexes should exist for a base key.
 * Used by CLI and UI.
 */
final class TargetPlanner
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly IndexNameResolver $resolver,
        private readonly ?TranslatableIndex $translatableIndex = null,
        private readonly array $enabledLocales = [],
        private readonly string $defaultLocale = 'en',
    ) {}

    /**
     * @param list<string> $onlyLocales
     * @return list<IndexTarget>
     */
    public function targetsForBase(string $baseKey, bool $perLocale, array $onlyLocales = []): array
    {
        $settings = $this->meili->getIndexSetting($baseKey);
        if (!$settings || !isset($settings['class'])) {
            return [];
        }

        $class = (string) $settings['class'];

        $fallbackEnabled = array_values(array_unique(array_filter(array_map(
            static fn($l) => HashUtil::normalizeLocale((string) $l),
            $this->enabledLocales
        ))));

        $baseLocale = HashUtil::normalizeLocale($this->defaultLocale);
        $targets    = $fallbackEnabled;

        if ($this->translatableIndex && $this->translatableIndex->has($class)) {
            $src = $this->translatableIndex->sourceLocaleFor($class);
            $baseLocale = HashUtil::normalizeLocale($src ?: $this->defaultLocale);
            $targets = $this->translatableIndex->effectiveTargetLocalesFor($class, $fallbackEnabled);
        }

        $targets = array_values(array_unique(array_filter(array_map(
            static fn($l) => HashUtil::normalizeLocale((string) $l),
            $targets
        ))));

        $targets = array_values(array_filter($targets, fn(string $l) => $l !== '' && $l !== $baseLocale));

        if ($onlyLocales !== []) {
            $only = array_flip($onlyLocales);
            $targets = array_values(array_filter($targets, static fn(string $l) => isset($only[$l])));
        }

        $fallbackSource = $baseLocale ?: HashUtil::normalizeLocale($this->defaultLocale);
        $isMlFor = $this->resolver->isMultiLingualFor($baseKey, $fallbackSource);

        $out = [];

        if ($perLocale && $isMlFor) {
            $allLocales = array_values(array_unique(array_filter(array_merge([$baseLocale], $targets))));
            foreach ($allLocales as $loc) {
                $uid = $this->resolver->uidFor($baseKey, $loc, true);
                $out[] = new IndexTarget(
                    base: $baseKey,
                    uid: $uid,
                    class: $class,
                    locale: $loc,
                    kind: ($loc === $baseLocale) ? 'source' : 'target',
                );
            }
        } else {
            $uid = $this->resolver->uidFor($baseKey, null, false);
            $out[] = new IndexTarget(
                base: $baseKey,
                uid: $uid,
                class: $class,
                locale: $baseLocale,
                kind: 'base',
            );
        }

        return $out;
    }

    /**
     * @param list<string> $bases
     * @param list<string> $onlyLocales
     * @return list<IndexTarget>
     */
    public function targetsForBases(array $bases, bool $perLocale, array $onlyLocales = []): array
    {
        $all = [];
        foreach ($bases as $baseKey) {
            foreach ($this->targetsForBase($baseKey, $perLocale, $onlyLocales) as $t) {
                $all[] = $t;
            }
        }
        return $all;
    }
}
