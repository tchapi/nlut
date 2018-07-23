#!/usr/bin/env php
<?php

class Transcoder
{
    const TYPE_DIALOGFLOW = 'DialogFlow';
    const TYPE_WIT = 'Wit.ai';
    const TYPE_ALEXA = 'Alexa';

    const FORMAT_DIALOGFLOW = 'DIALOGFLOW';
    const FORMAT_WIT = 'WIT';
    const FORMAT_ALEXA = 'ALEXA';

    private $type = null;
    private $name = null;
    private $lang = null;

    private $appInfo = [];
    private $entities = [];
    private $expressions = [];
    private $intents = [];

    public function __construct()
    {
        if (!class_exists('ZipArchive')) {
            echo "Error : You need the ZipArchive class for this tool to work.\n\n";
            exit;
        }
    }

    private function generateGuid()
    {
        $charid = strtoupper(bin2hex(openssl_random_pseudo_bytes(16)));
        $guid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($charid, 4));

        return strtolower($guid);
    }

    private function processAlexaJsonFile(array $contents)
    {
        $this->lang = 'fr'; // Default
        $this->appInfo = [
          'version' => (new \Datetime())->format('Ymd'),
          'zip-command' => '',
          'data' => [
            'name' => $contents['interactionModel']['languageModel']['invocationName'].'',
            'description' => '',
            'lang' => $this->lang,
          ],
        ];

        // Get entity for each slot name (will be used later)
        $slots = [];
        $slotValues = [];
        foreach ($contents['interactionModel']['dialog']['intents'] as $intent) {
            foreach ($intent['slots'] as $slot) {
                $slots[$slot['name']] = $slot['type'];
            }
        }
        $discoveredEntities = array_values($slots);

        $this->entities = [];
        foreach ($contents['interactionModel']['languageModel']['types'] as $entity) {
            $name = $entity['name'];
            // Store a value for each entity, for expressions later
            $slotValues[$name] = $entity['values'][0]['name']['value'];
            $values = [];
            foreach ($entity['values'] as $value) {
                $values[] = [
                    'value' => $value['name']['value'],
                    'expressions' => isset($value['name']['synonyms']) ? $value['name']['synonyms'] : [],
                ];
            }
            $this->entities[$name] = [
              'data' => [
                'lookups' => [
                  'keywords',
                ],
                'name' => $name,
                'lang' => 'fr',
                'exotic' => false,
                'id' => $name,
                'values' => $values,
                'doc' => 'User-defined entity',
                'builtin' => false,
              ],
            ];
        }
        // if an discovered entity does not have a slot value (ex : AMAZON.DATE)
        // add a default
        foreach (array_values($slots) as $item) {
            if (!isset($slotValues[$item])) {
                $slotValues[$item] = 'PLACEHOLDER';
            }
        }
        foreach ($discoveredEntities as $key => $value) {
            if (isset($this->entities[$value])) {
                continue;
            }
            $this->entities[$value] = [
              'data' => [
                'lookups' => [
                  'keywords',
                ],
                'name' => $value,
                'lang' => 'fr',
                'exotic' => false,
                'id' => $value,
                'values' => [
                  [
                    'value' => $slotValues[$value],
                    'expressions' => [
                      $slotValues[$value],
                    ],
                  ],
                ],
                'doc' => 'User-defined entity',
                'builtin' => false,
              ],
            ];
        }

        $this->intents = [
          'data' => [
            'lookups' => [
              'trait',
            ],
            'name' => 'intent',
            'lang' => $this->lang,
            'exotic' => false,
            'id' => 'intent',
            'values' => [],
            'doc' => 'User-defined entity',
            'builtin' => false,
          ],
        ];
        $this->expressions = [
            'data' => [],
        ];
        foreach ($contents['interactionModel']['languageModel']['intents'] as $intent) {
            if (in_array($intent['name'], ['AMAZON.FallbackIntent', 'AMAZON.CancelIntent', 'AMAZON.HelpIntent', 'AMAZON.StopIntent'])) {
                continue;
            }
            $this->intents['data']['values'][] = [
                'value' => $intent['name'],
            ];

            foreach ($intent['samples'] as $phrase) {
                $temp = [
                    'entities' => [
                        [
                            'entity' => 'intent',
                            'value' => $intent['name'],
                        ],
                    ],
                ];
                // For each slot
                $offset = 0;
                $diffS = 0;
                $diffL = 0;
                $realPhrase = '';
                for ($i = 0; $i < substr_count($phrase, '{'); ++$i) {
                    $pos = strpos($phrase, '{', $offset);
                    $posE = strpos($phrase, '}', $offset);
                    $slotName = substr($phrase, $pos + 1, $posE - $pos - 1);
                    $diffL += strlen($slotName) + 2 - strlen($slotValues[$slots[$slotName]]);
                    $temp['entities'][] = [
                        'entity' => $slots[$slotName],
                        'value' => $slotValues[$slots[$slotName]],
                        'start' => $pos - $diffS,
                        'end' => $posE - $diffL,
                    ];
                    $realPhrase .= substr($phrase, $offset, $pos - $offset).$slotValues[$slots[$slotName]];
                    $offset = $posE + 1;
                    $diffS = $diffL;
                }
                $temp['text'] = $realPhrase;

                $this->expressions['data'][] = $temp;
            }
        }
    }

    private function processWitArchive(array $contentsTemp)
    {
        $contents = [];

        // Remove first folder from ZIP path
        foreach ($contentsTemp as $key => $value) {
            $realFileName = explode('/', $key, 2)[1];
            $contents[$realFileName] = $value;
        }

        // Set app info
        $this->appInfo = $contents['app.json'];
        unset($contents['app.json']);

        $this->name = $this->appInfo['data']['name'];
        $this->lang = $this->appInfo['data']['lang'];

        // Set expressions
        $this->expressions = ['data' => []];
        foreach ($contents['expressions.json']['data'] as $ex) {
            $temp = [
                'text' => $ex['text'],
                'entities' => [],
            ];
            if (isset($ex['entities'])) {
                foreach ($ex['entities'] as $en) {
                    $en['value'] = str_replace('"', '', $en['value']);
                    $temp['entities'][] = $en;
                }
            }
            $this->expressions['data'][] = $temp;
        }
        unset($contents['expressions.json']);

        // Extract entities
        foreach ($contents as $entity => $value) {
            if ('entities/intent.json' == $entity) {
                $this->intents = $value;
                continue;
            }
            $entityName = $value['data']['name'];
            $this->entities[$entityName] = $value;
        }
    }

    private function processDialogFlowArchive(array $contents)
    {
        $this->name = $contents['agent.json']['googleAssistant']['project'];
        $this->lang = $contents['agent.json']['language'];

        $this->appInfo = [
          'version' => $contents['package.json']['version'],
          'zip-command' => 'zip '.$this->name.'.zip '.$this->name.'/app.json '.$this->name.'/entities/*.json '.$this->name.'/expressions.json',
          'data' => [
            'name' => $contents['agent.json']['googleAssistant']['project'],
            'description' => $contents['agent.json']['description'],
            'lang' => $contents['agent.json']['language'],
          ],
        ];
        unset($contents['agent.json']);
        unset($contents['package.json']);
        array_keys($contents);

        // Intents and expressions at the same time
        $this->intents = [
          'data' => [
            'lookups' => [
              'trait',
            ],
            'name' => 'intent',
            'lang' => $this->lang,
            'exotic' => false,
            'id' => 'intent',
            'values' => [],
            'doc' => 'User-defined entity',
            'builtin' => false,
          ],
        ];
        $this->expressions = [
            'data' => [],
        ];
        $discoveredEntities = [];
        $discoveredValues = [];
        foreach ($contents as $key => $value) {
            if ('intents' != substr($key, 0, 7)) {
                continue;
            }
            if ('intents/Default Fallback Intent.json' == $key) {
                continue;
            }
            if (substr($key, -15 - strlen($this->lang)) == '_usersays_'.$this->lang.'.json') {
                $name = substr($key, 8, -15 - strlen($this->lang));
                foreach ($value as $phrase) {
                    $entities = [
                        [
                            'entity' => 'intent',
                            'value' => $name,
                        ],
                    ];
                    $nextStart = 0;
                    $text = '';
                    foreach ($phrase['data'] as $phraseSegment) {
                        $text .= $phraseSegment['text'];
                        if (isset($phraseSegment['alias'])) {
                            $entities[] = [
                              'entity' => $phraseSegment['alias'],
                              'value' => $phraseSegment['text'],
                              'start' => $nextStart,
                              'end' => $nextStart + strlen($phraseSegment['text']),
                            ];
                            // Add text in values
                            $discoveredValues[$phraseSegment['alias']][] = $phraseSegment['text'];
                        }
                        $nextStart += strlen($phraseSegment['text']);
                    }
                    $this->expressions['data'][] = [
                        'text' => $text,
                        'entities' => $entities,
                    ];
                }
            } else {
                $this->intents['data']['values'][] = [
                  'value' => $value['name'],
                ];
            }
            if (isset($value['responses']) && count($value['responses']) > 0) {
                foreach ($value['responses'][0]['parameters'] as $param) {
                    $discoveredEntities[] = $param['name'];
                }
            }
            unset($contents[$key]);
        }

        // Then parse entities
        $entities = [];
        $values = [];
        foreach ($contents as $key => $value) {
            if ('entities' != substr($key, 0, 8)) {
                continue;
            }
            if (substr($key, -14 - strlen($this->lang)) == '_entries_'.$this->lang.'.json') {
                $name = substr($key, 9, -14 - strlen($this->lang));
                $values[$name] = [];
                foreach ($value as $valueItem) {
                    $values[$name][] = [
                        'value' => $valueItem['value'],
                        'expressions' => $valueItem['synonyms'],
                    ];
                }
            } else {
                $entities[$value['name']] = [
                  'data' => [
                    'lookups' => [
                      'keywords',
                    ],
                    'name' => $value['name'],
                    'lang' => $this->lang,
                    'exotic' => false,
                    'id' => $value['name'],
                    'values' => [],
                    'doc' => 'User-defined entity',
                    'builtin' => false,
                  ],
                ];
            }
            unset($contents[$key]);
        }
        // Add discovered entities
        foreach (array_unique($discoveredEntities) as $key => $value) {
            $entities[$value] = [
              'data' => [
                'lookups' => [
                  'free-text',
                ],
                'name' => $value,
                'lang' => $this->lang,
                'exotic' => false,
                'id' => $value,
                'values' => [],
                'doc' => 'User-defined entity',
                'builtin' => false,
              ],
            ];
        }
        foreach ($entities as $key => $value) {
            $this->entities[$key] = $value;
            if (isset($values[$key])) {
                $this->entities[$key]['data']['values'] = $values[$key];
            } elseif (isset($discoveredValues[$key]) && count($discoveredValues[$key]) > 0) {
                $newValues = [
                    [
                        'value' => $discoveredValues[$key][0],
                        'expressions' => [],
                    ],
                ];
                foreach (array_unique($discoveredValues[$key]) as $j => $value) {
                    $newValues[0]['expressions'][] = $value;
                }
                $this->entities[$key]['data']['values'] = $newValues;
            }
        }
    }

    private function toWitArchive(string $filename): array
    {
        $zipName = pathinfo($filename)['filename'];

        $contents = [];
        $contents[$zipName.'/'.'app.json'] = $this->appInfo;
        $contents[$zipName.'/'.'app.json']['zip-command'] = 'zip '.$filename.' '.$zipName.'/app.json '.$zipName.'/entities/*.json '.$zipName.'/expressions.json';

        // Add quotes to expressions, remove empty entities
        foreach ($this->expressions['data'] as &$expr) {
            if (0 === count($expr['entities'])) {
                unset($expr['entities']);
                continue;
            }
            foreach ($expr['entities'] as &$en) {
                $en['value'] = '"'.$en['value'].'"';
            }
        }

        foreach ($this->entities as $key => $value) {
            $contents[$zipName.'/'.'entities/'.$key.'.json'] = $value;
        }
        $contents[$zipName.'/'.'entities/intent.json'] = $this->intents;

        // expressions.json MUST be the last file in the zip
        $contents[$zipName.'/'.'expressions.json'] = $this->expressions;

        return $contents;
    }

    private function toAlexaFile(string $filename): array
    {
        $contents = [
            'interactionModel' => [
                'languageModel' => [
                    'invocationName' => $this->name,
                    'intents' => [
                        [
                            'name' => 'AMAZON.FallbackIntent',
                            'samples' => [],
                        ],
                        [
                            'name' => 'AMAZON.CancelIntent',
                            'samples' => [],
                        ],
                        [
                            'name' => 'AMAZON.HelpIntent',
                            'samples' => [],
                        ],
                        [
                            'name' => 'AMAZON.StopIntent',
                            'samples' => [],
                        ],
                    ],
                    'types' => [],
                ],
                'dialog' => [
                    'intents' => [],
                ],
            ],
        ];

        // Intents
        foreach ($this->intents['data']['values'] as $intent) {
            $name = $intent['value'];

            $slots = [];
            $slots2 = [];

            $params = [];
            $phrases = [];

            // Search all expressions that have that intent, and extract all the parameters
            foreach ($this->expressions['data'] as $ex) {
                $keep = false;
                $paramsTemp = [];
                $phrasesTemp = [];
                if (!isset($ex['entities'])) {
                    continue;
                }
                foreach ($ex['entities'] as $en) {
                    if ('intent' == $en['entity'] && $en['value'] == $name) {
                        $keep = true;
                    } else {
                        $paramsTemp[] = $en['entity'];
                        $phrasesTemp[] = $ex;
                    }
                }
                if ($keep) {
                    $phrases = array_merge($phrases, $phrasesTemp);
                    $params = array_unique(array_merge($params, $paramsTemp));
                }
            }
            foreach ($params as $param) {
                $slots[] = [
                    'name' => $param,
                    'type' => $param,
                ];
                $slots2[] = [
                    'name' => $param,
                    'type' => $param,
                    'confirmationRequired' => false,
                    'elicitationRequired' => false,
                    'prompts' => [],
                ];
            }

            $samples = [];

            foreach ($phrases as $phrase) {
                // Construct data from start/end
                $text = $phrase['text'];
                foreach ($phrase['entities'] as $entity) {
                    if ('intent' == $entity['entity']) {
                        continue;
                    }
                    if (isset($entity['start']) && $entity['start'] > 0) {
                        $text = substr_replace($text, '{'.$entity['entity'].'}', $entity['start'], $entity['end'] - $entity['start'] + 1);
                    }
                }
                $samples[] = $text;
            }
            $contents['interactionModel']['languageModel']['intents'][] = [
                'name' => $name,
                'slots' => $slots,
                'samples' => array_values(array_unique($samples)),
            ];
            $contents['interactionModel']['dialog']['intents'][] = [
                'name' => $name,
                'confirmationRequired' => false,
                'prompts' => [],
                'slots' => $slots2,
            ];
        }

        // Entities
        foreach ($this->entities as $key => $entity) {
            $name = $entity['data']['name'];
            foreach ($entity['data']['values'] as $value) {
                $values[] = [
                    'name' => [
                        'value' => $value['value'],
                        'synonyms' => isset($value['expressions']) ? $value['expressions'] : [],
                    ],
                ];
            }
            $contents['interactionModel']['languageModel']['types'][] = [
                'name' => $name,
                'values' => $values,
            ];
        }

        return $contents;
    }

    private function toDialogFlowArchive(string $filename): array
    {
        $contents = [];

        $contents['package.json'] = [
            'version' => $this->appInfo['version'],
        ];

        $contents['agent.json'] = [
          'description' => $this->appInfo['data']['description'],
          'language' => $this->lang,
          'activeAssistantAgents' => [],
          'disableInteractionLogs' => false,
          'disableStackdriverLogs' => true,
          'googleAssistant' => [
            'googleAssistantCompatible' => true,
            'project' => $this->name,
            'welcomeIntentSignInRequired' => false,
            'startIntents' => [],
            'systemIntents' => [],
            'endIntentIds' => [],
            'oAuthLinking' => [
              'required' => false,
              'grantType' => 'AUTH_CODE_GRANT',
            ],
            'voiceType' => 'MALE_1',
            'capabilities' => [],
            'protocolVersion' => 'V1',
            'isDeviceAgent' => false,
          ],
          'defaultTimezone' => 'Europe/Paris',
          'webhook' => [
            'url' => null,
            'headers' => [
              '' => '',
            ],
            'available' => true,
            'useForDomains' => false,
            'cloudFunctionsEnabled' => false,
            'cloudFunctionsInitialized' => false,
          ],
          'isPrivate' => true,
          'customClassifierMode' => 'use.after',
          'mlMinConfidence' => 0.2,
          'supportedLanguages' => [],
          'onePlatformApiVersion' => 'v2',
          'analyzeQueryTextSentiment' => false,
        ];

        foreach ($this->entities as $key => $value) {
            $contents['entities/'.$key.'.json'] = [
              'id' => $this->generateGuid(),
              'name' => $key,
              'isOverridable' => true,
              'isEnum' => false,
              'automatedExpansion' => false,
            ];

            $contents['entities/'.$key.'_entries_'.$this->lang.'.json'] = [];

            foreach ($value['data']['values'] as $item) {
                $contents['entities/'.$key.'_entries_'.$this->lang.'.json'][] = [
                    'value' => $item['value'],
                    'synonyms' => isset($item['expressions']) ? $item['expressions'] : [],
                ];
            }
        }

        foreach ($this->intents['data']['values'] as $key => $value) {
            $intent = $value['value'];
            $parameters = [];

            $params = [];
            $phrases = [];

            // Search all expressions that have that intent, and extract all the parameters
            foreach ($this->expressions['data'] as $ex) {
                $keep = false;
                $paramsTemp = [];
                $phrasesTemp = [];
                if (!isset($ex['entities'])) {
                    continue;
                }
                foreach ($ex['entities'] as $en) {
                    if ('intent' == $en['entity'] && $en['value'] == $intent) {
                        $keep = true;
                    } else {
                        $paramsTemp[] = $en['entity'];
                        $phrasesTemp[] = $ex;
                    }
                }
                if ($keep) {
                    $phrases = array_merge($phrases, $phrasesTemp);
                    $params = array_merge($params, $paramsTemp);
                }
            }

            foreach ($params as $param) {
                $parameters[] = [
                      'id' => $this->generateGuid(),
                      'required' => true,
                      'dataType' => '@'.$param,
                      'name' => $param,
                      'value' => '$'.$param,
                      'isList' => false,
                ];
            }

            $contents['intents/'.$intent.'.json'] = [
                'id' => $this->generateGuid(),
                'name' => $intent,
                'auto' => true,
                'contexts' => [],
                'responses' => [
                    [
                      'resetContexts' => false,
                      'affectedContexts' => [],
                      'parameters' => $parameters,
                      'messages' => [],
                      'defaultResponsePlatforms' => [],
                      'speech' => [],
                    ],
                ],
                'priority' => 1000000,
                'webhookUsed' => false,
                'webhookForSlotFilling' => false,
                'lastUpdate' => 1490879523,
                'fallbackIntent' => false,
                'events' => [],
            ];

            $contents['intents/'.$intent.'_usersays_'.$this->lang.'.json'] = [];

            foreach ($phrases as $phrase) {
                // Construct data from start/end
                $text = $phrase['text'];
                $offset = 0;
                $datas = [];
                foreach ($phrase['entities'] as $entity) {
                    if ('intent' == $entity['entity']) {
                        continue;
                    }
                    if (isset($entity['start']) && $entity['start'] > 0) {
                        $datas[] = [
                            'text' => substr($text, 0 - $offset, $entity['start'] - $offset),
                            'userDefined' => false,
                        ];
                    }
                    $datas[] = [
                        'text' => substr($text, $entity['start'] - $offset, $entity['end'] - $offset),
                        'alias' => $entity['entity'],
                        'meta' => '@'.$entity['entity'],
                        'userDefined' => false,
                    ];

                    $text = substr($text, $entity['end'] - $offset);
                    $offset -= $entity['end'] - $offset;
                }

                $contents['intents/'.$intent.'_usersays_'.$this->lang.'.json'][] = [
                    'id' => $this->generateGuid(),
                    'data' => $datas,
                    'isTemplate' => false,
                    'count' => 1,
                    'updated' => 1490878988,
                ];
            }
        }

        $contents['intents/Default Fallback Intent.json'] = [
          'id' => $this->generateGuid(),
          'name' => 'Default Fallback Intent',
          'auto' => true,
          'contexts' => [],
          'responses' => [
            [
              'resetContexts' => false,
              'action' => 'input.unknown',
              'affectedContexts' => [],
              'parameters' => [],
              'messages' => [],
              'defaultResponsePlatforms' => [],
              'speech' => [],
            ],
          ],
          'priority' => 500000,
          'webhookUsed' => false,
          'webhookForSlotFilling' => false,
          'fallbackIntent' => true,
          'events' => [],
        ];

        return $contents;
    }

    private function help()
    {
        echo "🔠  NLU Transcoder 1.0\n";
        echo "tchapi <https://github.com/tchapi>\n\n";
        echo "USAGE\n";
        echo "    ./nlut.php [OPTIONS]\n\n";
        echo "FLAGS:\n";
        echo "    --help    Displays this help message\n\n";
        echo "OPTIONS:\n";
        echo "    --source  <FILE>    The source file (a .zip file or a .json file)\n";
        echo "    --export  <FILE>    [Optional] The destination file\n";
        echo "    --format  <FORMAT>  [Optional] The destination format\n\n";
        echo "AVAILABLE FORMATS:\n";
        echo "    DIALOGFLOW\n";
        echo "    WIT\n";
        echo "    ALEXA\n\n";
    }

    public function run()
    {
        $longopts = [
            'help',
            'source:',
            'export:',
            'format:',
        ];
        $options = getopt('', $longopts);

        if (isset($options['help'])) {
            $this->help();
            exit;
        }

        $filename = isset($options['source']) ? $options['source'] : null;
        if (!$filename) {
            echo "Error : You must at least provide a file (.zip, .json) with the --source option.\n\n";
            $this->help();
            exit;
        }

        $zip = zip_open($filename);
        if (is_resource($zip)) {
            while ($zip_entry = zip_read($zip)) {
                $name = zip_entry_name($zip_entry);
                $s = zip_entry_filesize($zip_entry);
                if (zip_entry_open($zip, $zip_entry)) {
                    $c = zip_entry_read($zip_entry, $s);
                    $contents[$name] = json_decode($c, true);
                    switch (json_last_error()) {
                        case JSON_ERROR_NONE:
                        break;
                        case JSON_ERROR_DEPTH:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Maximum depth reached\n";
                        break;
                        case JSON_ERROR_STATE_MISMATCH:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Underflow or modes do not match\n";
                        break;
                        case JSON_ERROR_CTRL_CHAR:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Character control error\n";
                        break;
                        case JSON_ERROR_SYNTAX:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Malformed JSON\n";
                        break;
                        case JSON_ERROR_UTF8:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Encoding error - check UTF-8 characters\n";
                        break;
                        default:
                            echo $name.' ('.($s / 1000).' kb)';
                            echo " - Unknown error\n";
                        break;
                    }

                    zip_entry_close($zip_entry);
                }
            }

            zip_close($zip);

            if (isset($contents['agent.json'])) {
                $this->type = self::TYPE_DIALOGFLOW;
                $this->processDialogFlowArchive($contents);
            } else {
                $this->type = self::TYPE_WIT;
                $this->processWitArchive($contents);
            }
        } else {
            if (file_exists($filename)) {
                $contents = json_decode(file_get_contents($filename), true);
            } else {
                echo 'Error : File '.$filename." does not exist.\n\n";
                $this->help();
                exit;
            }
            $this->type = self::TYPE_ALEXA;
            $this->processAlexaJsonFile($contents);
        }

        $this->output();

        if (isset($options['export']) && isset($options['format'])) {
            switch ($options['format']) {
                case self::FORMAT_DIALOGFLOW:
                    echo "Exporting to the DialogFlow format\n";
                    $newContents = $this->toDialogFlowArchive($options['export']);
                    break;
                case self::FORMAT_WIT:
                    echo "Exporting to the Wit format\n";
                    $newContents = $this->toWitArchive($options['export']);
                    break;
                case self::FORMAT_ALEXA:
                    echo "Exporting to the Alexa Skill format\n";
                    $newContents = $this->toAlexaFile($options['export']);
                    break;
                default:
                    echo 'Error : '.$options['format']." is not a valid format.\n\n";
                    exit;
                    break;
            }
            $this->export($newContents, $options['format'], $options['export']);
            echo 'File '.$options['export']." written.\n";
        }
    }

    private function output()
    {
        echo '----------------------------------------'."\n";
        echo 'This is a '.$this->type." archive\n";
        echo 'App name     : '.$this->appInfo['data']['name']."\n";
        echo 'Entities     : '.count($this->entities)."\n";
        echo 'Intents      : '.(isset($this->intents['data']) ? count($this->intents['data']['values']) : '—')."\n";
        echo 'Expressions  : '.(isset($this->expressions['data']) ? count($this->expressions['data']) : '—')."\n";
        echo '----------------------------------------'."\n";
    }

    private function prettify(array $contents): string
    {
        $json = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Make JSON file exactly the same from the original format (2 spaces, space before semicolon)
        $json2 = str_replace('": ', '" : ', $json);

        return preg_replace('/^ {4}|\G {4}/Sm', '  ', $json2);
    }

    private function export(array $newContents, string $format, string $filename)
    {
        if (self::FORMAT_ALEXA === $format) {
            $jsonFile = fopen($filename, 'w');
            fwrite($jsonFile, $this->prettify($newContents));
            fclose($jsonFile);
        } else {
            $zip = new ZipArchive();
            if (true === $zip->open($filename, ZipArchive::CREATE)) {
                foreach ($newContents as $key => $value) {
                    $zip->addFromString($key, $this->prettify($value));
                }
                $zip->close();
            }
        }
    }
}

(new Transcoder())->run();
