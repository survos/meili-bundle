<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Controller;

use Survos\MeiliBundle\Registry\MeiliRegistry;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use function count;
use function implode;
use function is_array;
use function strrpos;
use function substr;

#[Route('/meili')]
class MeiliRegistryController extends AbstractController
{
    public function __construct(
        private readonly MeiliRegistry $registry,
        private readonly IndexNameResolver $resolver,
        private readonly IndexInfoRepository $indexInfoRepository,
        private readonly ?ChatWorkspaceResolver $chatWorkspaceResolver = null,
    ) {
    }

    #[Route('/registry', name: 'meili_registry')]
    public function registry(
        MeiliService $meili,
    ): Response {
        $locale = 'en';

        $rows = [];
        foreach ($this->registry->names() as $baseName) {
            $cfg = $this->registry->settingsFor($baseName) ?? [];
            $ui = (array)($cfg['ui'] ?? []);
            $label = (string)($ui['label'] ?? '');
            if ($label === '') {
                $class = (string)($cfg['class'] ?? $this->registry->classFor($baseName) ?? '');
                $label = $class ? $this->shortClass($class) : '';
            }

            $loc = $this->resolver->localesFor($baseName, $locale);
            $isMlFor = $this->resolver->isMultiLingualFor($baseName, $locale);

            $persisted = is_array($cfg['persisted'] ?? null) ? $cfg['persisted'] : [];
            $persistedGroups = isset($persisted['groups']) && is_array($persisted['groups'])
                ? implode(',', $persisted['groups'])
                : '';
            $persistedFieldsCount = isset($persisted['fields']) && is_array($persisted['fields'])
                ? count($persisted['fields'])
                : 0;

            // Get the raw index name for the primary locale
            $primaryLocale = $loc['source'] ?: $locale;
            $rawIndexName = $this->resolver->rawFor($baseName, $primaryLocale, $isMlFor);

            // Get workspaces for this specific index
            $workspaces = [];
            if ($this->chatWorkspaceResolver) {
                $workspaces = $this->chatWorkspaceResolver->workspaceTemplatesForIndex($rawIndexName);
            }

            $rows[] = [
                'baseName' => $baseName,
                'label' => $label,
                'indexName' => $rawIndexName,
                'workspaces' => $workspaces,
                'multilingual' => $isMlFor ? 'yes' : 'no',
                'locale' => $loc['source'],
                'targetLocales' => $loc['targets'] ? implode(',', $loc['targets']) : '',
                'primaryKey' => (string)($cfg['primaryKey'] ?? ''),
                'persistedGroups' => $persistedGroups,
                'persistedFieldsCount' => (string)$persistedFieldsCount,
            ];
        }

        $dbRows = [];
        foreach ($this->indexInfoRepository->findAll() as $info) {
            $dbRows[] = [
                'indexName' => $info->indexName,
                'primaryKey' => $info->primaryKey,
                'documentCount' => (string)$info->documentCount,
                'updatedAt' => $info->updatedAt?->format('Y-m-d H:i:s') ?? '',
                'status' => $info->status ?? '',
                'searchKey' => $info->hasSearchApiKey() ? 'yes' : 'no',
                'chatKeys' => (string) $info->chatWorkspaceKeyCount(),
            ];
        }

        return $this->render('@SurvosMeili/registry.html.twig', [
            'locale' => $locale,
            'multilingual' => $this->resolver->isMultiLingual() ? 'yes' : 'no',
            'rows' => $rows,
            'dbRows' => $dbRows,
        ]);
    }

    private function shortClass(string $fqcn): string
    {
        $p = strrpos($fqcn, '\\');
        return $p === false ? $fqcn : substr($fqcn, $p + 1);
    }
}
