<?php

namespace CodeInspector\Tests;

use PhpParser;
use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;

class ArrayReturnTypeTest extends PHPUnit_Framework_TestCase
{
    protected static $_parser;

    public static function setUpBeforeClass()
    {
        self::$_parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        $config = \CodeInspector\Config::getInstance();
        $config->throw_exception = true;
        $config->use_docblock_types = true;
    }

    public function setUp()
    {
        \CodeInspector\ClassMethodChecker::clearCache();
    }

    public function testGenericArrayCreation()
    {
        $stmts = self::$_parser->parse('<?php
        class B {
            /**
             * @return array<int>
             */
            public function bar(array $in) {
                $out = [];

                foreach ($in as $key => $value) {
                    $out[] = 4;
                }

                return $out;
            }
        }');

        $file_checker = new \CodeInspector\FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }

    public function testGeneric2DArrayCreation()
    {
        $stmts = self::$_parser->parse('<?php
        class B {
            /**
             * @return array<array<int>>
             */
            public function bar(array $in) {
                $out = [];

                foreach ($in as $key => $value) {
                    $out[] = [4];
                }

                return $out;
            }
        }');

        $file_checker = new \CodeInspector\FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }

    public function testGeneric2DArrayCreationAddedInIf()
    {
        $stmts = self::$_parser->parse('<?php
        class B {
            /**
             * @return array<array<int>>
             */
            public function bar(array $in) {
                $out = [];

                $bits = [];

                foreach ($in as $key => $value) {
                    if (rand(0,100) > 50) {
                        $out[] = $bits;
                        $bits = [];
                    }

                    $bits[] = 4;
                }

                if ($bits) {
                    $out[] = $bits;
                }

                return $out;
            }
        }');

        $file_checker = new \CodeInspector\FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }

    public function testGenericArrayCreationWithObjectAddedInIf()
    {
        $stmts = self::$_parser->parse('<?php
        class B {
            /**
             * @return array<B>
             */
            public function bar(array $in) {
                $out = [];

                if (rand(0,10) === 10) {
                    $out[] = new B();
                }

                return $out;
            }
        }');

        $file_checker = new \CodeInspector\FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }

    public function testGenericArrayCreationWithObjectAddedInSwitch()
    {
        $stmts = self::$_parser->parse('<?php
        class B {
            /**
             * @return array<B>
             */
            public function bar(array $in) {
                $out = [];

                if (rand(0,10) === 10) {
                    switch (rand(0,10)) {
                        case 5:
                            $out[4] = new B();
                    }
                }

                return $out;
            }
        }');

        $file_checker = new \CodeInspector\FileChecker('somefile.php', $stmts);
        $file_checker->check();
    }
}
