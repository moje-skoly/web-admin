<?php

namespace Tests\TransformatoBundle\Utils;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BuildTest extends WebTestCase
{
	private $build;
	public function setUp()
	{
		$client = static::createClient();

        $this->build = $client->getContainer()->get('transformator.utils.build');
	}

    public function testMergeString()
    {
        $obj1 = new \stdClass;
        $obj2 = new \stdClass;

        $obj1->attr1 = "a";
        $obj2->attr1 = "b";
        $result = $this->build->mergeObjects($obj1, $obj2);

        $this->assertEquals("b", $result->attr1);
    }

    public function testMergeObjects()
    {
        $obj1 = new \stdClass;
        $obj2 = new \stdClass;

        $obj3 = new \stdClass;
        $obj3->attr1 = "a";
        $obj3->attr2 = "b";
        $obj3->attr3 = "c";

        $obj4 = new \stdClass;
        $obj4->attr1 = "a";
        $obj4->attr2 = "x";
        $obj4->attr4 = "y";

        $obj1->attr1 = $obj3;
        $obj2->attr1 = $obj4;
        $result = $this->build->mergeObjects($obj1, $obj2);

        $this->assertEquals("a", $result->attr1->attr1);
        $this->assertEquals("x", $result->attr1->attr2);
        $this->assertEquals("c", $result->attr1->attr3);
        $this->assertEquals("y", $result->attr1->attr4);
    }

    public function testMergeArrays()
    {
        $obj1 = new \stdClass;
        $obj2 = new \stdClass;

        $obj3 = new \stdClass;
        $obj3->units = [
        	(object) [
        		"IZO" => "a",
        		"attr1" => "a",
        		"attr2" => "b",
        		"attr3" => "c"
        	],
        	(object) [
        		"IZO" => "b",
        		"attr1" => "a"
        	]
        ];

        $obj4 = new \stdClass;
        $obj4->units = [
        	(object) [
        		"IZO" => "a",
        		"attr1" => "a",
        		"attr2" => "x",
        		"attr4" => "y"
        	],
        	(object) [
        		"IZO" => "x",
        		"attr1" => "a"
        	]
        ];

        $obj1->attr1 = $obj3;
        $obj2->attr1 = $obj4;
        $result = $this->build->mergeObjects($obj1, $obj2);

        $units = $result->attr1->units;
        $a = array_values(array_filter($units, function ($array) {
        	return $array->IZO == "a";
        }));

        $b = array_values(array_filter($units, function ($array) {
        	return $array->IZO == "b";
        }));

        $x = array_values(array_filter($units, function ($array) {
        	return $array->IZO == "x";
        }));

        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertNotEmpty($x);
        
        $this->assertEquals("a", $a[0]->attr1);
        $this->assertEquals("x", $a[0]->attr2);
        $this->assertEquals("c", $a[0]->attr3);
        $this->assertEquals("y", $a[0]->attr4);
        $this->assertEquals("a", $b[0]->attr1);
        $this->assertEquals("a", $x[0]->attr1);
    }

    public function testRemoveRedundant() {
    	$obj1 = (object) [
    		"attr1" => "a",
    		"attr2" => "b",
    		"attr3" => "c"
    	];

    	$this->build->removeRedundant($obj1, ["attr1"]);

    	$this->assertObjectHasAttribute("attr1", $obj1);
    	$this->assertObjectNotHasAttribute("attr2", $obj1);
    	$this->assertObjectNotHasAttribute("attr3", $obj1);
    }
}