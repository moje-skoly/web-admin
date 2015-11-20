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
        $grid->addRowAction(
            new RowAction("edit", 'users_edit', FALSE, '_self', [ 'class' => "btn btn-primary btn-sm" ], 'ROLE_ADMIN')
        );

        $grid->addRowAction(
            new RowAction("delete", 'users_delete', TRUE, '_self', [ 'class' => "btn btn-danger btn-sm" ], 'ROLE_ADMIN')
        );

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
            ->add('plainPassword', 'repeated', [
                'type' => 'password',
                'options' => array('translation_domain' => 'FOSUserBundle'),
                'first_options' => array('label' => 'form.password'),
                'second_options' => array('label' => 'form.password_confirmation'),
                'invalid_message' => 'fos_user.password.mismatch',
                'required' => FALSE
            ])
            ->add('roles', 'choice', [
                'choices'  => [
                    'ROLE_USER'     => 'School',
                    'ROLE_EDITOR'   => 'Editor',
                    'ROLE_ADMIN'    => 'Admin',
                ],
                'multiple' => TRUE,
                'expanded' => TRUE
            ])
            ->add('save', 'submit', [ 'label' => 'Uložit' ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->get('fos_user.user_manager')->updateUser($user);

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

    /**
     * @Route("/users/delete/{id}", name="users_delete")
     */
    public function deleteAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:User')
            ->find($id);
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', "User was successfully deleted.");
        return $this->redirectToRoute('users');
    }
}
