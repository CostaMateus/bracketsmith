<?php

namespace BracketSmith\Tests;

use PHPUnit\Framework\TestCase;
use BracketSmith\BracketSmith;

class BracketSmithTest extends TestCase
{
    public function test_it_can_process_a_simple_string()
    {
        $bs = new BracketSmith( true );

        $result = ( new \ReflectionClass( $bs ) )
            ->getMethod( "addSpacesToArrays" )
            ->invoke( $bs, "return [1,2,3];" );

        $this->assertStringContainsString( "[ 1,2,3 ]", $result );
    }
}
