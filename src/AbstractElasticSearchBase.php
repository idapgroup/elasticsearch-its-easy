<?php

namespace IdapGroup\ElasticsearchItsEasy;

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
     * @param string $ip
     * @param string $port
     * @param string $indexSearch
     */
    public function __construct(string $ip, string $port, string $indexSearch)
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
    public function reCreateIndex() : void
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
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function createIndex(array $params) : array
    {
        try {
            return $this->client->indices()->create($params);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param array $body
     * @param string $primaryKey
     * @param string $primaryValue
     * @return array|callable
     * @throws Exception
     */
    public function addDocument(array $body, string $primaryKey, string $primaryValue)
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
     * @param string $primaryKey
     * @param string $primaryValue
     * @return void
     * @throws Exception
     */
    public function removeDocument(string $primaryKey, string $primaryValue)
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
     * @param array $params
     * @return array|callable|void
     * @throws Exception
     */
    public function deleteDocumentByQuery(array $params)
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