<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Util;

use Survos\MeiliBundle\Service\SettingsService;

final class TextFieldResolver
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Prefer fields tagged as #[Translatable], fall back to #[Searchable] if present.
     * @param class-string $class
     * @return list<string>
     */
    public function resolveSearchable(string $class): array
    {
        $cfg = $this->settings->getSettingsFromAttributes($class);

        $translatable = $this->settings->getFieldsWithAttribute($cfg, 'translatable');
        if (!empty($translatable)) {
            return array_values(array_unique($translatable));
        }

        $searchable = $this->settings->getFieldsWithAttribute($cfg, 'searchable');
        if (!array_is_list($searchable)) {
            // ['name' => 'partial', 'code' => 'exact']
            $searchable = array_keys($searchable);
        }
        return array_values(array_unique($searchable));
    }
}
