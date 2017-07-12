<?php

namespace TransformatorBundle\Utils;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
//use Symfony\Component\Filesystem\Filesystem;
//use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

use League\Csv\Reader;
use AppBundle\Entity;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

class FileOperations {
    const EMPTY_VALUE = "není";
    const MSMT_ENCODING = "windows-1250";

    const MSMT_REDIZO = "openred2.csv";
    const MSMT_ACTIVITY = "opensouc2.csv";

    /** @var EntityManager */
	private $em;

    private $msmtUrl;
    private $user;

    /** @var \Doctrine\ORM\EntityRepository */
    private $userRepository;

    /** @var \Doctrine\ORM\EntityRepository */
    private $schoolRepository;

    private $types = [
        'Mateřská škola' => 'materska_skola',
        'Základní škola' => 'zakladni_skola',
        'Střední škola' => 'stredni_skola'
    ];

    private $founderTable = [
        1 => "Státní správa ve školství",
        2 => "Obec",
        3 => "Jiný ústř.orgán st.správy",
        4 => "Privátní sektor",
        5 => "Soukromník",
        6 => "Církev",
        7 => "Kraj"
    ];

	public function __construct(TokenStorage $tokenStorage, $entityManager, $msmtUrl) {
        mb_internal_encoding("UTF-8");

        $this->em = $entityManager;

        $this->msmtUrl = $msmtUrl;
        $this->tempDir = __DIR__ . "/../../../../data-sources";

        stream_filter_register(FilterTranscode::FILTER_NAME."*", "\TransformatorBundle\Utils\FilterTranscode");

        $this->userId = $tokenStorage->getToken()->getUser()->getId();
        $this->userRepository = $this->em->getRepository('AppBundle:User');
        $this->user = $this->userRepository->find($this->userId);
        $this->schoolRepository = $this->em->getRepository('AppBundle:School');
	}

    public function processCSI() {

        // first load the map info - create most school objects and store map info at the right place
        $maps = $this->openCsv($this->tempDir . '/csi/REDIZO_mapy.csv');
        $schools = [];
        $header = [ 0, 1, 2, 3 ];

        foreach ($maps->fetch() as $row) {
            $redizo = $this->getCsvColumn($row, $header, 0);
            $googleId = $this->getCsvColumn($row, $header, 1);
            $latitude = $this->getCsvColumn($row, $header, 2);
            $longitude = $this->getCsvColumn($row, $header, 3);
            // redizo cannot be empty, and either google id or both latitude and longitude must be set
            if (!empty($redizo) && (!empty($googleId) || (!empty($latitude) && !empty($longitude)))) {
                $school = new \stdClass();

                $school->{'RED-IZO'} = $redizo;

                $metadata = new \stdClass();
                $address = new \stdClass();
                if (!empty($latitude)) {
                    $location = new \stdClass();
                    $location->lat = floatval(str_replace(',', '.', $latitude));
                    $location->lon = floatval(str_replace(',', '.', $longitude));
                    $address->location = $location;
                }

                if (!empty($googleId)) {
                    $address->googleMapsId = $googleId;
                }

                $metadata->address = $address;
                $school->metadata = $metadata;

                $schools[$redizo] = $school;
            }
        }

        $questionsCsv = $this->openCsv($this->tempDir . '/csi/OTAZKY_ciselnik.csv');
        $questions = [];
        $segments = [ 0 => "Základní informace" ]; // default fallback segment
        $header = [ 0, 1, 2, 3, 4, 5, 6 ]; // activity id, activity name, segment id, segment name, question id, question text, type

        foreach ($questionsCsv->fetch() as $row) {
            $segmentId = $this->getCsvColumn($row, $header, 2);
            $segmentName = $this->getCsvColumn($row, $header, 3);
            $questionId = $this->getCsvColumn($row, $header, 4);

            if (!array_key_exists($segmentId, $segments)) {
                $segments[$segmentId] = $segmentName;
            }

            if (!array_key_exists($questionId, $questions)) {
                $questions[$questionId] = $segmentId;
            }
        }


        $header = [ 0, 1, 2, 3, 4, 5 ]; // redizo, izo, form id, question id, question, answer
        $answers = $this->openCsv($this->tempDir . '/csi/ODPOVEDI_k_IZO.csv');

        $levelRepository = $this->em->getRepository('AppBundle:Level');
        $level = $levelRepository->findOneByName('ČŠI');

        $redizo = NULL;
        $school = NULL;
        $unit = NULL;
        $i = 0;
        
        // go through all answers
        foreach ($answers->fetch() as $row) {
            $r = $this->getCsvColumn($row, $header, 0);
            if ($r !== $redizo) {
                if ($redizo !== NULL) {
                    // remove the unneccessary IZO keys
                    $this->normalizeUnits($school);

                    // store school and log only if it exists - was included in Ministry of education data
                    if ($this->schoolRepository->findOneByCode($redizo))
                        $this->logSchoolJson($school, $level);

                    // free the memory
                    unset($schools[$redizo]);

                    $i++;
                    if ($i >= 100) {
                        $this->em->flush();

                        // detach all objects from doctrine, which clears the memory
                        $this->em->clear();
                        $level = $levelRepository->findOneByName('ČŠI');
                        $this->user = $this->userRepository->find($this->userId);


                        $i = 0;
                    }//*/
                }

                $redizo = $r;
                if (!array_key_exists($redizo, $schools)) {
                    $schools[$redizo] = new \stdClass();
                    $schools[$redizo]->{'RED-IZO'} = $redizo;
                }

                $school = $schools[$redizo];
                $school->units = [];
            }

            $izo = $this->getCsvColumn($row, $header, 1);
            if (!array_key_exists($izo, $school->units)) {
                $unit = new \stdClass();
                $unit->IZO = $izo;
                $unit->sections = [];
                $school->units[$izo] = $unit;
            }
            $questionId = $this->getCsvColumn($row, $header, 3);
            $segmentId = 0;
            if (array_key_exists($questionId, $questions)) {
                $segmentId = $questions[$questionId];
            }
            if (!array_key_exists($segmentId, $school->units[$izo]->sections)) {
                $section =  new \stdClass();
                $section->title = $segments[$segmentId];
                $section->information = [];
                $unit->sections[$segmentId] = $section;
            }
            $information = new \stdClass();
            $information->key = $this->getCsvColumn($row, $header, 4);
            $information->value = $this->getCsvColumn($row, $header, 5);
            $school->units[$izo]->sections[$segmentId]->information[] = $information;
        }

        $this->normalizeUnits($school);

        if ($this->schoolRepository->findOneByCode($school->{'RED-IZO'}))
            $this->logSchoolJson($school, $level);

        $this->em->flush();
    }

    private function normalizeUnits($school) {
        $school->units = array_values($school->units);
        foreach ($school->units as $i => $unit) {
            $school->units[$i]->sections = array_values($unit->sections);
        }
    }

	public function processMSMT() {
        $destination = $this->tempDir;
        $filePath = $destination . "/msmt.zip";

        /*/ download the zip file
		$data = $this->getDataFromUrl($this->msmtUrl);

        // save it to temp dir
        $this->storeDataToFile($data, $filePath);

        // unzip the csv files
        $this->unzipFileTo($filePath, $destination); //*/

        // open the general file
        $this->processMsmtCsv();

/*
        $answers = $this->openCsv($this->tempDir . '/u.csv');

        $header = range(0, 15); // redizo, izo, form id, question id, question, answer

        $redizo = NULL;
        $i = 0;
        
        // go through all answers
        foreach ($answers->fetch() as $row) {
            foreach ($header as $i) {
                dump($this->getCsvColumn($row, $header, $i));
            }
        }*/
	}

    private function processMsmtCsv() {
        $activities = $this->loadActivities();
        $entities = $this->openCsv($this->tempDir . '/' . self::MSMT_REDIZO, self::MSMT_ENCODING);

        $header = array_flip($entities->fetchOne(0));
        $levelRepository = $this->em->getRepository('AppBundle:Level');
        $level = $levelRepository->findOneByName('MŠMT');

        /*$fs = new Filesystem();
        if (!$fs->exists($this->tempDir . '/json_export')) {
            try {
                $fs->mkdir($this->tempDir . '/json_export');
            } catch (IOExceptionInterface $e) {
                echo "Couldn't create directory at " . $e->getPath();
            }
        }*/

        $i = 0;
        $first = TRUE;
        foreach ($entities->fetch() as $row) {
            if ($first) {
                $first = FALSE;
                continue;
            }

            $json = $this->createMsmtSchoolJson($row, $header, $activities);

            $this->logSchoolJson($json, $level);

            //$this->storeDataToFile($data, $this->tempDir . '/json_export/' . $json->{'RED-IZO'} . '.json');
            /*$i++;
            if ($i >= 100) {
                $this->em->flush();

                // detach all objects from doctrine, which clears the memory
                $this->em->clear();

                // which means we need to retrieve level once again
                $level = $level = $levelRepository->findOneByName('MŠMT');
                $i = 0;
            }//*/
        }

        $this->em->flush();
    }

    private function logSchoolJson($json, $level) {
        $school = $this->schoolRepository->findOneByCode($json->{'RED-IZO'});

        if (!$school || (isset($json->metadata->name) && $school->getName() != $json->metadata->name)) {
            if (!$school) {
                $school = new Entity\School();
                $school->setCode($json->{'RED-IZO'});
            }

            if (isset($json->metadata->name))
                $school->setName($json->metadata->name);
            $school->setIsValid(FALSE);
            $this->em->persist($school);
        }

        $log = $this->em->createQuery(
            "SELECT l
            FROM AppBundle:Log l
            WHERE l.school = :school_id AND l.level = :level_id
            ORDER BY l.loggedOn DESC"
        )->setParameter('school_id', $school->getId())
        ->setParameter('level_id', $level->getId())
        ->setMaxResults(1)
        ->getOneOrNullResult();

        $data = NULL;
        // already some log exists - check if the new is different
        if (!!$log) {
            try {
                $data = Json::encode($json);
            } catch (JsonException $e) {
                dump("1");
                dump($json);
                dump($e->getMessage());
            }

            if (empty($data)) {
                dump("1");
                dump($json); exit;
            }
            if ($data != $log->getJsonData()) {
                $log = NULL; // so it passes next condition
            }
        }

        if (!$log) {
            if (!$data)
                try {
                    $data = Json::encode($json);
                } catch (JsonException $e) {
                    dump("2");
                    dump($json);
                    dump($e->getMessage());
                }
            if (empty($data)) {
                dump("2");
                dump($json); exit;
            }

            $log = new Entity\Log();
            $log->setLevel($level);
            $log->setSchool($school);
            $log->setLoggedOn(new \Datetime());
            $log->setUser($this->user);
            $log->setJsonData($data);

            $this->em->persist($log);
        }
    }

    private function createMsmtSchoolJson($row, $header, $activities) {
        $school = new \stdClass();

        $school->{'RED-IZO'} = $redizo = $this->getCsvColumn($row, $header, 'RED_IZO');

        $metadata = new \stdClass();

        $metadata->ICO = $this->getCsvColumn($row, $header, 'RED_ICO');
        $metadata->name = $this->getCsvColumn($row, $header, 'REDNAZEV');
        $metadata->founder = $this->founderTable[$this->getCsvColumn($row, $header, 'ZRIZ_KOD')];

        // address
        $address = new \stdClass();
        $address->street = $this->createStreetName($this->getCsvColumn($row, $header, 'RED_ULICE'),
                                                   $this->getCsvColumn($row, $header, 'RED_CP'));
        $address->city = $this->getCsvColumn($row, $header, 'RED_MISTO');
        $address->postalCode = $this->getCsvColumn($row, $header, 'RED_PSC');
        $address->ruianCode = $this->getCsvColumn($row, $header, 'ruian_kod');

        $metadata->address = $address;

        // contacts
        $contact = new \stdClass();
        $phone = $this->getCsvColumn($row, $header, 'TELEFON');
        if (!empty($phone))
            $contact->phoneNumbers = [$phone];

        $fax = $this->getCsvColumn($row, $header, 'FAX');
        if (!empty($fax))
            $contact->fax = $fax;
        
        $email1 = $this->getCsvColumn($row, $header, 'EMAIL1');
        $email2 = $this->getCsvColumn($row, $header, 'EMAIL2');
        if (!empty($email1)) {
            $contact->emails = [$email1];
            if (!empty($email2))
                $contact->emails[] = $email2;
        }

        $web = $this->getCsvColumn($row, $header, 'WWW');
        if (!empty($web))
            $contact->websites = [ $web ];

        $metadata->contact = $contact;

        $headmaster = $this->getCsvColumn($row, $header, 'REDITEL');
        if (!empty($headmaster)) {
            $metadata->headmaster = new \stdClass();
            $metadata->headmaster->name = $headmaster;
        }

        $school->metadata = $metadata;

        if (array_key_exists($redizo, $activities)) {
            $school->units = $activities[$redizo];
        }

        return $school;
    }

    private function loadActivities() {
        $reader = $this->openCsv($this->tempDir . '/' . self::MSMT_ACTIVITY, self::MSMT_ENCODING);

        $i = 0;
        $header = array_flip($reader->fetchOne(0));

        $activities = [];
        
        $first = TRUE;
        foreach ($reader->fetch() as $row) {
            if ($first) {
                $first = FALSE;
                continue;
            }

            $redizo = $this->getCsvColumn($row, $header, 'RED_IZO');
            if (!array_key_exists($redizo, $activities)) {
                $activities[$redizo] = [];
            }

            $activity = new \stdClass();
            
            $activity->IZO = $this->getCsvColumn($row, $header, 'IZO');
            $activity->unitType = $this->getType($this->getCsvColumn($row, $header, 'ZAR_NAZ'));

            $metadata = new \stdClass();

            $metadata->ICO = $this->getCsvColumn($row, $header, 'ICO');

            // address
            $address = new \stdClass();
            $address->street = $this->createStreetName($this->getCsvColumn($row, $header, 'ULICE'),
                                                       $this->getCsvColumn($row, $header, 'CP'));
            $address->city = $this->getCsvColumn($row, $header, 'MISTO');
            $address->postalCode = $this->getCsvColumn($row, $header, 'PSC');
            $address->ruianCode = $this->getCsvColumn($row, $header, 'ruian_kod');

            $metadata->address = $address;

            // contacts
            $contact = new \stdClass();
            $phone = $this->getCsvColumn($row, $header, 'TELEFON');
            if (!empty($phone)) {
                $contact->phoneNumbers = [$phone];
                $metadata->contact = $contact;
            }


            $activity->metadata = $metadata;

            $activities[$redizo][] = $activity;

            /*
            $i++;
            if ($i >= 100)
                break;//*/
        }

        return $activities;
    }

    private function createStreetName($name, $number) {
        if (mb_ereg_match('[0-9a-z]+', $number) &&       // number is not empty and is +/- alphanumeric (might include letters too)
            !mb_ereg_match('.*[0-9]+[a-z]?$', $name)) {   // street name does not end with number (and possible letter, e.g. Kolbenova 34b)
            $name = $name . " " . $number;
        }
        return $name;
    }


    private function getDataFromUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec ($ch);
        curl_close ($ch);

        return $data;
    }

    private function storeDataToFile($data, $path) {
        $file = fopen($path, "w+");
        fputs($file, $data);
        fclose($file);
    }

    private function unzipFileTo($file, $to) {
        $zip = new \ZipArchive;
        if ($zip->open($file) != "true") {
            throw new \Exception("Couldn't open the zip file.");
        }

        $zip->extractTo($to);
    }

    private function openCsv($path, $encoding = NULL) {
        $reader = Reader::createFromPath($path);

        if ($reader->isActiveStreamFilter() && $encoding !== NULL) {
            $reader->appendStreamFilter(FilterTranscode::FILTER_NAME."$encoding:utf-8");
        }

        if ($encoding === NULL) {
            $reader->stripBom(TRUE);
        }

        $reader->setDelimiter(';');

        return $reader;
    }

    private function getCsvColumn($row, $header, $name) {
        // remove multiple spaces
        $val = trim(preg_replace('/\s\s+/', ' ', $row[$header[$name]]));

        // replace different empty value representations by an empty string
        $val = mb_ereg_replace('^(' . self::EMPTY_VALUE . '|NULL)$', '', $val);

        // get rid of possible invalid utf8 characters - which occured actually
        return mb_convert_encoding($val, 'UTF-8', 'UTF-8');
    }

    private function sanitizeUrl($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        return $url;
    }

    public function getType($type) {
        if (array_key_exists($type, $this->types)) {
            return $this->types[$type];
        } else {
            return $type;
        }
    }
}