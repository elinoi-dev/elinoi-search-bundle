<?php

declare(strict_types=1);

namespace Algolia\SearchBundle\Command;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\SearchBundle\Entity\Aggregator;
use Algolia\SearchBundle\SearchService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class SearchImportCommand extends IndexCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'search:import';

    /**
     * @var SearchService
     */
    private $searchServiceForAtomicReindex;

    /**
     * @var ManagerRegistry|null
     */
    private $managerRegistry;

    /**
     * @var SearchClient
     */
    private $searchClient;

    public function __construct(
        SearchService $searchService,
        SearchService $searchServiceForAtomicReindex,
        ManagerRegistry $managerRegistry,
        SearchClient $searchClient
    ) {
        parent::__construct($searchService);

        $this->searchServiceForAtomicReindex = $searchServiceForAtomicReindex;
        $this->managerRegistry = $managerRegistry;
        $this->searchClient = $searchClient;
    }

    protected function configure()
    {
        $this
            ->setDescription('Import given entity into search engine')
            ->addOption('indices', 'i', InputOption::VALUE_OPTIONAL, 'Comma-separated list of index names')
            ->addOption(
                'atomic',
                null,
                InputOption::VALUE_NONE,
                <<<'EOT'
                    If set, command replaces all records in an index without any downtime. It pushes a new set of objects and removes all previous ones.

                    Internally, this option causes command to copy existing index settings, synonyms and query rules and indexes all objects. Finally, the existing index is replaced by the temporary one.
                    EOT
            )
            ->addArgument(
                'extra',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Check your engine documentation for available options'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shouldDoAtomicReindex = (bool) $input->getOption('atomic');
        $entitiesToIndex = $this->getEntitiesFromArgs($input, $output);
        $config = $this->searchService->getConfiguration();
        $indexingService = ($shouldDoAtomicReindex ? $this->searchServiceForAtomicReindex : $this->searchService);

        foreach ($entitiesToIndex as $entityClassName) {
            if (!$this->searchService->isSearchable($entityClassName)) {
                continue;
            }

            $sourceIndexName = $this->searchService->searchableAs($entityClassName);

            if ($shouldDoAtomicReindex) {
                $temporaryIndexName = $this->searchServiceForAtomicReindex->searchableAs($entityClassName);
                $output->writeln("Creating temporary index <info>{$temporaryIndexName}</info>");
                $this->searchClient->copyIndex($sourceIndexName, $temporaryIndexName, ['scope' => ['settings', 'synonyms', 'rules']]);
            }

            $allResponses = [];
            foreach (is_subclass_of($entityClassName, Aggregator::class) ? $entityClassName::getEntities() : [$entityClassName] as $entityClass) {
                $manager = $this->managerRegistry->getManagerForClass($entityClass);
                $repository = $manager->getRepository($entityClass);

                $talentStatuses = ['status_accepted'];
                foreach ($talentStatuses as $talStatus) {
                    $allEntities = $repository->findBy(['status' => $talStatus]);
                    $output->writeln(sprintf('Count of %s is %s', $talStatus, \count($allEntities)));

                    $entitiesArray = array_chunk($allEntities, $config['batchSize']);

                    foreach ($entitiesArray as $entities) {
                        $response = $indexingService->index($manager, $entities);
                        $output->writeln(sprintf('Count of %s is %s', $talStatus, \count($allEntities)));
                        $allResponses[] = $response;
                        $responses = $this->formatIndexingResponse($response, $output);

                        foreach ($responses as $indexName => $numberOfRecords) {
                            $output->writeln(sprintf(
                                'Indexed <comment>%s / %s</comment> %s entities into %s index',
                                $numberOfRecords,
                                \count($entities),
                                $entityClass,
                                '<info>'.$indexName.'</info>'
                            ));
                        }
                    }
                }

                $manager->clear();
            }

            if ($shouldDoAtomicReindex && isset($indexName)) {
                $output->writeln("Waiting for indexing tasks to finalize\n");
                foreach ($allResponses as $response) {
                    $response->wait();
                }
                $output->writeln("Moving <info>{$indexName}</info> -> <comment>{$sourceIndexName}</comment>\n");
                $this->searchClient->moveIndex($indexName, $sourceIndexName);
            }
        }

        $output->writeln('<info>Done!</info>');

        return 0;
    }

    /**
     * @param array<int, array> $batch
     *
     * @return array<string, int>
     */
    private function formatIndexingResponse($batch, OutputInterface $output)
    {
        $formattedResponse = [];

        foreach ($batch as $chunk) {
            foreach ($chunk as $indexName => $apiResponse) {
                if (!\array_key_exists($indexName, $formattedResponse)) {
                    $formattedResponse[$indexName] = 0;
                }
                $formattedResponse[$indexName] += \count($apiResponse->current()['objectIDs']);
            }
        }

        return $formattedResponse;
    }
}
