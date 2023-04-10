<?php

namespace IdapGroup\ElasticsearchItsEasy;

use Exception;

class ModelSearchBase extends AbstractElasticSearchBase
{
    const GROUP_MUST = 'must';
    const GROUP_SHOULD = 'should';
    const GROUP_FILTER = 'filter';
    const GROUP_LOCATION = 'location';

    const RULE_EQUAL = 'equal';
    const RULE_LIKE = 'like';
    const RULE_IN = 'in';
    const RULE_RANGE = 'range';

    const SORT_ASC = 'asc';
    const SORT_DESC = 'desc';

    const LIMIT_MAX_FIX = 100;

    const DISTANCE_UNTIL = 'km';

    public $rules = [];
    public $sort = [];

    public $page = 1;
    public $limit = 10;

    public $fixLimit = false;

    public $enabledGeo = false;
    public $locationLat;
    public $locationLon;
    public $locationSort = self::SORT_DESC;

    /**
     * @param $ip
     * @param $port
     * @param $indexSearch
     */
    public function __construct($ip, $port, $indexSearch)
    {
        parent::__construct($ip, $port, $indexSearch);

        $this->setRules();
        $this->setSort();
    }

    public function setRules()
    {
        // TODO: Implement setRules() method in child class
        /* Example
        $this->rules = [
            // Groups list
            self::GROUP_FILTER => [
                // Rules list
                self::RULE_EQUAL => [
                    // 'front key param' => 'path to value in elasticsearch'
                    'userId' => 'user.id'
                ],
            ],
        ];
        */
    }

    public function setSort()
    {
        // TODO: Implement setRules() method in child class
        /* Example
        $this->sort = [
            // 'path to value in elasticsearch' => 'sort mode (asc or desc)'
            'user.id' => self::SORT_DESC,
        ];
        */
    }

    /**
     * @param $params
     * @return int|mixed
     */
    public function getLimit($params = [])
    {
        if ($this->fixLimit) {
            return $this->limit;
        }

        $limit = (isset($params['limit'])) ? $params['limit'] : $this->limit;

        return ($limit <= self::LIMIT_MAX_FIX) ? $limit : self::LIMIT_MAX_FIX;
    }

    /**
     * @param $limit
     * @return void
     */
    public function enableFixLimitResult($limit = self::LIMIT_MAX_FIX)
    {
        $this->limit = $limit;
        $this->fixLimit = true;
    }

    /**
     * @param $name
     * @return array|mixed
     */
    public function getRulesOfGroup($name)
    {
        return $this->rules[$name] ?? [];
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    public function searchList($params = [])
    {
        try {

            if (!$this->client->indices()->exists(['index' => $this->indexSearch])) {
                throw new Exception('Not found index - ' . $this->indexSearch);
            }

            $conditions = $this->prepareConditions($params);
            $sort = $this->prepareSort();
            $limit = $this->getLimit($params);

            $paramsSearch = [
                'index' => $this->indexSearch,
                'type' => self::TYPE_SEARCH,
                'from' => ($this->page <= 1) ? 0 : ($this->page - 1) * $limit,
                'size' => $limit,
                'body' => ['sort' => $sort],
            ];

            if ($conditions['isChanged']) {
                $paramsSearch['body']['query'] = ['bool' => $conditions['query']];
            }

            $elasticResult = $this->client->search($paramsSearch);

            unset($paramsSearch['from'], $paramsSearch['size'], $paramsSearch['body']['sort']);

            $elasticsearchRecordsTotal = $this->client->count($paramsSearch)['count'];
            $elasticsearchPagesTotal = ceil($elasticsearchRecordsTotal / $limit);

            $preparedResult = $this->prepareResult($elasticResult);

            return [
                'result' => $preparedResult,
                'pagination' => [
                    'totalCount' => $elasticsearchRecordsTotal,
                    'pageCount' => $elasticsearchPagesTotal,
                    'currentPage' => $this->page,
                ],
            ];

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    public function searchMap($params = [])
    {
        try {

            if (!$this->client->indices()->exists(['index' => $this->indexSearch])) {
                throw new Exception('Not found index - ' . $this->indexSearch);
            }

            $conditions = $this->prepareConditions($params);
            $sort = $this->prepareSort();

            $allElasticsearchHits = [];

            $page = 0;

            while (true) {

                $paramsSearch = [
                    'index' => $this->indexSearch,
                    'type' => self::TYPE_SEARCH,
                    'from' => $page * self::PAGE_CONTENT_BATCH,
                    'size' => self::PAGE_CONTENT_BATCH,
                    'body'  => ['sort' => $sort],
                ];

                if ($conditions['isChanged']) {
                    $paramsSearch['body']['query'] = ['bool' => $conditions['query']];
                }

                $elasticResult = $this->client->search($paramsSearch);
                $preparedResult = $this->prepareResult($elasticResult);

                if ($preparedResult) {
                    $allElasticsearchHits = array_merge($allElasticsearchHits, $preparedResult);
                    $page++;
                } else {
                    break 1;
                }
            }

            return $allElasticsearchHits;

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    private function prepareConditions($params = [])
    {
        $conditions = [];
        $isChanged = false;

        $mustConditions = $this->collectRules($this->getRulesOfGroup(self::GROUP_MUST), $params);

        if ($mustConditions) {
            $isChanged = true;
            $conditions['must'] = $mustConditions;
        }

        $shouldConditions = $this->collectRules($this->getRulesOfGroup(self::GROUP_SHOULD), $params);

        if ($shouldConditions) {
            $isChanged = true;
            $conditions['should'] = $shouldConditions;
        }

        $filterConditions = $this->collectRules($this->getRulesOfGroup(self::GROUP_FILTER), $params);

        $location = $this->getRulesOfGroup(self::GROUP_LOCATION);

        if ($location) {

            $locationKey = key($location);
            $this->locationSort = $location[$locationKey];

            if (!isset($params[$locationKey]['lat']) || !isset($params[$locationKey]['lon']) || !isset($params[$locationKey]['distance'])) {
                throw new Exception('Wrong value for GROUP_LOCATION, params: lat, lon, distance is required');
            }

            $this->enabledGeo = true;
            $this->locationLat = (float) $params[$locationKey]['lat'];
            $this->locationLon = (float) $params[$locationKey]['lon'];

            $filterConditions[] = [
                'geo_distance' => [
                    'distance' => (int) $params[$locationKey]['distance'] . self::DISTANCE_UNTIL,
                    'location' => [
                        'lat' => $this->locationLat,
                        'lon' => $this->locationLon,
                    ],
                ],
            ];
        }

        if ($filterConditions) {
            $isChanged = true;
            $conditions['filter'] = $filterConditions;
        }

        return [
            'isChanged' => $isChanged,
            'query' => $conditions,
        ];
    }

    /**
     * @param $rules
     * @param $params
     * @return array
     * @throws Exception
     */
    private function collectRules($rules = [], $params = [])
    {
        $data = [];

        if ($rules) {

            foreach ($rules as $rule => $relations) {

                switch ($rule) {
                    case self::RULE_EQUAL:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!is_numeric($params[$key])) {
                                throw new Exception('Wrong value for RULE_EQUAL, key is - ' . $key);
                            }
                            $data[] = [
                                'term' => [
                                    $relation => $params[$key],
                                ],
                            ];
                        }
                        break;
                    case self::RULE_LIKE:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!is_string($params[$key])) {
                                throw new Exception('Wrong value for RULE_LIKE, key is - ' . $key);
                            }
                            $data[] = [
                                'term' => [
                                    $relation . '.keyword' => $params[$key],
                                ],
                            ];
                        }
                        break;
                    case self::RULE_IN:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!is_array($params[$key])) {
                                throw new Exception('Wrong value for RULE_IN, key is - ' . $key);
                            }
                            $data[] = [
                                'terms' => [
                                    $relation => $params[$key],
                                ],
                            ];
                        }
                        break;
                    case self::RULE_RANGE:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!isset($params[$key]['min']) || !isset($params[$key]['max'])) {
                                throw new Exception('Wrong value for RULE_RANGE, key is - ' . $key . '. Required field is min/max');
                            }
                            $data[] = [
                                'range' => [
                                    $relation => [
                                        "gte" => (float) $params[$key]['min'],
                                        "lte" => (float) $params[$key]['max'],
                                    ]
                                ],
                            ];
                        }
                        break;
                }
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function prepareSort() : array
    {
        $sort = [];

        if ($this->enabledGeo) {

            $sort[] = [
                '_geo_distance' => [
                    'location' => [
                        $this->locationLon,
                        $this->locationLat,
                    ],
                    'order' => $this->locationSort,
                    'unit' => self::DISTANCE_UNTIL,
                ],
            ];
        }

        if ($this->sort) {
            foreach ($this->sort as $path => $mode) {
                $sort[] = [$path => $mode];
            }
        } else {
            $sort[] = ['_score' => 'desc'];
        }

        return $sort;
    }

    /**
     * @param $searchResult
     * @return array
     */
    private function prepareResult($searchResult = [])
    {
        $result = [];

        $list = $searchResult['hits']['hits'] ?? [];

        if ($list) {
            foreach ($list as $item) {
                $result[] = $item['_source'];
            }
        }

        return $result;
    }

}