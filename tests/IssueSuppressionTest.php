<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Config;

class IssueSuppressionTest extends PHPUnit_Framework_TestCase
{
    /** @var \PhpParser\Parser */
    protected static $parser;
    protected static $file_filter;

    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    }

    public function setUp()
    {
        $config = new TestConfig();
        $config->throw_exception = true;
        FileChecker::clearCache();
    }

    public function testUndefinedClass()
    {
        $stmts = self::$parser->parse('<?php

        class A{
            /**
             * @psalm-suppress UndefinedClass
             * @psalm-suppress MixedMethodCall
             * @psalm-suppress MissingReturnType
             */
            public function a() {
                B::fooFoo()->barBar()->bat()->baz()->bam()->bas()->bee()->bet()->bes()->bis();
            }
        }
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }

    public function testExcludeIssue()
    {
        Config::getInstance()->setCustomErrorLevel('UndefinedFunction', Config::REPORT_SUPPRESS);

        $stmts = self::$parser->parse('<?php
        fooFoo();
        ');

        $file_checker = new FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }
}
