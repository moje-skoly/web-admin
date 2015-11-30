<?php

namespace TransformatorBundle\EventListener;

use TransformatorBundle\Controller\TokenAuthenticatedController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

class TokenListener
{
    private $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function onKernelController(FilterControllerEvent $event)
    {
        $controller = $event->getController();

        if (!is_array($controller)) {
            return;
        }

        if ($controller[0] instanceof TokenAuthenticatedController) {
            $token = $event->getRequest()->query->get('token');

            if ($token != $this->token) {
                throw new AccessDeniedHttpException('This action needs a valid token!');
            }
        }
    }
}