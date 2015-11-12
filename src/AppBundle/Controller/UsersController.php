<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;

class UsersController extends Controller
{
    /**
     * @Route("/users", name="users")
     */
    public function indexAction(Request $request)
    {
        $source = new Entity('AppBundle:User');
        $grid = $this->get('grid');
        $grid->setSource($source);
        $grid->setLimits([20, 50, 100]);

        // actions
        $rowAction = new RowAction("edit", 'users_edit', FALSE, '_self', [ 'class' => "btn btn-primary btn-sm" ], 'ROLE_ADMIN');
        $grid->addRowAction($rowAction);

        return $grid->getGridResponse('users/usersGrid.html.twig');
    }

    /**
     * @Route("/users/edit/{id}", name="users_edit")
     */
    public function editAction(Request $request, $id)
    {

        $user = $this->getDoctrine()
            ->getRepository('AppBundle:User')
            ->find($id);

        $form = $this->createFormBuilder($user)
            ->add('username', 'text', [ 'label' => 'Uživatelské jméno' ])
            ->add('email', 'text', [ 'label' => 'E-mail' ])
            ->add('password', 'password', [ 'required' => FALSE, 'label' => 'Heslo' ])
            ->add('save', 'submit', [ 'label' => 'Uložit' ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->addFlash(
                'success',
                'Your changes were saved!'
            );
            return $this->redirectToRoute('users');
        }

        return $this->render('users/edit.html.twig', array(
            'form' => $form->createView(),
        ));
    }
}
