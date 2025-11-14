<?php

namespace Survos\MeiliBundle\Components;

use Psr\Log\LoggerInterface;
use Survos\InspectionBundle\Services\InspectionService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Environment;

#[AsTwigComponent('instant_search', template: '@SurvosMeili/components/instant_search.html.twig')]
class InstantSearchComponent
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private ?MeiliService $meiliService = null,
        public ?string $stimulusController = null,
        private bool $meili = false,
        private ?string $class = null,
        private array $filter = [],
        private $collectionRoutes = [],
    ) {}

    public function getClass(): ?string { return $this->class; }

    public function getFilter(): array
    {
        if ($stack = $this->requestStack->getCurrentRequest()) {
            $this->filter = array_merge($this->filter, $stack->query->all());
        }
        return $this->filter;
    }

    public function setClass(?string $class): self { $this->class = $class; return $this; }

    public string $server = 'http://127.0.0.1:7700';
    public ?string $apiKey = null;
    public ?string $embedder = null;
    public ?string $_sc_locale = '_sc_cola';

    public iterable $data;

    public array $columns = [];
    public array $facet_columns = [];
    public array $globals = [];
    public array $searchBuilderFields = [];

    public array|object|null $schema = null;
    public ?string $index = null;
    public string $dom = 'BQlfrtpP';
    public int $pageLength = 50;
    public string $searchPanesDataUrl;
    public ?string $apiGetCollectionUrl = null;
    public ?string $apiRoute = null;
    public array $apiRouteParams = [];
    public array $apiGetCollectionParams = [];
    public bool $trans = true;
    public string|bool|null $domain = null;
    public array $buttons = [];
    public bool $search = true;
    public string $scrollY = '70vh';
    public bool $useDatatables = true;
    public ?string $source = null;
    public ?string $style = 'spreadsheet';
    public ?string $locale = null;
    public ?string $path = null;
    public bool $info = false;
    public ?string $tableId = null;
    public string $tableClasses = '';

    /** @var array<string,mixed> */
    public array $indexConfig = []; // this index configuration
    public array $settings = []; // All indexes, for

    public function getLocale(): string
    {
        return $this->requestStack->getParentRequest()->getLocale();
    }

    public function mount(
        string $class,
        ?string $apiRoute = null,
        ?string $apiGetCollectionUrl = null,
        array $filter = [],
        array $buttons = [],
        bool $meili = false,
        ?array $indexConfig = null // <- NEW
    ): void {
        $this->filter = $filter;
        $this->buttons = $buttons;
        $this->class = $class;
        $this->settings = $this->meiliService->getAllSettings();
        dd($this->settings);
        $this->indexConfig = $indexConfig ?? $this->indexConfig; // <- NEW
    }

    /** Used in Twig to pass to Stimulus as a single JSON blob */
    public function getIndexConfigJson(): string
    {
        return json_encode($this->indexConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
