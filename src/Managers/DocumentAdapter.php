<?php

namespace YucaDoo\ElasticSearcher\Managers;

interface DocumentAdapter
{
    /**
     * Get Elasticsearch id for item instance.
     * @param mixed $item
     * @return string|int Elasticsearch id.
     */
    public function getId($item);

    /**
     * Get index name for item instance.
     * @param mixed $item
     * @return string Elasticsearch index name.
     */
    public function getIndexName($item): string;
}
