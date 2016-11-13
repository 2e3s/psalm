<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Type;

class TypeCombinationTest extends PHPUnit_Framework_TestCase
{
    protected static $parser;

    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    }

    private static function getAtomic($string)
    {
        return array_values(Type::parseString($string)->types)[0];
    }

    public function testIntOrString()
    {
        $this->assertEquals(
            'int|string',
            (string) Type::combineTypes([
                self::getAtomic('int'),
                self::getAtomic('string')
            ])
        );
    }

    public function testArrayOfIntOrString()
    {
        $this->assertEquals(
            'array<mixed, int|string>',
            (string) Type::combineTypes([
                self::getAtomic('array<int>'),
                self::getAtomic('array<string>')
            ])
        );
    }

    public function testArrayOfIntOrAlsoString()
    {
        $this->assertEquals(
            'array<mixed, int>|string',
            (string) Type::combineTypes([
                self::getAtomic('array<int>'),
                self::getAtomic('string')
            ])
        );
    }

    public function testEmptyArrays()
    {
        $this->assertEquals(
            'array<empty, empty>',
            (string) Type::combineTypes([
                self::getAtomic('array<empty,empty>'),
                self::getAtomic('array<empty,empty>')
            ])
        );
    }

    public function testArrayStringOrEmptyArray()
    {
        $this->assertEquals(
            'array<mixed, string>',
            (string) Type::combineTypes([
                self::getAtomic('array<empty>'),
                self::getAtomic('array<string>')
            ])
        );
    }

    public function testArrayMixedOrString()
    {
        $this->assertEquals(
            'array<mixed, mixed>',
            (string) Type::combineTypes([
                self::getAtomic('array<mixed>'),
                self::getAtomic('array<string>')
            ])
        );
    }

    public function testArrayMixedOrStringKeys()
    {
        $this->assertEquals(
            'array<mixed, string>',
            (string) Type::combineTypes([
                self::getAtomic('array<int|string,string>'),
                self::getAtomic('array<mixed,string>')
            ])
        );
    }

    public function testArrayMixedOrEmpty()
    {
        $this->assertEquals(
            'array<mixed, mixed>',
            (string) Type::combineTypes([
                self::getAtomic('array<empty>'),
                self::getAtomic('array<mixed>')
            ])
        );
    }

    public function testArrayBigCombination()
    {
        $this->assertEquals(
            'array<mixed, int|float|string>',
            (string) Type::combineTypes([
                self::getAtomic('array<int|float>'),
                self::getAtomic('array<string>')
            ])
        );
    }

    public function testFalseDestruction()
    {
        $this->assertEquals(
            'bool',
            (string) Type::combineTypes([
                self::getAtomic('false'),
                self::getAtomic('bool')
            ])
        );
    }

    public function testOnlyFalse()
    {
        $this->assertEquals(
            'bool',
            (string) Type::combineTypes([
                self::getAtomic('false')
            ])
        );
    }

    public function testFalseFalseDestruction()
    {
        $this->assertEquals(
            'bool',
            (string) Type::combineTypes([
                self::getAtomic('false'),
                self::getAtomic('false')
            ])
        );
    }

    public function testAAndAOfB()
    {
        $this->assertEquals(
            'A<mixed>',
            (string) Type::combineTypes([
                self::getAtomic('A'),
                self::getAtomic('A<B>')
            ])
        );
    }

    public function testCombineObjectType()
    {
        $this->assertEquals(
            'array{a:int, b:string}',
            (string) Type::combineTypes([
                self::getAtomic('array{a:int}'),
                self::getAtomic('array{b:string}')
            ])
        );

        $this->assertEquals(
            'array{a:int|string, b:string}',
            (string) Type::combineTypes([
                self::getAtomic('array{a:int}'),
                self::getAtomic('array{a:string,b:string}')
            ])
        );
    }

    public function testMultipleValuedArray()
    {
        $stmts = self::$parser->parse('<?php
            class A {}
            class B {}
            $var = [];
            $var[] = new A();
            $var[] = new B();
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }
}
