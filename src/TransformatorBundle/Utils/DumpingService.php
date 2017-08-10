<?php

namespace TransformatorBundle\Utils;

use Symfony\Component\HttpFoundation\Session\Session;

class DumpingService {
    /** @var Session */
    private $session;

    public function __construct(Session $session) {
        $this->session = $session;
    }

    private function toDumpString($message) {
        if (is_scalar($message)) {
            return $message;
        } else {
            return print_r($message, TRUE);
        }
    }

    public function dump($message) {
        $this->session->getFlashBag()->add("info", $this->toDumpString($message));
    }

    public function error($message) {
        $this->session->getFlashBag()->add("danger", $this->toDumpString($message));
    }
}