<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class AnnotationTest extends PHPUnit_Framework_TestCase
{
    /** @var \PhpParser\Parser */
    protected static $parser;

    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        $config = new TestConfig();
        $config->throw_exception = true;
        $config->use_docblock_types = true;
    }

    public function setUp()
    {
        FileChecker::clearCache();
    }

    public function testDeprecatedMethod()
    {
        $stmts = self::$parser->parse('<?php
        class Foo {
            /**
             * @deprecated
             */
            public static function bar() : void {
            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    /**
     * @expectedException \Psalm\Exception\CodeException
     * @expectedExceptionMessage DeprecatedMethod
     */
    public function testDeprecatedMethodWithCall()
    {
        $stmts = self::$parser->parse('<?php
        class Foo {
            /**
             * @deprecated
             */
            public static function bar() : void {
            }
        }

        Foo::bar();
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    /**
     * @expectedException \Psalm\Exception\CodeException
     * @expectedExceptionMessage InvalidDocblock
     */
    public function testInvalidDocblockParam()
    {
        $stmts = self::$parser->parse('<?php
        /**
         * @param int $bar
         */
        function foo(array $bar) : void {
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    /**
     * @expectedException \Psalm\Exception\CodeException
     * @expectedExceptionMessage InvalidDocblock
     */
    public function testExtraneousDocblockParam()
    {
        $stmts = self::$parser->parse('<?php
        /**
         * @param int $bar
         */
        function foo() : void {
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    /**
     * @expectedException \Psalm\Exception\CodeException
     * @expectedExceptionMessage InvalidDocblock
     */
    public function testInvalidDocblockReturn()
    {
        $stmts = self::$parser->parse('<?php
        /**
         * @return string
         */
        function foo() : void {
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }

    public function testValidDocblockReturn()
    {
        $stmts = self::$parser->parse('<?php
        /**
         * @return string
         */
        function foo() : string {
            return "boop";
        }

        /**
         * @return array<int, string>
         */
        function foo2() : array {
            return ["hello"];
        }

        /**
         * @return array<int, array<int, string>>
         */
        function foo2() : array {
            return ["hello"];
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $context = new Context('somefile.php');
        $file_checker->check(true, true, $context);
    }
}
