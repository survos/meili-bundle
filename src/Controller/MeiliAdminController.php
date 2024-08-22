<?php

namespace Survos\MeiliAdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MeiliAdminController extends AbstractController
{
//defaults={"anything" = null}, requirements={"anything"=".+"}
    #[Route('/meili/admin{anything}', name: 'survos_meili_admin', defaults: ['anything' => null], requirements: ['anything' => '.+'])]
    public function dashboard(UrlGeneratorInterface $urlGenerator): Response
    {
        $config = json_decode(<<<END
{
  "modulePrefix": "meiliadmin",
  "environment": "production",
  "rootURL": "/",
  "locationType": "history",
  "EmberENV": {
    "FEATURES": {},
    " APPLICATION_TEMPLATE_WRAPPER": false,
    " DEFAULT_ASYNC_OBSERVERS": true,
    " JQUERY_INTEGRATION": false,
    " TEMPLATE_ONLY_GLIMMER_COMPONENTS": true
  },
  "APP": { 
    "meilisearch": { "url": "http://localhost:7700", "key": "MASTER_KEY" },
    "name": "meiliadmin", 
    "version": "0.0.0+bd7f85d7" 
  }
}
END
);

        // this seems problematic
        $config->rootURL = $urlGenerator->generate('survos_meili_admin');
//        dd($config, json_encode($config, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES));
//        return $this->render('@SurvosBootstrap/base.html.twig', [
        return $this->render('@SurvosMeiliAdmin/dashboard.html.twig', [
            'config' => $config,
            'encodedConfig' => json_encode($config),
            'controller_name' => 'MeiliAdminController',
        ]);
    }

    #[Route(path: '/stats/{indexName}.{_format}', name: 'survos_index_stats', methods: ['GET'])]
    public function stats(
        string  $indexName,
        Request $request,
        string $_format='html'
    ): Response
    {
        $index = $this->meili->getIndex($indexName);
        $stats = $index->stats();
        // idea: meiliStats as a component?
        $data =  [
            'indexName' => $indexName,
            'settings' => $index->getSettings(),
            'stats' => $stats
        ];
        return $_format == 'json'
            ? $this->json($data)
            : $this->render('@SurvosApiGrid/stats.html.twig', $data);

        // Get the base URL
//        $url = "/api/projects";//.$indexName;
        $url = "/api/" . $indexName;
        $queryParams = ['limit' => 0, 'offset' => 0, '_index' => false];
        $queryParams['_locale'] = $translator->getLocale();
        $settings = $index->getSettings();
        foreach ($settings['filterableAttributes'] as $filterableAttribute) {
            $queryParams['facets'][$filterableAttribute] = 1;
        }
        $queryParams = http_build_query($queryParams);

        $data = $client->request('GET', $finalUrl = $baseUrl . $url . "?" . $queryParams, [
            'headers' => [
                'Content-Type' => 'application/ld+json;charset=utf-8',
            ]
        ]);

        dd($finalUrl, $data->getStatusCode());
        assert($index);
        return $this->render('meili/stats.html.twig', [
            'stats' => $index->stats(),
            'settings' => $index->getSettings()
        ]);


    }

}
