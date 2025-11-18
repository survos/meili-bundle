<?php
declare(strict_types=1);

namespace Survos\MeiliBundle\Command;

use Liquid\Template;
use Meilisearch\Client;
use Survos\MeiliBundle\Meili\MeiliTaskStatus;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand('meili:estimate', 'Estimate cost/tokens for the embedders')]
final class MeilEstimatorCommand extends MeiliBaseCommand
{
    /** @param array<string,array<string,mixed>> $indexSettings (indexName => settings)
     * @param array<string,string> $indexEntities (indexName => FQCN)
     */
//    public function __construct(
//        public readonly MeiliService $meili,
//    ) {
//        parent::__construct();
//    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Dump settings without applying', name: 'dump')]
        bool         $dumpSettings = false,

        #[Option('Filter by index name')]
        ?string      $index = null,

        #[Option('Filter by FQCN or short class name')]
        ?string      $class = null,
    ): int
    {
        $targets = $this->resolveTargets($index, $class);
        if ($targets === []) {
            $io->warning('No matching indexes. Use --index or --class to filter. or --all?');
            return Command::SUCCESS;
        }

        foreach ($targets as $name) {

            $io->section(sprintf('Processing index "%s"', $name));
//            $uid = $this->prefixed($name);

            $settings = $this->meili->getRawIndexSetting($name);

            $embedders = $this->embeddersProvider->forMeili();
//            $task = $index->updateEmbedders($embedders);

            $totalTokens = [];
            foreach ($this->meili->getConfig()['embedders'] as $index => $embedder) {
                $template = new Template();
                $template->parse(file_get_contents($embedder['template']));
                $templates[$index] = $template;
            }
            $embedderKeys = $settings['embedders'] ?? [];
            // serialize the doc first

            $iterator = $this->entityManager->getRepository($settings['class'])->createQueryBuilder('e')->select('e')
                ->setMaxResults(3)
                ->getQuery()
                ->toIterable();
            foreach ($iterator as $e) {
                // chicken and egg -- we want to get the data from meili, it's exact, but we don't want to add it if the embedder is active.
                $data = $this->payloadBuilder->build($e, $settings['persisted']);
                dump($data);
                foreach ($embedderKeys as $embedderKey) {
                    $text = $templates[$embedderKey]->render(['doc' => $data]);
                    dd($embedderKey, $text);
                }
            }
            dd($embedderKeys);
            if ($embedderKeys) {
                $embeddersForThisIndex = [];
                $templates = [];
                foreach ($embedderKeys as $key) {
                    $embeddersForThisIndex[$key] = $embedder = $embedders[$key];
                    $templateFilename = $embedder['documentTemplate'];
                    $embedder['documentTemplate'] = file_get_contents($templateFilename);
                    $embeddersForThisIndex[$key]['documentTemplate'] = file_get_contents($templateFilename);
//                        $content = file_get_contents($templateFilename);

//                        $templates[$key]->parse($content);
//                        dd($templates[$key]);
                }
                if ($cost) {
                    if (!class_exists(Template::class)) {
                        throw new \RuntimeException("composer req liquid/liquid");
                    }
                    foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                        $templates[$embedderName] = new Template($templateFilename);
                        $totalTokens[$embedderName] = 0;
                        $templates[$embedderName] = new Template();
                    }

                    // iterate through the records, render the liquid template, and pass to totenizer estmiate
                    // @todo: batches, etc.  Argh. Should this be a separate command?
                    $iterator = $this->entityManager->getRepository($settings['class'])->createQueryBuilder('e')->select('e')
                        ->setMaxResults(3)
                        ->getQuery()
                        ->toIterable();
                    foreach ($iterator as $e) {
                        // chicken and egg -- we want to get the data from meili, it's exact, but we don't want to add it if the embedder is active.
                        $data = $this->payloadBuilder->build($e, $settings['persisted']);
//                            $data = $this->normalizer->normalize($e, 'array');
//                            dump($data);
                        foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                            $template = $templates[$embedderName];
                            dump($data);
                            $text = $template->render(['doc' => $data]);
                            dd($templates, $embedderName, $template, $text);

                            $provider = new EncoderProvider();
                            $encoder = $provider->getForModel($embedder['model']); // or 'gpt-3.5-turbo'
                            $tokens = $encoder->encode($text);
                            $tokenCount = count($tokens);
                            $totalTokens[$embedderName] += $tokenCount;
                            dd($embedder['model'], $embedderName, $tokenCount, $text);
                        }
                    }
                    dump($totalTokens);
                    foreach ($embeddersForThisIndex as $embedderName => $embedder) {
                        $io->writeln("$embedderName tokens: " . $totalTokens[$embedderName]);
                    }
                }
            }


        }

        return Command::SUCCESS;
    }
}
