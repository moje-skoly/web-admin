<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="school")
 */
class School
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\Id
     * @ORM\Column(name="code",type="string",length=30)
     */
    protected $code;

    /**
     * @ORM\Column(type="string",length=200)
     */
    protected $name;

    /**
     * @ORM\Column(type="text",name="last_build_json_data", nullable=true)
     */
    protected $lastBuildJsonData;

    /**
     * @ORM\Column(type="boolean",name="is_valid")
     */
    protected $isValid;

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set code
     *
     * @param string $code
     *
     * @return School
     */
    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * Get code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return School
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set lastBuildJsonData
     *
     * @param string $lastBuildJsonData
     *
     * @return School
     */
    public function setLastBuildJsonData($lastBuildJsonData)
    {
        $this->lastBuildJsonData = $lastBuildJsonData;

        return $this;
    }

    /**
     * Get lastBuildJsonData
     *
     * @return string
     */
    public function getLastBuildJsonData()
    {
        return $this->lastBuildJsonData;
    }

    /**
     * Set isValid
     *
     * @param bool $isValid
     *
     * @return School
     */
    public function setIsValid($isValid)
    {
        $this->isValid = $isValid;

        return $this;
    }

    /**
     * Get isValid
     *
     * @return bool
     */
    public function getIsValid()
    {
        return $this->isValid;
    }
}
