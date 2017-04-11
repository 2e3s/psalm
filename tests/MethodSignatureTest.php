<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class MethodSignatureTest extends PHPUnit_Framework_TestCase
{
    /** @var \PhpParser\Parser */
    protected static $parser;

    /** @var TestConfig */
    protected static $config;

    /** @var \Psalm\Checker\ProjectChecker */
    protected $project_checker;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        self::$config = new TestConfig();
    }

    /**
     * @return void
     */
    public function setUp()
    {
        FileChecker::clearCache();
        $this->project_checker = new \Psalm\Checker\ProjectChecker();
        $this->project_checker->setConfig(self::$config);
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage Method B::fooFoo has more arguments than parent method A::fooFoo
     * @return                   void
     */
    public function testMoreArguments()
    {
        $stmts = self::$parser->parse('<?php
        class A {
            public function fooFoo(int $a, bool $b) : void {

            }
        }

        class B extends A {
            public function fooFoo(int $a, bool $b, array $c) : void {

            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage Method B::fooFoo has fewer arguments than parent method A::fooFoo
     * @return                   void
     */
    public function testFewerArguments()
    {
        $stmts = self::$parser->parse('<?php
        class A {
            public function fooFoo(int $a, bool $b) : void {

            }
        }

        class B extends A {
            public function fooFoo(int $a) : void {

            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage Argument 1 of B::fooFoo has wrong type 'bool', expecting 'int' as defined by A::foo
     * @return                   void
     */
    public function testDifferentArguments()
    {
        $stmts = self::$parser->parse('<?php
        class A {
            public function fooFoo(int $a, bool $b) : void {

            }
        }

        class B extends A {
            public function fooFoo(bool $b, int $a) : void {

            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }

    /**
     * @return void
     */
    public function testExtendDocblockParamType()
    {
        if (class_exists('SoapClient') === false) {
            $this->markTestSkipped(
                'Cannot run test, base class "SoapClient" does not exist!'
            );

            return;
        }
        $stmts = self::$parser->parse('<?php
        class A extends SoapClient
        {
           /**
             * @param string $function_name
             * @param array<mixed> $arguments
             * @param array<mixed> $options default null
             * @param array<mixed> $input_headers default null
             * @param array<mixed> $output_headers default null
             * @return mixed
             */
            public function __soapCall(
                $function_name,
                $arguments,
                $options = [],
                $input_headers = [],
                &$output_headers = []
            ) {

            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage MethodSignatureMismatch
     * @return                   void
     */
    public function testExtendDocblockParamTypeWithWrongParam()
    {
        if (class_exists('SoapClient') === false) {
            $this->markTestSkipped(
                'Cannot run test, base class "SoapClient" does not exist!'
            );

            return;
        }
        $stmts = self::$parser->parse('<?php
        class A extends SoapClient
        {
           /**
             * @param string $function_name
             * @param string $arguments
             * @param array<mixed> $options default null
             * @param array<mixed> $input_headers default null
             * @param array<mixed> $output_headers default null
             * @return mixed
             */
            public function __soapCall(
                $function_name,
                string $arguments,
                $options = [],
                $input_headers = [],
                &$output_headers = []
            ) {

            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $context = new Context();
        $file_checker->visitAndAnalyzeMethods($context);
    }
}
