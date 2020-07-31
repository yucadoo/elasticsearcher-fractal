<?php

namespace YucaDoo\ElasticSearcher\Managers;

use ElasticSearcher\Managers\DocumentsManager as WrappedManager;
use InvalidArgumentException;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Item as FractalItem;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\TransformerAbstract;
use Psr\Container\ContainerInterface;

class DocumentManager
{
    /** @var WrappedManager */
    protected $manager;
    /** @var FractalManager */
    protected $fractal;
    /** @var DocumentAdapter */
    protected $adapter;
    /** @var ContainerInterface */
    protected $transformerRegistry;
    /**
     * Type will be depracted in future versions.
     */
    protected const TYPE = '_doc';

    public function __construct(
        WrappedManager $manager,
        FractalManager $fractal,
        DocumentAdapter $adapter,
        ContainerInterface $transformerRegistry
    ) {
        $this->manager = $manager;
        $this->fractal = $fractal;
        $this->fractal->setSerializer(new ArraySerializer());
        $this->adapter = $adapter;
        $this->transformerRegistry = $transformerRegistry;
    }

    /**
     * Create Elasticsearch document for item.
     *
     * @param mixed $item
     * @return array Elasticsearch response.
     */
    public function create($item)
    {
        return $this->manager->index(
            $this->getIndexName($item),
            self::TYPE,
            $this->toDocument($item)
        );
    }

    /**
     * Create Elasticsearch documents for multiple item.
     *
     * @param \iterable $items.
     * @return array Empty array for empty items, Elasticsearch response otherwise.
     * @throws InvalidArgumentException When items belong to different indices.
     */
    public function bulkCreate($items)
    {
        // Get index name from first item.
        $indexName = null;
        foreach ($items as $item) {
            $indexName = $this->getIndexName($item);
            break;
        }

        if (!is_null($indexName)) {
            $documents = $this->toDocuments($items);

            return $this->manager->bulkIndex(
                $indexName,
                self::TYPE,
                $documents
            );
        }
        return [];
    }

    /**
     * Delete Elasticsearch document for item.
     *
     * @param mixed $item
     * @return array Elasticsearch response.
     */
    public function delete($item)
    {
        return $this->manager->delete(
            $this->getIndexName($item),
            self::TYPE,
            $this->getId($item)
        );
    }

    /**
     * Update existing Elasticsearch document for item.
     *
     * @param mixed $item
     * @return array Elasticsearch response.
     */
    public function update($item)
    {
        return $this->manager->update(
            $this->getIndexName($item),
            self::TYPE,
            $this->getId($item),
            $this->toDocument($item)
        );
    }

    /**
     * Check if Elasticsearch document exists for item.
     *
     * @param mixed $item
     * @return bool True when document exists, false otherwise.
     */
    public function exists($item): bool
    {
        return $this->manager->exists(
            $this->getIndexName($item),
            self::TYPE,
            $this->getId($item)
        );
    }

    /**
     * Update Elasticsearch document for item. Create it if it doesn't exist.
     *
     * @param mixed $item
     * @return array Elasticsearch response.
     */
    public function updateOrCreate($item)
    {
        if ($this->exists($item)) {
            return $this->update($item);
        }
        return $this->create($item);
    }

    /**
     * Fetch Elasticsearch document.
     *
     * @param mixed $item Item for which document is fetched.
     * @return array Fetched Elasticsearch document.
     */
    public function get($item)
    {
        return $this->manager->get(
            $this->getIndexName($item),
            self::TYPE,
            $this->getId($item)
        );
    }

    /**
     * Get index name for item instance.
     * @param mixed $item
     * @return string Elasticsearch index name.
     */
    protected function getIndexName($item): string
    {
        return $this->adapter->getIndexName($item);
    }

    /**
     * Get id for item instance.
     * @param mixed $item
     * @return string|int Elasticsearch id.
     */
    protected function getId($item)
    {
        return $this->adapter->getId($item);
    }

    /**
     * Get transformer for creating documents in specified index.
     *
     * @param string $indexName Name of Elasticsearch index.
     * @return TransformerAbstract Fractal transformer.
     */
    protected function getTransformerByIndexName(string $indexName): TransformerAbstract
    {
        return $this->transformerRegistry->get($indexName);
    }

    /**
     * Convert item instance to document.
     *
     * @param mixed $item Item for which documents should be composed.
     * @return array Composed document.
     */
    protected function toDocument($item): array
    {
        $transformer = $this->getTransformerByIndexName($this->getIndexName($item));
        return $this->fractal
            ->createData(new FractalItem($item, $transformer))
            ->toArray();
    }

    /**
     * Convert multiple items to documents.
     * @param \iterable $items Items for which documents should be composed.
     * @return array Composed documents.
     * @throws InvalidArgumentException When items belong to different indices.
     */
    protected function toDocuments($items): array
    {
        $indexName = null;
        foreach ($items as $item) {
            if (is_null($indexName)) {
                $indexName = $this->getIndexName($item);
            } else {
                // All items should belong to the same index
                $currentIndexName = $this->getIndexName($item);
                if ($indexName != $currentIndexName) {
                    throw new InvalidArgumentException(
                        'All items have to belong to same index.
	                    Found ' . $indexName . ' and ' . $currentIndexName
                    );
                }
            }
        }

        // The ArraySerializer causes the documents to be wrapped.
        $wrappedDocuments = $this->fractal
            ->createData(new FractalCollection($items, $this->getTransformerByIndexName($indexName)))
            ->toArray();
        return $wrappedDocuments['data'];
    }
}
