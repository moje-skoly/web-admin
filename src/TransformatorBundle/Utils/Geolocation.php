<?php

namespace TransformatorBundle\Utils;

use Ci\RestClientBundle\Exceptions\OperationTimedOutException;
use Ci\RestClientBundle\Services\RestClient;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

class Geolocation {

    /** @var RestClient */
    private $restClient;

    /** @var DumpingService */
    private $dumpingService;

    /** @var string */
    private $googleMapsKey;

    /** @var int */
    private $lastTime = 0;

    public function __construct(RestClient $restClient, DumpingService $dumpingService, $googleMapsKey) {
        mb_internal_encoding("UTF-8");

        $this->restClient = $restClient;
        $this->dumpingService = $dumpingService;
        $this->googleMapsKey = $googleMapsKey;
    }

    /**
     * Retrieve GPS location from different services.
     * @param $address object Address object
     * @param $vars array
     * @return object
     */
    public function retrieveLocation($address, $vars = []) {
        $location = $this->retrieveNominatimLocation($address);
        if ($location) {
            $vars['nominatim1']++;
        } else {
            $location = $this->retrieveNominatimLocation($address, TRUE);

            if ($location) {
                $vars['nominatim2']++;
            } else {
                $location = $this->retrieveGoogleMapsLocation($address);

                if ($location) {
                    $vars['google']++;
                } else {
                    $this->dumpingService->error("Address not found:");
                    $this->dumpingService->error($address);
                    $vars['none']++;
                }
            }
        }

        return $location;
    }

    /**
     * Retrieve the precise location of given address from openstreetmap.org
     * @param $address object Address object
     * @param $omitCity bool Omit city in the url
     * @return object Location object
     */
    public function retrieveNominatimLocation($address, $omitCity = FALSE) {
        $url = $this->createNominatimUrl($address, $omitCity);

        try {
            // sleep to prevent overrunning the one request-per-second limit
            $time = microtime(TRUE);
            if ($time - $this->lastTime < 1.0) {
                $time = 1.0 - ($time - $this->lastTime);
                usleep(intval($time * 1000000));
            }
            $this->lastTime = $time;
            $response = $this->restClient->get($url);
            $json = $response->getContent();
        } catch (OperationTimedOutException $exception) {
            $this->dumpingService->error("Couldn't retrieve location information at openstreetmap.org, server not responding.");
            return NULL;
        }

        $location = NULL;
        try {
            $location = Json::decode($json);
            if (empty($location) || !isset($location[0]->lat) || !isset($location[0]->lon)) {
                $location = NULL;
            } else {
                $location = $location[0];
            }
        } catch (JsonException $e) {
            $this->dumpingService->error($e->getMessage());
            $this->dumpingService->error($json);
        }

        return $location;
    }

    /**
     * Retrieve the precise location of given address from Google Maps API
     * @param $address object Address object
     * @return object Location object
     */
    public function retrieveGoogleMapsLocation($address) {
        $url = $this->createGoogleMapsUrl($address);

        try {
            $response = $this->restClient->get($url);
            $json = $response->getContent();
        } catch (OperationTimedOutException $exception) {
            $this->dumpingService->error("Couldn't retrieve location information at Google Maps, server not responding.");
            return NULL;
        }

        $location = NULL;
        try {
            $location = Json::decode($json);
            if (!empty($location) && $location->status == "OK") {
                $location = $location->results[0]->geometry->location;
                $location->lon = $location->lng;
            } else {
                $this->dumpingService->error($address);
                if ($location) {
                    $this->dumpingService->error("status: " . $location->status);
                }
                $location = NULL;
            }
        } catch (JsonException $e) {
            $this->dumpingService->error($e->getMessage());
            $this->dumpingService->error($json);
        }

        return $location;
    }


    /**
     * Create the URL address for the Nominatim API from the given address.
     * @param $address object Address object
     * @param $omitCity bool Omit city in the URL
     * @return string
     */
    public function createNominatimUrl($address, $omitCity = FALSE) {
        $url = 'http://nominatim.openstreetmap.org/search?format=json&countrycodes=CZ&q=';
        $url .= $this->getAddressQuery($address, $omitCity);
        return $url;
    }

    /**
     * Create the URL address for the Google Maps API from the given address.
     * @param $address object Address object
     * @return string
     */
    public function createGoogleMapsUrl($address) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=';
        $url .= $this->getAddressQuery($address);
        $url .= '&key=' . $this->googleMapsKey;
        return $url;
    }

    private function getAddressQuery($address, $omitCity = FALSE) {
        $components = [];
        if (!empty($address->street)) {
            $components[] = $address->street;
        }
        if (!empty($address->city) && !$omitCity) {
            $components[] = $address->city;
        }
        if (!empty($address->postalCode)) {
            $components[] = $address->postalCode;
        }
        return urlencode(implode(', ', $components) . ", Czech Republic");
    }
}