<?php

namespace TransformatorBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class DefaultController extends Controller implements TokenAuthenticatedController
{

    private function setUnlimitedResources()
    {
        ini_set('max_execution_time', 60 * 60 * 10); // 10 hours :-P
        ini_set('memory_limit', '1G');
    }

    private function completed()
    {
        //return new Response("DONE");
        return $this->redirectToRoute('scripts');
    }

    /**
     * @Route("/transformator/csi", name="csi")
     */
    public function csiAction(Request $request)
    {
        $this->setUnlimitedResources();
        $operations = $this->get('transformator.utils.csi_parser');

        $operations->processCSI();

        return $this->completed();
    }

    /**
     * @Route("/transformator/msmt", name="msmt")
     */
    public function msmtAction(Request $request)
    {
        $this->setUnlimitedResources();
        $operations = $this->get('transformator.utils.msmt_parser');

        $operations->processMSMT();

        return $this->completed();
    }

    /**
     * @Route("/build/buildAll", name="buildAll")
     */
    public function buildAllAction(Request $request)
    {
        $this->setUnlimitedResources();
        $build = $this->get('transformator.utils.build');

        $build->build();

        return $this->completed();
    }

    /**
     * @Route("/build/build/{limit}/{offset}", name="build")
     */
    public function buildAction($limit, $offset) {
        $this->setUnlimitedResources();
        $build = $this->get('transformator.utils.build');

        $build->build($limit, $offset);

        return $this->completed();
    }

    /**
     * @Route("/build/pushToElastic", name="pushToElastic")
     */
    public function pushToElasticAction(Request $request)
    {
        $this->setUnlimitedResources();
        $build = $this->get('transformator.utils.build');

        $build->pushToElastic();

        return $this->completed();
    }


    /**
     * @Route("/build/cacheSchoolLocation/{limit}/{offset}", name="cacheSchoolLocation", defaults={"limit": NULL, "offset": NULL})
     */
    public function cacheSchoolLocation($limit, $offset)
    {
        $this->setUnlimitedResources();
        $build = $this->get('transformator.utils.build');

        $build->cacheSchoolLocation($limit, $offset);

        return $this->completed();
    }
}