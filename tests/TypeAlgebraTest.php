<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;
use Psalm\Context;

class TypeAlgebraTest extends PHPUnit_Framework_TestCase
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
     * @return void
     */
    public function testTwoVarLogic()
    {
        $stmts = self::$parser->parse('<?php
        $a = rand(0, 10) ? "hello" : null;
        $b = rand(0, 10) ? "goodbye" : null;

        if ($a !== null || $b !== null) {
            if ($a !== null) {
                $c = $a;
            } else {
                $c = $b;
            }

            echo strpos($c, "e");
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testThreeVarLogic()
    {
        $stmts = self::$parser->parse('<?php
        $a = rand(0, 10) ? "hello" : null;
        $b = rand(0, 10) ? "goodbye" : null;
        $c = rand(0, 10) ? "hello" : null;

        if ($a !== null || $b !== null || $c !== null) {
            if ($a !== null) {
                $d = $a;
            } elseif ($b !== null) {
                $d = $b;
            } else {
                $d = $c;
            }

            echo strpos($d, "e");
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage NullArgument
     * @return                   void
     */
    public function testThreeVarLogicWithChange()
    {
        $stmts = self::$parser->parse('<?php
        $a = rand(0, 10) ? "hello" : null;
        $b = rand(0, 10) ? "goodbye" : null;
        $c = rand(0, 10) ? "hello" : null;

        if ($a !== null || $b !== null || $c !== null) {
            $c = null;

            if ($a !== null) {
                $d = $a;
            } elseif ($b !== null) {
                $d = $b;
            } else {
                $d = $c;
            }

            echo strpos($d, "e");
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage NullArgument
     * @return                   void
     */
    public function testThreeVarLogicWithException()
    {
        $stmts = self::$parser->parse('<?php
        $a = rand(0, 10) ? "hello" : null;
        $b = rand(0, 10) ? "goodbye" : null;
        $c = rand(0, 10) ? "hello" : null;

        if ($a !== null || $b !== null || $c !== null) {
            if ($c !== null) {
                throw new \Exception("bad");
            }

            if ($a !== null) {
                $d = $a;
            } elseif ($b !== null) {
                $d = $b;
            } else {
                $d = $c;
            }

            echo strpos($d, "e");
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testTwoVarLogicNotNested()
    {
        $stmts = self::$parser->parse('<?php
        function foo(?string $a, ?string $b) : string {
            if (!$a && !$b) return "bad";
            if (!$a) return $b;
            return $a;
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @return void
     */
    public function testInvertedTwoVarLogicNotNested()
    {
        $stmts = self::$parser->parse('<?php
        function foo(?string $a, ?string $b) : string {
            if ($a || $b) {
                // do nothing
            } else {
                return "bad";
            }

            if (!$a) return $b;
            return $a;
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage MoreSpecificReturnType
     * @return                   void
     */
    public function testInvertedTwoVarLogicNotNestedWithVarChange()
    {
        $stmts = self::$parser->parse('<?php
        function foo(?string $a, ?string $b) : string {
            if ($a || $b) {
                $b = null;
            } else {
                return "bad";
            }

            if (!$a) return $b;
            return $a;
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }

    /**
     * @expectedException        \Psalm\Exception\CodeException
     * @expectedExceptionMessage MoreSpecificReturnType
     * @return                   void
     */
    public function testInvertedTwoVarLogicNotNestedWithElseif()
    {
        $stmts = self::$parser->parse('<?php
        function foo(?string $a, ?string $b) : string {
            if (true) {
                // do nothing
            } elseif ($a || $b) {
                // do nothing here
            } else {
                return "bad";
            }

            if (!$a) return $b;
            return $a;
        }
        ');

        $file_checker = new FileChecker('somefile.php', $this->project_checker, $stmts);
        $file_checker->visitAndAnalyzeMethods();
    }
}
