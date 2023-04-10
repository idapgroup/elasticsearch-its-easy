<?php

namespace Belyys7\ElasticsearchItsEasy;

use Elasticsearch\ClientBuilder;
use Exception;

abstract class AbstractElasticSearchBase
{
    const TYPE_SEARCH = '_doc';

    const PAGE_CONTENT_BATCH = 1000;

    protected $client;

    public $indexSearch;

    abstract public function setRules();

    abstract public function setSort();

    /**
     * @param $ip
     * @param $port
     * @param $indexSearch
     */
    public function __construct($ip, $port, $indexSearch)
    {
        $this->indexSearch = $indexSearch;

        $this->client = ClientBuilder::create()
            ->setHosts(["{$ip}:{$port}"])
            ->build();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function reCreateIndex()
    {
        $params = [
            'index' => $this->indexSearch,
        ];

        $existIndex = $this->client->indices()->exists($params);

        if ($existIndex) {
            $this->client->indices()->delete($params);
        }

        $params = [
            'index' => $params['index'],
            'body' => [
                'mappings' => [
                    'properties' => [
                        'location' => [
                            'type' => 'geo_point',
                        ],
                    ],
                ],
            ],
        ];

        $this->createIndex($params);
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    public function createIndex($params)
    {
        try {
            return $this->client->indices()->create($params);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $body
     * @param $primaryKey
     * @param $primaryValue
     * @return array|callable
     * @throws Exception
     */
    public function addDocument($body, $primaryKey, $primaryValue)
    {
        try {

            $this->removeDocument($primaryKey, $primaryValue);

            $params = [
                'index' => $this->indexSearch,
                'type' => self::TYPE_SEARCH,
                'body' => $body,
            ];

            try {
                return $this->client->index($params);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $primaryKey
     * @param $primaryValue
     * @return void
     * @throws Exception
     */
    public function removeDocument($primaryKey, $primaryValue)
    {
        try {

            $params = [
                'index' => $this->indexSearch,
                'type' => self::TYPE_SEARCH,
                'body' => [
                    'query' => [
                        'match' => [
                            $primaryKey => $primaryValue,
                        ]
                    ]
                ]
            ];

            $this->deleteDocumentByQuery($params);

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $params
     * @return array|callable|void
     * @throws Exception
     */
    public function deleteDocumentByQuery($params)
    {
        try {
            if ($params) {
                return $this->client->deleteByQuery($params);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}