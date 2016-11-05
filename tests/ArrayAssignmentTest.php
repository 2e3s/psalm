<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Context;

class ArrayAssignmentTest extends PHPUnit_Framework_TestCase
{
    protected static $parser;

    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        $config = \Psalm\Config::getInstance();
        $config->throw_exception = true;
        $config->use_docblock_types = true;
    }

    public function setUp()
    {
        \Psalm\Checker\FileChecker::clearCache();
    }

    public function testImplicitIntArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo[] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array<int,string>', (string) $context->vars_in_scope['$foo']);
    }

    public function testImplicit2DIntArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo[][] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array<int,array<int,string>>', (string) $context->vars_in_scope['$foo']);
    }

    public function testImplicit3DIntArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo[][][] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array<int,array<int,array<int,string>>>', (string) $context->vars_in_scope['$foo']);
    }

    public function testImplicit4DIntArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo[][][][] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals(
            'array<int,array<int,array<int,array<int,string>>>>',
            (string) $context->vars_in_scope['$foo']
        );
    }

    public function testImplicitIndexedIntArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo[0] = "hello";
        $foo[1] = "hello";
        $foo[2] = "hello";

        $bar = [0, 1, 2];

        $bat = [];

        foreach ($foo as $i => $text) {
            $bat[$text] = $bar[$i];
        }
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array<int,string>', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('array<int,int>', (string) $context->vars_in_scope['$bar']);
        $this->assertEquals('array<string,int>', (string) $context->vars_in_scope['$bat']);
    }

    public function testImplicitStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["bar"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:string}', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'bar\']']);
    }

    public function testImplicit2DStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["bar"]["baz"] = "hello";
        ');

        // check array access of baz on foo
        // with some extra data – if we need to create an array for type $foo["bar"],

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:array{baz:string}}', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'bar\'][\'baz\']']);
    }

    public function testImplicit3DStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["bar"]["baz"]["bat"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:array{baz:array{bat:string}}}', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'bar\'][\'baz\'][\'bat\']']);
    }

    public function testImplicit4DStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["bar"]["baz"]["bat"]["bap"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals(
            'array{bar:array{baz:array{bat:array{bap:string}}}}',
            (string) $context->vars_in_scope['$foo']
        );

        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'bar\'][\'baz\'][\'bat\'][\'bap\']']);
    }

    public function test2Step2DStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = ["bar" => []];
        $foo["bar"]["baz"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:array{baz:string}}', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'bar\'][\'baz\']']);
    }

    public function test2StepImplicit3DStringArrayCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = ["bar" => []];
        $foo["bar"]["baz"]["bat"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:array{baz:array{bat:string}}}', (string) $context->vars_in_scope['$foo']);
    }

    public function testConflictingTypes()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [
            "bar" => ["a" => "b"],
            "baz" => [1]
        ];
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:array{a:string},baz:array<int,int>}', (string) $context->vars_in_scope['$foo']);
    }

    public function testImplicitObjectLikeCreation()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [
            "bar" => 1,
        ];
        $foo["baz"] = "a";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{bar:int,baz:string}', (string) $context->vars_in_scope['$foo']);
    }

    public function testConflictingTypesWithAssignment()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [
            "bar" => ["a" => "b"],
            "baz" => [1]
        ];
        $foo["bar"]["bam"]["baz"] = "hello";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals(
            'array{bar:array{a:string,bam:array{baz:string}},baz:array<int,int>}',
            (string) $context->vars_in_scope['$foo']
        );
    }

    public function testConflictingTypesWithAssignment2()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["a"] = "hello";
        $foo["b"][] = "goodbye";
        $bar = $foo["a"];
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{a:string,b:array<int,string>}', (string) $context->vars_in_scope['$foo']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$foo[\'a\']']);
        $this->assertEquals('array<int,string>', (string) $context->vars_in_scope['$foo[\'b\']']);
        $this->assertEquals('string', (string) $context->vars_in_scope['$bar']);
    }

    public function testConflictingTypesWithAssignment3()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["a"] = "hello";
        $foo["b"]["c"]["d"] = "goodbye";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{a:string,b:array{c:array{d:string}}}', (string) $context->vars_in_scope['$foo']);
    }

    public function testNestedObjectLikeAssignment()
    {
        $stmts = self::$parser->parse('<?php
        $foo = [];
        $foo["a"]["b"] = "hello";
        $foo["a"]["c"] = 1;
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{a:array{b:string,c:int}}', (string) $context->vars_in_scope['$foo']);
    }

    public function testConditionalObjectLikeAssignment()
    {
        $stmts = self::$parser->parse('<?php
        $foo = ["a" => "hello"];
        if (rand(0, 10) === 5) {
            $foo["b"] = 1;
        }
        else {
            $foo["b"] = 2;
        }
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('array{a:string,b:int}', (string) $context->vars_in_scope['$foo']);
    }

    public function testIssetKeyedOffset()
    {
        $file_checker = new \Psalm\Checker\FileChecker(
            'somefile.php',
            self::$parser->parse('<?php
                if (!isset($foo["a"])) {
                    $foo["a"] = "hello";
                }
            ')
        );
        $context = new Context('somefile.php');
        $context->vars_in_scope['$foo'] = \Psalm\Type::getArray();
        $file_checker->check(true, true, $context);
        $this->assertEquals('mixed', (string) $context->vars_in_scope['$foo[\'a\']']);
    }

    public function testConditionalAssignment()
    {
        $file_checker = new \Psalm\Checker\FileChecker(
            'somefile.php',
            self::$parser->parse('<?php
                if ($b) {
                    $foo["a"] = "hello";
                }
            ')
        );

        $context = new Context('somefile.php');
        $context->vars_in_scope['$b'] = \Psalm\Type::getBool();
        $context->vars_in_scope['$foo'] = \Psalm\Type::getArray();
        $file_checker->check(true, true, $context);
        $this->assertFalse(isset($context->vars_in_scope['$foo[\'a\']']));
    }

    public function testImplementsArrayAccess()
    {
        $stmts = self::$parser->parse('<?php
        class A implements \ArrayAccess { }

        $a = new A();
        $a["bar"] = "cool";
        ');

        $file_checker = new \Psalm\Checker\FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('A', (string) $context->vars_in_scope['$a']);
        $this->assertFalse(isset($context->vars_in_scope['$a[\'bar\']']));
    }

    public function testConditionalCheck()
    {
        $file_checker = new \Psalm\Checker\FileChecker(
            'somefile.php',
            self::$parser->parse('<?php
                /**
                 * @param  array{b:string} $a
                 * @return null|string
                 */
                function foo($a) {
                    if ($a["b"]) {
                        return $a["b"];
                    }
                }
            ')
        );

        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    public function testArrayKey()
    {
        $file_checker = new \Psalm\Checker\FileChecker(
            'somefile.php',
            self::$parser->parse('<?php
            $a = ["foo", "bar"];
            $b = $a[0];

            $c = ["a" => "foo", "b"=> "bar"];
            $d = "a";
            $e = $a[$d];
            ')
        );

        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
        $this->assertEquals('string', (string)$context->vars_in_scope['$b']);
        $this->assertEquals('string', (string)$context->vars_in_scope['$e']);
    }
}
