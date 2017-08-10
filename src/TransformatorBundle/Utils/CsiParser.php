<?php

namespace TransformatorBundle\Utils;

class CsiParser extends FileOperations {

    public function processCSI() {
        $schools = $this->loadSchools();
        list($questions, $segments) = $this->loadSegmentsAndQuestions();
        $this->processAnswers($schools, $questions, $segments);
    }

    /**
     * First load the school info - create most school objects and store all info at the right place.
     * @return array
     */
    protected function loadSchools() {
        $activities = $this->loadActivities();

        $maps = $this->openCsv($this->tempDir . '/csi/REDIZO_mapy_rozsireny.csv');
        $schools = [];
        // redizo, googleId, lat, lon, school name, street, city, postal, phone, email, web, headmaster, founder code
        $header = [ 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 ];

        foreach ($maps->fetch() as $row) {
            $redizo =   $this->getCsvColumn($row, $header, 0);
            $latitude = $this->getCsvColumn($row, $header, 2);
            $longitude =$this->getCsvColumn($row, $header, 3);
            $name =     $this->getCsvColumn($row, $header, 4);
            $street =   $this->getCsvColumn($row, $header, 5);
            $city =     $this->getCsvColumn($row, $header, 6);
            $postal =   $this->getCsvColumn($row, $header, 7);
            $phone =    $this->getCsvColumn($row, $header, 8);
            $email =    $this->getCsvColumn($row, $header, 9);
            $web =      $this->getCsvColumn($row, $header, 10);
            $headmaster=$this->getCsvColumn($row, $header, 11);
            $founder =  $this->getCsvColumn($row, $header, 12);

            // redizo cannot be empty
            if (empty($redizo)) {
                continue;
            }
            $school = new \stdClass();
            $school->{'RED-IZO'} = $redizo;

            $metadata = new \stdClass();

            $metadata->name = $name;
            if (!empty($founder) && in_array($founder, array_keys($this->csiFounderTable))) {
                $metadata->founder = $this->csiFounderTable[$founder];
            }

            // address
            $address = new \stdClass();
            $address->street = $street;
            $address->city = $city;
            $address->postalCode = $postal;

            if (!empty($latitude)) {
                $location = new \stdClass();
                $location->lat = floatval(str_replace(',', '.', $latitude));
                $location->lon = floatval(str_replace(',', '.', $longitude));
                $address->location = $location;
            }

            $metadata->address = $address;

            // contacts
            $contact = new \stdClass();
            if (!empty($phone)) $contact->phoneNumbers = [ $phone ];
            if (!empty($email)) $contact->emails = [ $email ];
            if (!empty($web)) $contact->websites = [ $web ];

            $metadata->contact = $contact;

            if (!empty($headmaster)) {
                $metadata->headmaster = new \stdClass();
                $metadata->headmaster->name = $headmaster;
            }

            $school->metadata = $metadata;

            if (isset($activities[$redizo])) {
                $school->units = $activities[$redizo];
            }

            $schools[$redizo] = $school;
        }

        return $schools;
    }

    private function loadActivities() {
        $reader = $this->openCsv($this->tempDir . '/csi/IZO.csv');

        // REDIZO, IZO, name, street, city, postal, phone
        $header = [0, 1, 2, 3, 4, 5, 6 ];

        $activities = [];

        // skip the header line
        $reader->fetch();
        foreach ($reader->fetch() as $row) {
            $redizo = $this->getCsvColumn($row, $header, 0);
            $izo    = $this->getCsvColumn($row, $header, 1);
            $name   = $this->getCsvColumn($row, $header, 2);
            $street = $this->getCsvColumn($row, $header, 3);
            $city   = $this->getCsvColumn($row, $header, 4);
            $postal = $this->getCsvColumn($row, $header, 5);
            $phone  = $this->getCsvColumn($row, $header, 6);

            $activity = new \stdClass();

            $activity->IZO = $izo;
            $activity->unitType = $this->getType($name);

            $metadata = new \stdClass();

            // address
            $address = new \stdClass();
            $address->street = $street;
            $address->city = $city;
            $address->postalCode = $postal;

            $metadata->address = $address;

            // contacts
            $contact = new \stdClass();

            if (!empty($phone)) {
                $contact->phoneNumbers = [ $phone ];
                $metadata->contact = $contact;
            }

            $activity->metadata = $metadata;

            if (!array_key_exists($redizo, $activities)) {
                $activities[$redizo] = [];
            }
            $activities[$redizo][$izo] = $activity;
        }

        return $activities;
    }

    /**
     * Load questions and segments for categorizing data into correct segments
     * @return array
     */
    protected function loadSegmentsAndQuestions() {
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

        return [$questions, $segments];
    }

    /**
     * Process all the answers and store schools to database
     */
    protected function processAnswers($schools, $questions, $segments) {
        $header = [ 0, 1, 2, 3, 4, 5 ]; // redizo, izo, form id, question id, question, answer
        $answers = $this->openCsv($this->tempDir . '/csi/ODPOVEDI_k_IZO.csv');

        $levelRepository = $this->em->getRepository('AppBundle:Level');
        $level = $levelRepository->findOneByName('ČŠI');

        $redizo = NULL;
        $school = NULL;
        $unit = NULL;
        $i = 0;
        $logged = $skipped = 0;

        // 3)
        // Go through all answers, create JSON and persist it

        foreach ($answers->fetch() as $row) {
            $r = $this->getCsvColumn($row, $header, 0);
            if ($r !== $redizo) {
                if ($redizo !== NULL) {
                    // remove the unneccessary IZO keys
                    $this->normalizeUnits($school);

                    // store school and log only if it exists - was included in Ministry of education data
                    if ($this->schoolRepository->findOneByCode($redizo)) {
                        if ($this->logSchoolJson($school, $level)) {
                            $logged++;
                        } else {
                            $skipped++;
                        }
                    }

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
                if (!isset($school->units)) {
                    $school->units = [];
                }
            }

            $izo = $this->getCsvColumn($row, $header, 1);
            if (!array_key_exists($izo, $school->units)) {
                $unit = new \stdClass();
                $unit->IZO = $izo;
                $unit->sections = [];
                $school->units[$izo] = $unit;
            } else if ($unit !== $school->units[$izo]) {
                $unit = $school->units[$izo];
                $unit->sections = [];
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

        if ($this->schoolRepository->findOneByCode($school->{'RED-IZO'})) {
            if ($this->logSchoolJson($school, $level)) {
                $logged++;
            } else {
                $skipped++;
            }
        }

        $this->em->flush();

        $this->dumpingService->dump("Successfully logged $logged new records ($skipped skipped - identical to the old one).");
    }
}