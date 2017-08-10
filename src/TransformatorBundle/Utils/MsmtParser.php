<?php

namespace TransformatorBundle\Utils;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class MsmtParser extends FileOperations {
    const MSMT_ENCODING = "windows-1250";
    const MSMT_REDIZO = "openred2.csv";
    const MSMT_ACTIVITY = "opensouc2.csv";

    private $msmtUrl;

    public function __construct(TokenStorage $tokenStorage, EntityManager $entityManager, $msmtUrl) {
        parent::__construct($tokenStorage, $entityManager);
        $this->msmtUrl = $msmtUrl;
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

    protected function createMsmtSchoolJson($row, $header, $activities) {
        $school = new \stdClass();

        $school->{'RED-IZO'} = $redizo = $this->getCsvColumn($row, $header, 'RED_IZO');

        $metadata = new \stdClass();

        $metadata->ICO = $this->getCsvColumn($row, $header, 'RED_ICO');
        $metadata->name = $this->getCsvColumn($row, $header, 'REDNAZEV');
        $metadata->founder = $this->msmtFounderTable[$this->getCsvColumn($row, $header, 'ZRIZ_KOD')];

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
}