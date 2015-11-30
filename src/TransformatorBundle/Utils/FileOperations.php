<?php

namespace TransformatorBundle\Utils;

use League\Csv\Reader;

class FileOperations {
    const EMPTY_VALUE = "není";

	private $msmtUrl;

	public function __construct($msmtUrl) {
        mb_internal_encoding("UTF-8");

        $this->msmtUrl = $msmtUrl;
        $this->tempDir = __DIR__ . "/../../../app/temp";

        stream_filter_register(FilterTranscode::FILTER_NAME."*", "\TransformatorBundle\Utils\FilterTranscode");
	}

	public function processMSMT() {
        $destination = $this->tempDir;
        $filePath = $destination . "/msmt.zip";

        /*
        // download the zip file
		$data = $this->getDataFromUrl($this->msmtUrl);

        // save it to temp dir
        $this->storeDataToFile($data, $filePath);

        // unzip the csv files
        $this->unzipFileTo($filePath, $destination);
        */

        // open the general file
        $this->processMsmtCsv();
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

    private function processMsmtCsv() {
        $activities = $this->loadActivities();
        $entities = $this->openCsv($this->tempDir . '/sitred2.csv');

        $header = array_flip($entities->fetchOne(0));
        $first = TRUE;
        foreach ($entities->fetch() as $row) {
            if ($first) {
                $first = FALSE;
                continue;
            }
            $json = $this->createMsmtSchoolJson($row, $header, $activities);
            dump($json);
            break;
        }
    }

    private function createMsmtSchoolJson($row, $header, $activities) {
        $school = new \stdClass();

        $school->{'RED-IZO'} = $redizo = $this->getCsvColumn($row, $header, 'RED_IZO');

        $metadata = new \stdClass();

        $metadata->ICO = $this->getCsvColumn($row, $header, 'RED_ICO');
        $metadata->name = $this->getCsvColumn($row, $header, 'REDNAZEV');
        $metadata->founder = $this->getCsvColumn($row, $header, 'ZRIZ_KOD');

        // address
        $address = new \stdClass();
        $address->street = $this->createStreetName($this->getCsvColumn($row, $header, 'RED_ULICE'),
                                                   $this->getCsvColumn($row, $header, 'RED_CP'));
        $address->city = $this->getCsvColumn($row, $header, 'RED_MISTO');
        $address->postalCode = $this->getCsvColumn($row, $header, 'RED_PSC');
        $address->ruianCode = $this->getCsvColumn($row, $header, 'ruian_kod');

        $metadata->address = $address;

        // contacts
        $contacts = new \stdClass();
        $phone = $this->getCsvColumn($row, $header, 'TELEFON');
        if (!empty($phone))
            $contacts->phoneNumbers = [$phone];

        $fax = $this->getCsvColumn($row, $header, 'FAX');
        if (!empty($fax))
            $contacts->fax = $fax;
        
        $email1 = $this->getCsvColumn($row, $header, 'EMAIL1');
        $email2 = $this->getCsvColumn($row, $header, 'EMAIL2');
        if (!empty($email1)) {
            $contacts->emails = [$email1];
            if (!empty($email2))
                $contacts->emails[] = $email2;
        }

        $web = $this->getCsvColumn($row, $header, 'WWW');
        if (!empty($web))
            $contacts->website = $web;

        $metadata->contacts = $contacts;

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
        $reader = $this->openCsv($this->tempDir . '/rejmisto.csv');

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
            $activity->type = $this->getCsvColumn($row, $header, 'ZAR_NAZ');

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
            $contacts = new \stdClass();
            $phone = $this->getCsvColumn($row, $header, 'TELEFON');
            if (!empty($phone))
                $contacts->phoneNumbers = [$phone];

            $metadata->contacts = $contacts;

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
        if (mb_ereg_match('[0-9a-z]+', $number))
            $name = $name . (mb_ereg_match('.*[0-9]+[a-z]?$', $name) ? "/" : " ") . $number;
        return $name;
    }

    private function openCsv($path) {
        $reader = Reader::createFromPath($path);

        if ($reader->isActiveStreamFilter()) {
            $reader->appendStreamFilter(FilterTranscode::FILTER_NAME."windows-1250:utf-8");
        }

        $reader->setDelimiter(';');
        return $reader;
    }

    private function getCsvColumn($row, $header, $name) {
        $val = trim(preg_replace('/\s\s+/', ' ', $row[$header[$name]]));
        return $val == self::EMPTY_VALUE ? "" : $val;
    }

    private function sanitizeUrl($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        return $url;
    }
}