<?php

namespace AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('roles', 'choice', [
            'choices'  => [
                'ROLE_USER'     => 'School',
                'ROLE_EDITOR'   => 'Editor',
                'ROLE_ADMIN'    => 'Admin',
            ],
            'multiple' => TRUE,
            'expanded' => TRUE
        ]);
    }

    public function getParent()
    {
        return 'fos_user_registration';
    }

    public function getName()
    {
        return 'app_user_registration';
    }
}