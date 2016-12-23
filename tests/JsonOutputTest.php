<?php
namespace Psalm\Tests;

use PhpParser\ParserFactory;
use PHPUnit_Framework_TestCase;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Config;
use Psalm\Context;
use Psalm\IssueBuffer;

class JsonOutputTest extends PHPUnit_Framework_TestCase
{
    /** @var \PhpParser\Parser */
    protected static $parser;

    public static function setUpBeforeClass()
    {
        self::$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        $config = new TestConfig();
        $config->throw_exception = false;
        $config->stop_on_first_error = false;
    }

    public function setUp()
    {
        FileChecker::clearCache();
    }

    public function testJsonOutputForReturnTypeError()
    {
        $file_contents = '<?php
function foo(int $a) : string {
    return $a + 1;
}';

        $project_checker = new ProjectChecker(false, true, ProjectChecker::TYPE_JSON);
        $project_checker->registerFile(
            'somefile.php',
            $file_contents
        );

        $file_checker = new FileChecker('somefile.php');
        $file_checker->check();
        $issue_data = IssueBuffer::getIssueData()[0];
        $this->assertSame('somefile.php', $issue_data['file_path']);
        $this->assertSame('error', $issue_data['type']);
        $this->assertSame("The given return type 'string' for foo is incorrect, got 'int'", $issue_data['message']);
        $this->assertSame(2, $issue_data['line_number']);
        $this->assertSame(
            'string',
            substr($file_contents, $issue_data['from'], $issue_data['to'] - $issue_data['from'])
        );
    }

    public function testJsonOutputForUndefinedVar()
    {
        $file_contents = '<?php
function foo(int $a) : int {
    return $b + 1;
}';

        $project_checker = new ProjectChecker(false, true, ProjectChecker::TYPE_JSON);
        $project_checker->registerFile(
            'somefile.php',
            $file_contents
        );

        $file_checker = new FileChecker('somefile.php');
        $file_checker->check();
        $issue_data = IssueBuffer::getIssueData()[0];
        $this->assertSame('somefile.php', $issue_data['file_path']);
        $this->assertSame('error', $issue_data['type']);
        $this->assertSame('Cannot find referenced variable $b', $issue_data['message']);
        $this->assertSame(3, $issue_data['line_number']);
        $this->assertSame(
            '$b',
            substr($file_contents, $issue_data['from'], $issue_data['to'] - $issue_data['from'])
        );
    }

    public function testJsonOutputForUnknownParamClass()
    {
        $file_contents = '<?php
function foo(Badger\Bodger $a) : Badger\Bodger {
    return $a;
}';

        $project_checker = new ProjectChecker(false, true, ProjectChecker::TYPE_JSON);
        $project_checker->registerFile(
            'somefile.php',
            $file_contents
        );

        $file_checker = new FileChecker('somefile.php');
        $file_checker->check();
        $issue_data = IssueBuffer::getIssueData()[0];
        $this->assertSame('somefile.php', $issue_data['file_path']);
        $this->assertSame('error', $issue_data['type']);
        $this->assertSame('Class or interface Badger\\Bodger does not exist', $issue_data['message']);
        $this->assertSame(2, $issue_data['line_number']);
        $this->assertSame(
            'Badger\\Bodger',
            substr($file_contents, $issue_data['from'], $issue_data['to'] - $issue_data['from'])
        );
    }
}
