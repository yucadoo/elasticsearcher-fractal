<?php

namespace YucaDoo\ElasticSearcher\Managers;

/**
 * Dummy class required to mock output of fractal facade
 */
class ArrayCarrier
{
    /**
     * Carried array
     * @var array
     */
    public $data;

    /**
     * @param array $data Carried array
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Provides carried array.
     * @return array
     */
    public function toArray()
    {
        return $this->data;
    }
}
