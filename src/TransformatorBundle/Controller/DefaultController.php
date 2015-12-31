<?php

namespace TransformatorBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class DefaultController extends Controller implements TokenAuthenticatedController
{

    /**
     * @Route("/transformator/csi", name="csi")
     */
    public function csiAction(Request $request)
    {
        ini_set('max_execution_time', 60 * 60 * 10); // 10 hours :-P
        $operations = $this->get('transformator.utils.file_operations');

        $operations->processCSI();

        return new Response("DONE");
    }

    /**
     * @Route("/transformator/msmt", name="msmt")
     */
    public function msmtAction(Request $request)
    {
        ini_set('max_execution_time', 60 * 60 * 10); // 10 hours :-P
        ini_set('memory_limit', '1G');
        $operations = $this->get('transformator.utils.file_operations');

        $operations->processMSMT();

        return new Response("DONE");
    }

     /**
     * @Route("/build/buildAll", name="build")
     */
    public function buildAction(Request $request)
    {
        ini_set('max_execution_time', 60 * 60 * 10); // 10 hours :-P
        ini_set('memory_limit', '1G');
        $build = $this->get('transformator.utils.build');

        $build->build();

        return new Response("DONE");
    }
}