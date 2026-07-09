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
            ->invoke( $bs, "<?php return [1,2,3];" );

        $this->assertStringContainsString( "[ 1,2,3 ]", $result );
    }

    public function test_it_formats_brackets_without_touching_comments_or_strings()
    {
        $bs = new BracketSmith( true );

        $result = ( new \ReflectionClass( $bs ) )
            ->getMethod( "addSpacesToArrays" )
            ->invoke( $bs, <<<'PHP'
                <?php
                $array = [1, 'two', $three];
                $value = $array[0];
                $empty = [];
                $string = "[do not touch]";
                // [do not touch]
            PHP );

        $this->assertStringContainsString( '$array = [ 1, \'two\', $three ];', $result );
        $this->assertStringContainsString( '$value = $array[ 0 ];', $result );
        $this->assertStringContainsString( '$empty = [];', $result );
        $this->assertStringContainsString( '$string = "[do not touch]";', $result );
        $this->assertStringContainsString( '// [do not touch]', $result );
    }
}
