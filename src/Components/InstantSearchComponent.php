<?php

declare(strict_types=1);

namespace Survos\MeiliBundle\Components;

use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\ChatWorkspaceResolver;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Environment;

#[AsTwigComponent('meili_instant_search', template: '@SurvosMeili/components/instant_search.html.twig')]
final class InstantSearchComponent
{
    public string $server;
    public ?string $apiKey = null;
    public string $indexName;
    public string $baseIndexName;
    public ?string $embedder = null;
    public ?string $q = null;
    public array $indexConfig = [];
    public array $settings = [];
    public array $allSettings = [];
    public array $facets = [];
    public array $sorting = [];
    public string $templateName;
    public array $related = [];
    public mixed $indexStats = null;
    public ?string $translationStyle = null;
    public bool $searchAsYouType = true;
    public ?string $chatWorkspace = null;
    public ?string $imgproxyHost = null;
    public string $stimulusController = '@survos/meili-bundle/insta';
    public string $modalStimulusController = '@survos/meili-bundle/json';

    public function __construct(
        private readonly Environment $twig,
        private readonly ?LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly MeiliService $meiliService,
        private readonly MeiliServerKeyService $meiliServerKeyService,
        private readonly ChatWorkspaceResolver $chatWorkspaceResolver,
    ) {
    }

    public function mount(
        string $indexName,
        ?string $embedder = null,
        ?string $q = null,
        bool $useProxy = false,
        bool $searchAsYouType = true,
    ): void {
        $this->baseIndexName = $indexName;
        $this->embedder = $embedder;
        $this->q = $q;

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
        $meiliIndexUid = $this->meiliService->uidForBase($this->baseIndexName, $locale);
        $this->indexName = $meiliIndexUid;

        $this->indexConfig = $this->meiliService->getIndexSetting($this->baseIndexName)
            ?? [
                'template' => $this->baseIndexName,
                'primaryKey' => 'id',
                'baseName' => $this->baseIndexName,
                'facets' => [],
                'instantsearch' => ['routing' => false],
            ];
        $this->indexConfig['instantsearch'] = is_array($this->indexConfig['instantsearch'] ?? null) ? $this->indexConfig['instantsearch'] : [];
        $this->indexConfig['instantsearch']['routing'] ??= false;

        $this->templateName = (string) ($this->indexConfig['template'] ?? $this->baseIndexName);

        $index = $this->meiliService->getIndexEndpoint($meiliIndexUid);
        $this->settings = $index->getSettings();

        if (empty($this->indexConfig['facets'])) {
            $liveFilterable = $this->settings['filterableAttributes'] ?? [];
            if ($liveFilterable !== []) {
                $facetDefaults = [
                    'collapsed' => false,
                    'widget' => 'RefinementList',
                    'searchable' => null,
                    'searchMode' => 'contains',
                    'limit' => null,
                    'showMoreLimit' => null,
                    'lookup' => null,
                    'sortMode' => null,
                ];
                $this->indexConfig['facets'] = array_fill_keys($liveFilterable, $facetDefaults);
            }
        }

        $this->sorting = [['label' => 'Relevance', 'value' => $meiliIndexUid]];
        foreach (($this->settings['sortableAttributes'] ?? []) as $attr) {
            foreach (['asc', 'desc'] as $dir) {
                $this->sorting[] = [
                    'label' => sprintf('%s %s', $attr, $dir),
                    'value' => sprintf('%s:%s:%s', $meiliIndexUid, $attr, $dir),
                ];
            }
        }

        $this->server = $useProxy
            ? $this->router->generate('meili_proxy', [], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->meiliService->getHost();
        $this->apiKey = $this->meiliServerKeyService->resolveApiKey($meiliIndexUid);
        $this->allSettings = $this->meiliService->getAllSettings();
        $this->facets = $this->settings['filterableAttributes'] ?? [];
        $this->indexStats = $index->stats();
        $this->translationStyle = $this->meiliService->getConfig()['translationStyle'] ?? null;
        $this->searchAsYouType = $embedder === null && $searchAsYouType;
        $this->chatWorkspace = $this->chatWorkspaceResolver->workspaceForIndex($meiliIndexUid);
    }
}
