<?php

namespace TransformatorBundle\Utils;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Elasticsearch\ClientBuilder;

use AppBundle\Entity;

class Build {

    private $restClient;

	private $msmtUrl;
    private $user;
    private $schoolRepository;
    private $elastic;
    private $elasticIndex;
    private $elasticType;

    private $currentSchool;
    private $reportEnabled = TRUE;
    private $locationLevel = NULL;

	public function __construct(TokenStorage $tokenStorage, $restClient, $entityManager, $elasticAddress, $elasticIndex, $elasticType) {
        mb_internal_encoding("UTF-8");

        $this->restClient = $restClient;

        // testing mode
        if ($tokenStorage->getToken() === NULL)
            return;

        $this->em = $entityManager;

        $this->user = $this->em->getRepository('AppBundle:User')->find($tokenStorage->getToken()->getUser()->getId());
        $this->schoolRepository = $this->em->getRepository('AppBundle:School');
        $this->levelRepository = $this->em->getRepository('AppBundle:Level');
        $this->logRepository = $this->em->getRepository('AppBundle:Log');

        // configure elastic connection
        $this->elastic = ClientBuilder::create()
        ->setHosts([
            "http://134.168.35.125:9200/"
        ])
        ->build();

        $this->elasticIndex = $elasticIndex;
        $this->elasticType = $elasticType;
	}

    /**
     * Do the build - loop through all the schools:
     * - loop through the last versions of the school data from each data provider
     * - merge the results according to priority
     */
    public function build() {
        $schools = $this->em->createQuery("SELECT s FROM AppBundle:School s")->iterate();
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

            $school->setLastBuildJsonData(Json::encode($schoolJson));
            $school->setIsValid($isValid);
            if ($isValid) {
                $successful++;
            }

            if ($total % 100 == 0) {
                $this->em->flush();
                $this->em->clear();
            }

            // dump(Json::encode($schoolJson, Json::PRETTY));

            $total++;
        }
        $this->em->flush();

        dump("successful: $successful/$total");
    }

    public function cacheSchoolLocation() {

        $total = 0;
        $successful = 0;

        $schools = $this->em->createQuery(
            "SELECT s
            FROM AppBundle:School s
            WHERE s.isValid = 0"
        )->iterate();

        foreach ($schools as $row) {
            $school = $row[0];

            $schoolJson = Json::decode($school->getLastBuildJsonData());
            if (isset($schoolJson->metadata->address) && !isset($schoolJson->metadata->address->location)) {
                $url = $this->createNominatimUrl($schoolJson->metadata->address);
                //dump($url);
                //continue;
                $json = "";
                try {
                    $response = $this->restClient->get($url);
                    $json = $response->getContent();
                } catch (Ci\RestClientBundle\Exceptions\OperationTimedOutException $exception) {
                    dump("Couldn't retrieve location information, server not responding.");
                    continue;
                }

                try {
                    $location = Json::decode($json);
                } catch (JsonException $e) {
                    dump($e->getMessage());
                    dump($json);
                    break;
                }
                if (is_array($location) && count($location) > 0) {
                    if (isset($location[0]->lat) && isset($location[0]->lon)) {
                        $this->logSchoolLocation($school, $location[0]->lat, $location[0]->lon);
                        $successful++;$this->em->flush();
                    }
                }

                if (($total + 1) % 100 == 0) {
                    $this->em->flush();
                }

                usleep(600000); // sleep for one second
            }

            $total++;
        }

        $this->em->flush();

        dump("successful: $successful/$total");
    }

    public function createNominatimUrl($address) {
        $url = 'http://nominatim.openstreetmap.org/search?format=json&countrycodes=CZ';
        if (!empty($address->street)) {
            $url .= "&street=" . urlencode($address->street);
        } else {
            $url .= "&city=" . urlencode($address->city);
        }

        $url .= "&postalcode=" . urlencode($address->postalCode);
        return $url;
    }

    public function logSchoolLocation($school, $lat, $lon) {
        $json = new \stdClass;
        $json->metadata = new \stdClass;
        $json->metadata->address = new \stdClass;
        $json->metadata->address->location = new \stdClass;
        $json->metadata->address->location->lat = $lat;
        $json->metadata->address->location->lon = $lon;

        $data = NULL;
        try {
            $data = Json::encode($json);
        } catch (JsonException $e) {
            dump("2");
            dump($json);
            dump($e->getMessage());
            return;
        }

        if ($this->locationLevel === NULL) {
            $level = $this->levelRepository->findOneByName('Auto:location');
        }

        $log = new Entity\Log();
        $log->setLevel($level);
        $log->setSchool($school);
        $log->setLoggedOn(new \Datetime());
        $log->setUser($this->user);
        $log->setJsonData($data);

        $this->em->persist($log);
    }

    public function pushToElastic() {
        $documents = $this->retrieveExistingDocuments();
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

        $deleted = 0;
        foreach ($documents as $redizo => $document) {
            $params = [
                'index' => $this->elasticIndex,
                'type' => $this->elasticType
            ];
            $params['id'] = $id;
            $response = $this->elastic->delete($params);
            $deleted++;
        }

        dump("successful: $successful/$total");
        dump("updated: $updated");
        dump("inserted: $inserted");
        dump("deleted: $deleted");
    }

    public function retrieveExistingDocuments() {
        $documents = [];

        $params = [
            "search_type" => "scan",    // use search_type=scan
            "scroll" => "10s",          // how long between scroll requests. should be small!
            "size" => 100,               // how many results *per shard* you want back
            "index" => $this->elasticIndex,
            "type" => $this->elasticType,
            "body" => [
                "query" => [
                    "match_all" => []
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
     * @param object original
     * @param object new
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
     * @param object original
     * @param object new
     * @param string field
     * @return object
     */
    public function mergeArraysByField($original, $new, $field, $override = FALSE) {
        $found = FALSE;
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

                $this->validateUnitMetadata($unit->metadata);

                if (isset($unit->sections)) {
                    foreach ($unit->sections as $key => $section) {
                        if (!isset($section->title) || !isset($section->information)) {
                            unset($unit->sections[$key]);
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
        if (isset($metadata->address) && is_object($metadata->address)) {
            $this->removeRedundant($metadata->address, ["street", "city", "postalCode", "ruianCode"]);
        }

        $this->validateContact($metadata);
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
            dump("School $this->currentSchool invalid: $message");
        }
    }
}