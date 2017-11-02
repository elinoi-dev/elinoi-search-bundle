<?php

namespace Algolia\SearchBundle\Searchable;


use Algolia\SearchBundle\Engine\IndexingEngineInterface;
use Doctrine\Common\Persistence\ObjectManager;

class IndexManager implements IndexManagerInterface
{
    protected $engine;

    protected $indices;

    protected $prefix;

    private $searchableEntities;

    private $classToIndexMapping;

    public function __construct(IndexingEngineInterface $engine, array $indices, $prefix)
    {
        $this->engine = $engine;
        $this->indices = $indices;
        $this->prefix = $prefix;

        $this->setSearchableEntities();
        $this->setClassToIndexMapping();
    }

    public function isSearchable($className)
    {
        if (is_object($className)) {
            $className = get_class($className);
        }

        return in_array($className, $this->searchableEntities);
    }

    public function index($entity, ObjectManager $objectManager)
    {
        $className = get_class($entity);

        if (! $this->isSearchable($className)) {
            return;
        }

        foreach ($this->classToIndexMapping[$className] as $indexName) {

            $this->engine->update(new Searchable(
                $this->prefix.$indexName,
                $entity,
                $objectManager->getClassMetadata($className),
                $this->indices[$indexName]['normalizers']
            ));
        }

    }

    private function setClassToIndexMapping()
    {
        $mapping = [];
        foreach ($this->indices as $indexName => $indexDetails) {
            foreach ($indexDetails['classes'] as $class) {
                if (! isset($mapping[$class])) {
                    $mapping[$class] = [];
                }

                $mapping[$class][] = $indexName;
            }
        }

        $this->classToIndexMapping = $mapping;
    }

    private function setSearchableEntities()
    {
        $searchable = [];

        foreach ($this->indices as $name => $index) {
            $searchable = array_merge($searchable, $index['classes']);
        }

        $this->searchableEntities = array_unique($searchable);
    }
}
