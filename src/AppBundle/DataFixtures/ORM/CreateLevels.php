<?php
namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Entity\Level;

class LoadUserData implements FixtureInterface
{
    /**
     * {@inheritDoc}
     */
    public function load(ObjectManager $manager)
    {
        $levels = [
            [ 'priority' => 1, 'name' => "Uživatel" ],
            [ 'priority' => 2, 'name' => "Škola" ],
            [ 'priority' => 3, 'name' => "ČŠI" ],
            [ 'priority' => 4, 'name' => "MŠMT" ],
            [ 'priority' => 5, 'name' => "Zřizovatel" ]
        ];

        foreach ($levels as $levelData) {
            $level = new Level();
            $level->setPriority($levelData['priority']);
            $level->setName($levelData['name']);

            $manager->persist($level);
        }
        $manager->flush();
    }
}