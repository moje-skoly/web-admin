<?php

namespace TransformatorBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class DefaultController extends Controller implements TokenAuthenticatedController
{

    const MSMT_URL = "http://stistko.uiv.cz/rejstrik/rejstrik_151103.zip";

    /**
     * @Route("/transformator/msmt", name="msmt")
     */
    public function msmtAction(Request $request)
    {
        $operations = $this->get('transformator.utils.file_operations');

        $operations->processMSMT();

        return new Response("DONE");
    }
}