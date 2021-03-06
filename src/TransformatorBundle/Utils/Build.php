<?php

namespace TransformatorBundle\Utils;

use AppBundle\Entity;
use Doctrine\ORM\EntityManager;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class Build {

    /** @var EntityManager */
    private $em;

    /** @var Geolocation */
    private $geolocation;

    /** @var DumpingService */
    private $dumpingService;

    private $user;

    /** @var \Doctrine\ORM\EntityRepository */
    private $schoolRepository;

    /** @var \Elasticsearch\Client */
    private $elastic;

    private $elasticIndex;
    private $elasticType;

    private $currentSchool;
    private $reportEnabled = TRUE;
    private $locationLevel = NULL;
    private $vars = [];

	public function __construct(TokenStorage $tokenStorage, EntityManager $entityManager, Geolocation $geolocation,
                                DumpingService $dumpingService, $elasticAddress, $elasticIndex, $elasticType) {
        mb_internal_encoding("UTF-8");

        // testing mode
        if ($tokenStorage->getToken() === NULL)
            return;

        $this->em = $entityManager;
        $this->geolocation = $geolocation;
        $this->dumpingService = $dumpingService;

        $this->user = $this->em->getRepository('AppBundle:User')->find($tokenStorage->getToken()->getUser()->getId());
        $this->schoolRepository = $this->em->getRepository('AppBundle:School');
        $this->levelRepository = $this->em->getRepository('AppBundle:Level');
        $this->logRepository = $this->em->getRepository('AppBundle:Log');

        // configure elastic connection
        $this->elastic = ClientBuilder::create()
            ->setHosts([ $elasticAddress ])
            ->build();

        $this->elasticIndex = $elasticIndex;
        $this->elasticType = $elasticType;
	}

    /**
     * Do the build - loop through all the schools:
     * - loop through the last versions of the school data from each data provider
     * - merge the results according to priority
     */
    public function build($limit = NULL, $offset = NULL) {
        $query = $this->em->createQuery(
            "SELECT s 
            FROM AppBundle:School s
            ORDER BY s.id"
        );

        if ($limit !== NULL) {
            $query->setFirstResult($offset);
            $query->setMaxResults($limit);
        }

        $schools = $query->iterate();
        $levels = $this->levelRepository->findBy([], [ 'priority' => 'DESC' ]);
        $levelIds = [];
        foreach ($levels as $level) {
            $levelIds[] = $level->getId();
        }

        $total = 0;
        $successful = 0;
        foreach ($schools as $school) {
            $school = $school[0];
            $schoolJson = new \stdClass;

            $empty = TRUE;
            foreach ($levelIds as $levelId) {
                $log = $this->em->createQuery(
                    "SELECT l
                    FROM AppBundle:Log l
                    WHERE l.school = :school_id AND l.level = :level_id
                    ORDER BY l.loggedOn DESC"
                )->setParameter('school_id', $school->getId())
                ->setParameter('level_id', $levelId)
                ->setMaxResults(1)
                ->getOneOrNullResult();

                if (!$log) {
                    continue;
                }

                $empty = FALSE;
                $json = Json::decode($log->getJsonData());

                $schoolJson = $this->mergeObjects($schoolJson, $json);
            }

            $isValid = $empty ? FALSE : $this->validateDocument($schoolJson);
            if (!$isValid) {
                $this->dumpingService->error($schoolJson);
            }

            $school->setLastBuildJsonData(Json::encode($schoolJson));
            $school->setIsValid($isValid);
            if ($isValid) {
                $successful++;
            }

            if ($total % 100 == 99) {
                $this->em->flush();
                $this->em->clear();
            }

            // $this->dumpingService->dump(Json::encode($schoolJson, Json::PRETTY));

            $total++;
        }
        $this->em->flush();

        $this->dumpingService->dump("successful: $successful/$total");
    }

    /**
     * Loop through all schools, find units or schools without location set and get the location
     * from an external sources and cache it to database. At the moment we are using openstreetmap.org
     * free API service.
     */
    public function cacheSchoolLocation($limit = NULL, $offset = NULL) {

        $total = 0;
        $successful = 0;

        $this->vars['google'] = 0;
        $this->vars['nominatim1'] = 0;
        $this->vars['nominatim2'] = 0;
        $this->vars['none'] = 0;
        $addressesNotEqual = 0;
        $addressesEqual = 0;

        $query = $this->em->createQuery(
            "SELECT s
            FROM AppBundle:School s
            WHERE s.isValid = 0
            ORDER BY s.id"
        );

        if ($limit !== NULL) {
            $query->setFirstResult($offset);
            $query->setMaxResults($limit);
        }

        $schools = $query->iterate();

        foreach ($schools as $row) {
            $school = $row[0];

            $schoolJson = Json::decode($school->getLastBuildJsonData());

            $newLocationFound = FALSE;
            $someLocationMissing = FALSE;
            $json = new \stdClass;
            $schoolAddress = isset($schoolJson->metadata->address) ? $schoolJson->metadata->address : NULL;
            $schoolLocation = isset($schoolAddress->location) ? $schoolAddress->location : NULL;
            if ($schoolAddress) {
                if (!$schoolLocation) {
                    $someLocationMissing = TRUE;
                    $schoolLocation = $this->geolocation->retrieveLocation($schoolAddress, $this->vars);
                    if ($schoolLocation) {
                        $newLocationFound = TRUE;
                    }
                }

                if ($schoolLocation) {
                    $json = $this->createUnitWithLocation($schoolLocation->lat, $schoolLocation->lon);
                }
            }

            if (isset($schoolJson->units)) {
                foreach ($schoolJson->units as $unit) {
                    if (!isset($unit->metadata->address)) {
                        continue;
                    }
                    $address = $unit->metadata->address;
                    if ($address) {
                        $location = NULL;
                        if (!isset($address->location)) {
                            $someLocationMissing = TRUE;
                            if ($schoolAddress && $this->areAddressesEqual($schoolAddress, $address)) {
                                $location = $schoolLocation;
                                $addressesEqual++;
                                $this->dumpingService->dump("equal");
                            } else {
                                $addressesNotEqual++;
                                $location = $this->geolocation->retrieveLocation($address, $this->vars);
                                $this->dumpingService->dump("not equal");
                                if ($schoolLocation) {
                                    $location = $schoolLocation;
                                    $this->dumpingService->dump("location found");
                                } else {
                                    $this->dumpingService->dump("location not found");
                                }
                            }
                        } else {
                            $location = $address->location;
                        }

                        if ($location) {
                            if (!isset($json->units)) {
                                $json->units = [];
                            }
                            $newUnit = $this->createUnitWithLocation($location->lat, $location->lon);
                            $newUnit->IZO = $unit->IZO;
                            $json->units[] = $newUnit;
                            $newLocationFound = TRUE;
                        }
                    }
                }
            }

            if ($newLocationFound) {
                $this->dumpingService->dump($json);
                if ($this->logSchoolLocation($json, $school)) {
                    $successful++;
                }
            }

            if ($someLocationMissing) {
                $total++;
            }


            // store to db every 100th loop
            if (($total) % 100 == 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $this->dumpingService->dump("successful: $successful/$total");
        $this->dumpingService->dump("addresses equal/not equal: $addressesEqual/$addressesNotEqual");
        $this->dumpingService->dump($this->vars);
    }

    private function createUnitWithLocation($lat, $lon) {
        $unit = new \stdClass;
        $unit->metadata = new \stdClass;
        $unit->metadata->address = new \stdClass;
        $unit->metadata->address->location = new \stdClass;
        $unit->metadata->address->location->lat = $lat;
        $unit->metadata->address->location->lon = $lon;
        return $unit;
    }

    /**
     * Log the location json to DB
     * @param $json object
     * @param $school array
     * @return boolean Was stored successfully
     */
    public function logSchoolLocation($json, $school) {
        $data = NULL;
        try {
            $data = Json::encode($json);
        } catch (JsonException $e) {
            $this->dumpingService->error($json);
            $this->dumpingService->error($e->getMessage());
            return FALSE;
        }

        if ($this->locationLevel === NULL) {
            $this->locationLevel = $this->levelRepository->findOneByName('Auto:location');
        }
        $level = $this->locationLevel;

        $log = new Entity\Log();
        $log->setLevel($level);
        $log->setSchool($school);
        $log->setLoggedOn(new \Datetime());
        $log->setUser($this->user);
        $log->setJsonData($data);

        $this->em->persist($log);

        return TRUE;
    }

    public function pushToElastic() {
        try {
            $documents = $this->retrieveExistingDocuments();
        } catch (NoNodesAvailableException $e) {
            $this->dumpingService->error("Elastic server not responding: " . $e->getMessage());
            return;
        }
        $schools = $this->em->createQuery(
            "SELECT s
            FROM AppBundle:School s
            WHERE s.isValid = 1"
        )->getResult();

        $total = 0;
        $successful = 0;
        $inserted = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($schools as $school) {
            $params = [
                'index' => $this->elasticIndex,
                'type' => $this->elasticType
            ];

            $response = NULL;
            if (array_key_exists($school->getCode(), $documents)) {
                $params['id'] = $documents[$school->getCode()];
                $updated++;
                unset($documents[$school->getCode()]); // remove the array item, so we have the non-existing remaining only
            } else {
                $inserted++;
            }

            $params['body'] = $school->getLastBuildJsonData();
            $response = $this->elastic->index($params);

            if (isset($response['_id'])) {
                $successful++;
            }

            $total++;
        }

        foreach ($documents as $redizo => $id) {
            $params = [
                'index' => $this->elasticIndex,
                'type' => $this->elasticType
            ];
            $params['id'] = $id;
            $this->elastic->delete($params);
            $deleted++;
        }

        $this->dumpingService->dump("successful: $successful/$total");
        $this->dumpingService->dump("updated: $updated");
        $this->dumpingService->dump("inserted: $inserted");
        $this->dumpingService->dump("deleted: $deleted");
    }

    public function retrieveExistingDocuments() {
        $documents = [];

        $params = [
            "scroll" => "10s",          // how long between scroll requests. should be small!
            "size" => 100,               // how many results *per shard* you want back
            "index" => $this->elasticIndex,
            "type" => $this->elasticType,
            "body" => [
                "query" => [
                    "match_all" => new \stdClass()
                ]
            ]
        ];

        $docs = $this->elastic->search($params);   // Execute the search
        $scroll_id = $docs['_scroll_id'];   // The response will contain no results, just a _scroll_id

        // Now we loop until the scroll "cursors" are exhausted
        while (TRUE) {

            // Execute a Scroll request
            $response = $this->elastic->scroll([
                    "scroll_id" => $scroll_id,  //...using our previously obtained _scroll_id
                    "scroll" => "30s"           // and the same timeout window
                ]
            );

            // Check to see if we got any search hits from the scroll
            if (count($response['hits']['hits']) > 0) {

                foreach ($response['hits']['hits'] as $hit) {
                    if (isset($hit['_source']['RED-IZO'])) {
                        $documents[$hit['_source']['RED-IZO']] = $hit['_id'];
                    }
                }

                // Get new scroll_id
                // Must always refresh your _scroll_id!  It can change sometimes
                $scroll_id = $response['_scroll_id'];
            } else {
                // No results, scroll cursor is empty.  You've exported all the data
                break;
            }
        }

        return $documents;
    }

    /**
     * Merge two objects recursively with respect to the school schema.
     * @param $original object
     * @param $new object
     * @return object
     */
    public function mergeObjects($original, $new) {
        foreach ($new as $key => $value) {
            if (isset($original->$key)) {
                if (is_object($value)) {
                    $original->$key = $this->mergeObjects($original->$key, $value);
                } else if (is_array($value)) {
                    $original->$key = $this->mergeArrays($original->$key, $value, $key);
                } else {
                    $original->$key = $value;
                }
            } else {
                $original->$key = $value;
            }
        }

        return $original;
    }

    /**
     * Merge two arrays according to the given key.
     * @param $original array
     * @param $new array
     * @param $key string
     * @return array
     */
    public function mergeArrays($original, $new, $key) {
        switch ($key) {
            case 'units':
                return $this->mergeArraysByField($original, $new, 'IZO');
            case 'sections':
                return $this->mergeArraysByField($original, $new, 'title');
            case 'information':
                return $this->mergeArraysByField($original, $new, 'key', TRUE);
            default:
                return array_merge($original, $new);
        }
    }

    /**
     * Merge two arrays that has an identical field value.
     * @param $original array
     * @param $new array
     * @param $field string
     * @param $override bool
     * @return array
     */
    public function mergeArraysByField($original, $new, $field, $override = FALSE) {
        foreach ($new as $key => $value) {
            $found = FALSE;
            foreach ($original as $k => $v) {
                if ($value->$field == $v->$field) {
                    if ($override) {
                        $original[$k] = $value;
                    } else {
                        $original[$k] = $this->mergeObjects($original[$k], $value);
                    }
                    $found = TRUE;
                }
            }

            if (!$found) {
                $original[] = $value;
            }
        }

        return $original;
    }

    /**
     * Make the JSON document valid against the school JSON schema. Returns FALSE, if document cannot be fixed.
     * Sometimes, required fields might be filled with empty values or the parent node might be removed.
     * @param document
     * @return object|FALSE
     */
    public function validateDocument($document) {
        $this->currentSchool = "";
        if (!isset($document->{'RED-IZO'}) || !$document->{'RED-IZO'} || !isset($document->metadata)) {
            $this->report("Unset RED-IZO or missing metadata in main document.");
            return FALSE;
        }
        $this->currentSchool = $document->{'RED-IZO'};

        $this->removeRedundant($document, ["RED-IZO", "metadata", "units"]);

        if (!$this->validateMetadata($document->metadata)) {
            return FALSE;
        }

        if (isset($document->units)) {
            foreach ($document->units as $key => $unit) {
                // validate each unit

                if (!isset($unit->IZO) || !isset($unit->metadata) || !isset($unit->unitType)) {
                    unset($document->units[$key]);
                    continue;
                } else {
                    $this->removeRedundant($unit, ["IZO", "unitType", "metadata", "sections"]);
                }

                if (!$this->validateUnitMetadata($unit->metadata)) {
                    return FALSE;
                }

                if (isset($unit->sections)) {
                    foreach ($unit->sections as $key2 => $section) {
                        if (!isset($section->title) || !isset($section->information)) {
                            unset($unit->sections[$key2]);
                            continue;
                        }

                        $this->removeRedundant($section, ["title", "information"]);
                    }
                    $unit->sections = array_values($unit->sections);
                }
            }
            $document->units = array_values($document->units);
        }

        return TRUE;
    }

    public function validateMetadata($metadata) {
        // required fields
        if (!isset($metadata->name) || !isset($metadata->address) || !is_object($metadata->address)) {
            $this->report("Missing attribute name or address in school metadata.");
            return FALSE;
        }
        $this->removeRedundant($metadata, ["name", "headmaster", "founder", "contact", "address"]);

        // validate address
        $address = $metadata->address;
        if (!isset($address->street) || !isset($address->city) || !isset($address->postalCode) ||
            !isset($address->location) || !is_object($address->location)) {
            $this->report("Invalid address of a school, missing street, city, postalCode or location.");
            return FALSE;
        }
        $this->removeRedundant($address, ["street", "city", "postalCode", "ruianCode", "location", "googleMapsId"]);

        // validate location
        $location = $address->location;
        if (!isset($location->lon) || !isset($location->lat)) {
            $this->report("Invalid location of a school, missing lon or lat.");
            return FALSE;
        }
        $this->removeRedundant($metadata->address->location, ["lat", "lon"]);

        // validate contact
        $this->validateContact($metadata);
        return TRUE;
    }

    public function validateUnitMetadata($metadata) {
        $this->removeRedundant($metadata, ["name", "ICO", "contact", "address"]);

        // validate address
        //if (isset($metadata->address) && is_object($metadata->address)) {
        //    $this->removeRedundant($metadata->address, ["street", "city", "postalCode", "ruianCode"]);
        //}
        if (isset($metadata->address)) {
            $address = $metadata->address;
            if (!isset($address->street) || !isset($address->city) || !isset($address->postalCode) ||
                !isset($address->location) || !is_object($address->location)) {
                $this->report("Invalid address of a unit, missing street, city, postalCode or location.");
                return FALSE;
            }
        }

        $this->validateContact($metadata);

        return TRUE;
    }


    public function validateContact($metadata) {
        if (isset($metadata->contact) && is_object($metadata->contact)) {
            $this->validateArray($metadata->contact, 'phoneNumbers');
            $this->validateArray($metadata->contact, 'emails');
            $this->validateArray($metadata->contact, 'websites');

            $this->removeRedundant($metadata->contact, ["emails", "websites"]);
        }
    }

    public function validateArray($object, $field) {
        if (isset($object->$field)) {
            if (!is_array($object->$field)) {
                if (is_scalar($object->$field)) {
                    $object->$field = [ $object->$field ];
                } else {
                    unset($object->$field);
                }
            }
        }
    }

    public function removeRedundant($object, $allowedProperties) {
        foreach ($object as $key => $value) {
            if (!in_array($key, $allowedProperties)) {
                unset($object->$key);
            }
        }
    }

    public function report($message) {
        if ($this->reportEnabled) {
            $this->dumpingService->error("School $this->currentSchool invalid: $message");
        }
    }

    private function areAddressesEqual($first, $second) {
        return $first->street == $second->street &&
            $first->city == $second->city &&
            $first->postalCode == $second->postalCode;
    }
}