<?php

namespace TransformatorBundle\Utils;

use AppBundle\Entity\User;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

use League\Csv\Reader;
use AppBundle\Entity;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

class FileOperations {
    const EMPTY_VALUE = "není";

    /** @var EntityManager */
    protected $em;

    /** @var User */
    protected $user;

    /** @var DumpingService */
    protected $dumpingService;

    /** @var EntityRepository */
    protected $userRepository;

    /** @var EntityRepository */
    protected $schoolRepository;

    protected $types = [
        'Mateřská škola' => 'materska_skola',
        'Základní škola' => 'zakladni_skola',
        'Střední škola' => 'stredni_skola'
    ];

    protected $msmtFounderTable = [
        1 => "Státní správa (MŠMT)",
        2 => "Obec",
        3 => "Státní správa mimo MŠMT",
        5 => "Privátní sektor",
        6 => "Církev",
        7 => "Kraj"
    ];

    protected $csiFounderTable;

	public function __construct(TokenStorage $tokenStorage, EntityManager $entityManager, DumpingService $dumpingService) {
        mb_internal_encoding("UTF-8");

        $this->em = $entityManager;
        $this->dumpingService = $dumpingService;
        $this->tempDir = __DIR__ . "/../../../../data-sources";

        stream_filter_register(FilterTranscode::FILTER_NAME."*", "\\TransformatorBundle\\Utils\\FilterTranscode");

        $this->userId = $tokenStorage->getToken()->getUser()->getId();
        $this->userRepository = $this->em->getRepository('AppBundle:User');
        $this->user = $this->userRepository->find($this->userId);
        $this->schoolRepository = $this->em->getRepository('AppBundle:School');

        $this->csiFounderTable = [
            "a" => $this->msmtFounderTable[1],
            "b" => $this->msmtFounderTable[2],
            "c" => $this->msmtFounderTable[3],
            "d" => $this->msmtFounderTable[5],
            "e" => $this->msmtFounderTable[6],
            "f" => $this->msmtFounderTable[7],
        ];
	}

    protected function normalizeUnits($school) {
        $school->units = array_values($school->units);
        foreach ($school->units as $i => $unit) {
            if (isset($school->units[$i]->sections)) {
                $school->units[$i]->sections = array_values($unit->sections);
            }
        }
    }

    protected function logSchoolJson($json, $level) {
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
            WHERE l.school = :school_code AND l.level = :level_id
            ORDER BY l.loggedOn DESC"
        )->setParameter('school_code', $school->getCode())
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

            return TRUE;
        } else {
            return FALSE;
        }
    }

    protected function createStreetName($name, $number) {
        if (mb_ereg_match('[0-9a-z]+', $number) &&       // number is not empty and is +/- alphanumeric (might include letters too)
            !mb_ereg_match('.*[0-9]+[a-z]?$', $name)) {   // street name does not end with number (and possible letter, e.g. Kolbenova 34b)
            $name = $name . " " . $number;
        }
        return $name;
    }


    protected function getDataFromUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec ($ch);
        curl_close ($ch);

        return $data;
    }

    protected function storeDataToFile($data, $path) {
        $file = fopen($path, "w+");
        fputs($file, $data);
        fclose($file);
    }

    protected function unzipFileTo($file, $to) {
        $zip = new \ZipArchive;
        if ($zip->open($file) != "true") {
            throw new \Exception("Couldn't open the zip file.");
        }

        $zip->extractTo($to);
    }

    protected function openCsv($path, $encoding = NULL) {
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

    protected function getCsvColumn($row, $header, $name) {
        // remove multiple spaces
        $val = trim(preg_replace('/\s\s+/', ' ', $row[$header[$name]]));

        // replace different empty value representations by an empty string
        $val = mb_ereg_replace('^(' . self::EMPTY_VALUE . '|NULL)$', '', $val);

        // get rid of possible invalid utf8 characters - which occured actually
        return mb_convert_encoding($val, 'UTF-8', 'UTF-8');
    }

    protected function sanitizeUrl($url) {
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