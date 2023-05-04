# Elasticsearch it`s easy SDK

Elasticsearch it`s easy SDK for working with Elasticsearch like with constructor

## Documentation

The documentation for the Elasticsearch REST API can be found [here](https://www.elastic.co/guide/en/elasticsearch/reference/current/rest-apis.html).

## Installation

The preferred way to install this extension is through [composer](https://getcomposer.org/download/).

Either run

```
composer require idapgroup/elasticsearch-its-easy
```

or add

```json
{
  "require": {
    "idapgroup/elasticsearch-its-easy": "^1.0.0"
  }
}
```

to the requirement section of your `composer.json` file.

## Quickstart

### Prepare the data you want to store in elasticsearch

```php
$data = [
    [
        'user' => [
            'id' => 100,
            'email' => 'stepan21@gmail.com',
            'name' => 'Stepan',
            'age' => 21,
            'birthday' => '2001-06-15',
        ],
        'work' => [
            'position' => [
                'id' => 25,
                'name' => 'php developer',
            ],
            'skills' => [
                [
                    'id' => 36,
                    'name' => 'php'
                ],
                [
                    'id' => 40,
                    'name' => 'mysql'
                ],
                [
                    'id' => 56,
                    'name' => 'js'
                ],
            ],
            'salary' => 4000
        ],
        'location' => [
            'lat' => 50.445077,
            'lon' => 30.521215
        ],
    ],
    [
        'user' => [
            'id' => 101,
            'email' => 'luigi@gmail.com',
            'name' => 'Luigi',
            'age' => 29,
            'birthday' => '2005-03-20',
        ],
        'work' => [
            'position' => [
                'id' => 12,
                'name' => 'js developer',
            ],
            'skills' => [
                [
                    'id' => 56,
                    'name' => 'js'
                ],
                [
                    'id' => 70,
                    'name' => 'mongodb'
                ],
                [
                    'id' => 1,
                    'name' => 'html'
                ],
                [
                    'id' => 2,
                    'name' => 'css'
                ],
            ],
            'salary' => 2700
        ],
        'location' => [
            'lat' => 47.454589,
            'lon' => 32.915673
        ],
    ],
];
```

### Create your class to be expanded by basic search

```php
use IdapGroup\ElasticsearchItsEasy\ModelSearchBase;

class StaffModelSearch extends ModelSearchBase
{   
    public function setRules() : void
    {
        $this->rules = [
            self::GROUP_MUST => [
                self::RULE_EQUAL => [
                    'userId' => 'user.id',
                ],
            ],
            self::GROUP_SHOULD => [
                self::RULE_LIKE => [
                    'userEmail' => 'user.email',
                    'userName' => 'user.name',
                ],
                self::RULE_IN => [
                    'workSkillsId' => 'work.skills.id',
                ],
            ],
            self::GROUP_FILTER => [
                self::RULE_EQUAL => [
                    'workPositionId' => 'work.position.id'
                ],
                self::RULE_RANGE_NUMBER => [
                    'userAge' => 'user.age',
                    'workSalary' => 'work.salary',
                ],
                self::RULE_RANGE_DATE => [
                    'birthday' => 'user.birthday',
                ],
            ],
            self::GROUP_LOCATION => [
                'location' => self::SORT_DESC,
            ],
        ];
    }
   
    public function setSort() : void
    {
        $this->sort = [
            'user.id' => self::SORT_DESC,
        ];
    }

}
```

### Create model based on elasticsearch configuration

```php
$staffModelSearch = new StaffModelSearch('es01', '9200', 'staff_search');
```

### Indexing Documents in Elasticsearch

```php
$staffModelSearch->reCreateIndex();

foreach ($data as $item) {
    $staffModelSearch->addDocument($item, 'user.id', $item['user']['id']);
}
```

### Example #1: search as list with pagination

#### Specify search keys and their values

```php
$params = [
    'userId' => 100,
    'workPositionId' => 25,
    'userEmail' => 'stepan21@gmail.com',
    'userName' => 'Stepan',
    'workSkillsId' => [36, 40],
    'userAge' => ['min' => 18, 'max' => 65],
    'workSalary' => ['min' => 500, 'max' => 5000],
    'location' => [
        'point' => [
            'lat' => 48.454589,
            'lon' => 33.915673,
            'distance' => 100000
        ],
        'rectangle' => [
            'topLeftLat' => 55.710929,
            'topLeftLng' => 14.090451,
            'bottomRightLat' => 41.830140,
            'bottomRightLng' => 41.802791
        ],
    ],
    'page' => 1,
    'limit' => 20
];
```

### Set data output limits if required and search

```php
//$staffModelSearch->enableFixLimitResult(50);
$result = $staffModelSearch->searchList($params);
```

### The result of the response will be in the format

```php
[
    'result' => [
        [
            'user' => [
                'id' => 100,
                'email' => 'stepan21@gmail.com',
                'name' => 'Stepan',
                'age' => 21
            ],
            'work' => [
                'position' => [
                    'id' => 25,
                    'name' => 'php developer',
                ],
                'skills' => [
                    [
                        'id' => 36,
                        'name' => 'php'
                    ],
                    [
                        'id' => 40,
                        'name' => 'mysql'
                    ],
                    [
                        'id' => 56,
                        'name' => 'js'
                    ],
                ],
                'salary' => 4000
            ],
            'location' => [
                'lat' => 50.445077,
                'lon' => 30.521215
            ],
        ],
        //... etc.
    ],
    'pagination' => [
        'totalCount' => (int),
        'pageCount' => (int),
        'currentPage' => (int)
    ]
]
```

### Example #2: search for map

#### Specify search keys and their values

```php
$params = [
    'userId' => 100,
    'workPositionId' => 25,
    'userEmail' => 'stepan21@gmail.com',
    'userName' => 'Stepan',
    'workSkillsId' => [36, 40],
    'userAge' => ['min' => 18, 'max' => 65],
    'workSalary' => ['min' => 500, 'max' => 5000],
    'location' => [
        'point' => [
            'lat' => 48.454589,
            'lon' => 33.915673,
            'distance' => 100000
        ],
        'rectangle' => [
            'topLeftLat' => 55.710929,
            'topLeftLng' => 14.090451,
            'bottomRightLat' => 41.830140,
            'bottomRightLng' => 41.802791
        ],       
    ],
];
```

#### Execute search

```php
$result = $staffModelSearch->searchMap($params);
```

### The result of the response will be in the format

```php
[
    [
        'user' => [
            'id' => 100,
            'email' => 'stepan21@gmail.com',
            'name' => 'Stepan',
            'age' => 21
        ],
        'work' => [
            'position' => [
                'id' => 25,
                'name' => 'php developer',
            ],
            'skills' => [
                [
                    'id' => 36,
                    'name' => 'php'
                ],
                [
                    'id' => 40,
                    'name' => 'mysql'
                ],
                [
                    'id' => 56,
                    'name' => 'js'
                ],
            ],
            'salary' => 4000
        ],
        'location' => [
            'lat' => 50.445077,
            'lon' => 30.521215
        ],
    ],
    //... etc.
]
```

## Additional settings

#### Custom clustering (useful when markers on the map overlap each other and the maximum zoom does not solve the problem)

```php
$params = [
    //...
    'location' => [
        'point' => [
            'lat' => 48.454589,
            'lon' => 33.915673,
            'distance' => 100000
        ],
        'rectangle' => [
            'topLeftLat' => 55.710929,
            'topLeftLng' => 14.090451,
            'bottomRightLat' => 41.830140,
            'bottomRightLng' => 41.802791
        ],
        'clustering' => true,
        'zoom' => 1,
    ],
    //...
];
```

#### Response structure example

```php
[
    // Cluster 1
    [
        [
            // data
        ],
        [
            // data
        ],
        //...
    ],
    // Cluster 2
    [
        [
            // data
        ],
        [
            // data
        ],
        //...
    ],
    //... etc.
]
```

#### Overwrite rules

```php
// Describe the rules in the associative array as required by the ES documentation
$overWriteRules = [
    'must' => [
        [
            'term' => [
                'user.name.keyword' => 'Stepan',
            ],
        ],
        [
            'term' => [
                'user.age' => 21,
            ],
        ],
        //...
    ],
    //...
];

// Use a Method to Set Your Rules
$staffModelSearch->setOverWriteRules($overWriteRules);
```