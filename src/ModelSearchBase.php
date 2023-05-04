<?php

namespace IdapGroup\ElasticsearchItsEasy;

use Exception;
use Sk\Geohash\Geohash;

class ModelSearchBase extends AbstractElasticSearchBase
{
    const GROUP_MUST = 'must';
    const GROUP_SHOULD = 'should';
    const GROUP_FILTER = 'filter';
    const GROUP_LOCATION = 'location';

    const RULE_EQUAL = 'equal';
    const RULE_LIKE = 'like';
    const RULE_IN = 'in';
    const RULE_RANGE_NUMBER = 'range_number';
    const RULE_RANGE_DATE = 'range_date';

    const SORT_ASC = 'asc';
    const SORT_DESC = 'desc';

    const LIMIT_MAX_FIX = 100;

    const ZOOM_START_CUSTOM_CLUSTER = 13;

    const DISTANCE_UNTIL = 'km';

    public $rules = [];
    public $sort = [];

    public $overWriteRules = [];

    public $page = 1;
    public $limit = 10;

    public $fixLimit = false;

    public $enabledGeo = false;
    public $locationLat;
    public $locationLon;
    public $locationSort = self::SORT_DESC;

    public $clustering = false;
    public $zoom;

    /**
     * @param string $ip
     * @param string $port
     * @param string $indexSearch
     */
    public function __construct(string $ip, string $port, string $indexSearch)
    {
        parent::__construct($ip, $port, $indexSearch);

        $this->setRules();
        $this->setSort();
    }

    // Example
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

    // Example
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
     * @param array $params
     * @return int
     */
    public function getLimit(array $params = []) : int
    {
        if ($this->fixLimit) {
            return $this->limit;
        }

        $limit = (isset($params['limit'])) ? $params['limit'] : $this->limit;

        return ($limit <= self::LIMIT_MAX_FIX) ? $limit : self::LIMIT_MAX_FIX;
    }

    /**
     * @param int $limit
     * @return void
     */
    public function enableFixLimitResult(int $limit = self::LIMIT_MAX_FIX) : void
    {
        $this->limit = $limit;
        $this->fixLimit = true;
    }

    /**
     * @param string $name
     * @return array
     */
    public function getRulesOfGroup(string $name) : array
    {
        return $this->rules[$name] ?? [];
    }

    /**
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function searchList(array $params = []) : array
    {
        try {

            if (!$this->client->indices()->exists(['index' => $this->indexSearch])) {
                throw new Exception('Not found index - ' . $this->indexSearch);
            }

            $conditions = $this->prepareConditions($params);

            if ($conditions['isChanged'] && $this->overWriteRules) {
                $conditions = $this->prepareOverWriteRules($conditions);
            }

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
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function searchMap(array $params = []) : array
    {
        try {

            if (!$this->client->indices()->exists(['index' => $this->indexSearch])) {
                throw new Exception('Not found index - ' . $this->indexSearch);
            }

            $conditions = $this->prepareConditions($params);

            if ($conditions['isChanged'] && $this->overWriteRules) {
                $conditions = $this->prepareOverWriteRules($conditions);
            }

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

            if ($this->clustering && $this->zoom >= self::ZOOM_START_CUSTOM_CLUSTER) {
                return $this->prepareClustering($allElasticsearchHits);
            }

            return $allElasticsearchHits;

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function prepareConditions(array $params = []) : array
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

            $point = $params[$locationKey]['point'];

            if (!$point) {
                throw new Exception('Wrong value for GROUP_LOCATION, params: point is required');
            }

            $pointLat = $point['lat'];
            $pointLng = $point['lon'];
            $pointDistance = $point['distance'];

            if (!$pointLat || !$pointLng || !$pointDistance) {
                throw new Exception('Wrong value for GROUP_LOCATION, params: lat, lon, distance is required');
            }

            $this->enabledGeo = true;
            $this->locationLat = (float) $pointLat;
            $this->locationLon = (float) $pointLng;

            $filterConditions[] = [
                'geo_distance' => [
                    'distance' => (int) $pointDistance . self::DISTANCE_UNTIL,
                    'location' => [
                        'lat' => $this->locationLat,
                        'lon' => $this->locationLon,
                    ],
                ],
            ];

            if (isset($params[$locationKey]['rectangle'])) {
                list($topLeftLat, $topLeftLng, $bottomRightLat, $bottomRightLng) = $params[$locationKey]['rectangle'];
                if ($topLeftLat && $topLeftLng && $bottomRightLat && $bottomRightLng) {
                    $filterConditions[] = [
                        'geo_bounding_box' => [
                            'location' => [
                                'top_left' => [
                                    'lat' => $topLeftLat,
                                    'lon' => $topLeftLng,
                                ],
                                'bottom_right' => [
                                    'lat' => $bottomRightLat,
                                    'lon' => $bottomRightLng,
                                ],
                            ],
                        ],
                    ];
                }
            }

            $this->clustering = $params[$locationKey]['clustering'];
            $this->zoom = $params[$locationKey]['zoom'];
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
     * @param array $rules
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function collectRules(array $rules = [], array $params = []) : array
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
                    case self::RULE_RANGE_NUMBER:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!isset($params[$key]['min']) || !isset($params[$key]['max'])) {
                                throw new Exception('Wrong value for RULE_RANGE_NUMBER, key is - ' . $key . '. Required field is min/max');
                            }
                            $data[] = [
                                'range' => [
                                    $relation => [
                                        'gte' => (float) $params[$key]['min'],
                                        'lte' => (float) $params[$key]['max'],
                                    ]
                                ],
                            ];
                        }
                        break;
                    case self::RULE_RANGE_DATE:
                        foreach ($relations as $key => $relation) {
                            if (!isset($params[$key])) {
                                continue;
                            }
                            if (!isset($params[$key]['from']) || !isset($params[$key]['to'])) {
                                throw new Exception('Wrong value for RULE_RANGE_DATE, key is - ' . $key . '. Required field is from/to');
                            }
                            $data[] = [
                                'range' => [
                                    $relation => [
                                        'gte' => $params[$key]['from'],
                                        'lte' => $params[$key]['to'],
                                        'format' => 'yyyy-MM-dd',
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
     * @param array $searchResult
     * @return array
     */
    private function prepareResult(array $searchResult = []) : array
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

    /**
     * @param array $data
     * @return array
     */
    private function prepareClustering(array $data = []) : array
    {
        $geo = new Geohash();

        $groups = [];

        foreach ($data as $item) {
            $geoHash = $geo->encode($item['location']['lat'], $item['location']['lon'], 6);
            $groups[$geoHash][] = $item;
        }

        $result = [];
        $key = 0;

        foreach ($groups as $group) {
            foreach ($group as $item) {
                $result[$key][] = $item;
            }
            $key++;
        }

        return $result;
    }

    /**
     * @param array $conditions
     * @return array
     */
    private function prepareOverWriteRules(array $conditions = []) : array
    {
        $result = ['isChanged' => true];

        $conditions = $conditions['query'];

        foreach ($this->overWriteRules as $group => $rules) {
            $conditions[$group] = array_merge($this->overWriteRules[$group], $conditions[$group] ?? []);
        }

        $result['query'] = $conditions;

        return $result;
    }

}