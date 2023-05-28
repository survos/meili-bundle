<?php

namespace Survos\MeiliAdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MeiliAdminController extends AbstractController
{
//defaults={"anything" = null}, requirements={"anything"=".+"}
    #[Route('/meili/admin{anything}', name: 'app_meili_admin', defaults: ['anything' => null], requirements: ['anything' => '.+'])]
    public function index(UrlGeneratorInterface $urlGenerator): Response
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

        $config->rootURL = $urlGenerator->generate('app_meili_admin');
//        dd($config, json_encode($config, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES));
//        return $this->render('@SurvosBootstrap/base.html.twig', [
        return $this->render('@SurvosMeiliAdmin/dashboard.html.twig', [
            'config' => $config,
            'encodedConfig' => json_encode($config),
            'controller_name' => 'MeiliAdminController',
        ]);
    }
}
