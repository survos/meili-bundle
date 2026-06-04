<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Service;

use Survos\MeiliBundle\Entity\IndexInfo;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use function array_key_first;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function str_ends_with;

final class ChatWorkspaceResolver
{
    /**
     * @param array<string,mixed> $chatConfig
     */
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly array $chatConfig = [],
        private readonly ?IndexInfoRepository $indexInfoRepository = null,
    ) {
    }

    public function actualWorkspaceName(string $templateWorkspace, string $indexUid): string
    {
        return $indexUid . '_' . $templateWorkspace;
    }

    public function workspaceForIndex(string $indexUid): ?string
    {
        $templates = $this->workspaceTemplatesForIndex($indexUid);
        if ($templates === []) {
            return null;
        }

        return $this->actualWorkspaceName($templates[0], $indexUid);
    }

    public function resolveRequestedWorkspace(string $indexUid, string $workspace): ?string
    {
        foreach ($this->workspaceTemplatesForIndex($indexUid) as $templateWorkspace) {
            $actualWorkspace = $this->actualWorkspaceName($templateWorkspace, $indexUid);
            if ($workspace === $templateWorkspace || $workspace === $actualWorkspace) {
                return $actualWorkspace;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function workspaceTemplatesForIndex(string $indexUid): array
    {
        $templates = [];
        $allSettings = $this->meiliService->getAllSettings();
        foreach ($allSettings as $baseName => $settings) {
            $resolvedUid = $this->indexNameResolver->uidFor($baseName, null);
            if ($resolvedUid !== $indexUid && $baseName !== $indexUid && !str_ends_with($indexUid, '_' . $baseName)) {
                continue;
            }

            foreach ($settings['chats'] ?? [] as $workspaceName) {
                if (is_string($workspaceName) && isset($this->chatConfig['workspaces'][$workspaceName])) {
                    $templates[] = $workspaceName;
                }
            }
        }

        foreach ($this->chatConfig['workspaces'] ?? [] as $name => $cfg) {
            if (is_array($cfg['indexes'] ?? null) && in_array($indexUid, $cfg['indexes'], true)) {
                $templates[] = (string) $name;
            }
        }

        foreach ($this->registryChatWorkspaceNames($indexUid) as $actualWorkspace) {
            $template = $this->templateFromActualWorkspace($actualWorkspace, $indexUid);
            if ($template !== null && isset($this->chatConfig['workspaces'][$template])) {
                $templates[] = $template;
            }
        }

        if ($templates !== []) {
            return array_values(array_unique($templates));
        }

        $workspaces = $this->chatConfig['workspaces'] ?? [];
        $default = $workspaces !== [] ? array_key_first($workspaces) : null;

        return is_string($default) ? [$default] : [];
    }

    /**
     * @param array<string,mixed> $workspaceCfg
     * @return list<string>
     */
    public function resolveWorkspaceIndexes(string $workspace, array $workspaceCfg): array
    {
        $rawSettings = $this->meiliService->getRawIndexSettings();
        $indexUidsFromAttribute = [];
        foreach ($rawSettings as $baseName => $settings) {
            if (in_array($workspace, $settings['chats'] ?? [], true)) {
                $indexUidsFromAttribute[] = $this->indexNameResolver->uidFor($baseName, null);
            }
        }

        $indexUidsFromRegistry = $this->resolveWorkspaceIndexesFromRegistry($workspace);

        /** @var list<string> $legacyUids */
        $legacyUids = $workspaceCfg['indexes'] ?? [];

        $resolved = array_values(array_unique(array_merge($indexUidsFromAttribute, $indexUidsFromRegistry, $legacyUids)));
        if ($resolved !== []) {
            return $resolved;
        }

        $allIndexUids = [];
        foreach (array_keys($rawSettings) as $baseName) {
            if (!is_string($baseName) || $baseName === '') {
                continue;
            }
            $allIndexUids[] = $this->indexNameResolver->uidFor($baseName, null);
        }

        return array_values(array_unique($allIndexUids));
    }
    /**
     * @return list<string>
     */
    private function resolveWorkspaceIndexesFromRegistry(string $workspace): array
    {
        if ($this->indexInfoRepository === null) {
            return [];
        }

        $indexUids = [];
        foreach ($this->indexInfoRepository->findAll() as $indexInfo) {
            if (!$indexInfo instanceof IndexInfo) {
                continue;
            }

            foreach ($this->registryChatWorkspaceNames($indexInfo->indexName) as $actualWorkspace) {
                if ($actualWorkspace === $workspace || $this->templateFromActualWorkspace($actualWorkspace, $indexInfo->indexName) === $workspace) {
                    $indexUids[] = $indexInfo->indexName;
                }
            }
        }

        return array_values(array_unique($indexUids));
    }

    /**
     * @return list<string>
     */
    private function registryChatWorkspaceNames(string $indexUid): array
    {
        $indexInfo = $this->indexInfoRepository?->find($indexUid);
        if (!$indexInfo instanceof IndexInfo) {
            return [];
        }

        $registry = $indexInfo->settings['_registry'] ?? null;
        if (!is_array($registry)) {
            return [];
        }

        $workspaces = $registry['chatWorkspaces'] ?? null;
        if (!is_array($workspaces)) {
            return [];
        }

        $names = [];
        foreach (array_keys($workspaces) as $workspaceName) {
            if (is_string($workspaceName) && $workspaceName !== '') {
                $names[] = $workspaceName;
            }
        }

        return array_values(array_unique($names));
    }

    private function templateFromActualWorkspace(string $actualWorkspace, string $indexUid): ?string
    {
        $prefix = $indexUid . '_';
        if (!str_starts_with($actualWorkspace, $prefix)) {
            return null;
        }

        $template = substr($actualWorkspace, strlen($prefix));

        return $template !== '' ? $template : null;
    }
}
