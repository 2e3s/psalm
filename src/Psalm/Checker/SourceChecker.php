<?php
namespace Psalm\Checker;

use PhpParser\Node\Stmt\Namespace_;
use PhpParser;
use Psalm\Context;
use Psalm\StatementsSource;
use Psalm\Type;

abstract class SourceChecker implements StatementsSource
{
    /**
     * @var array<string, string>
     */
    protected $aliased_classes = [];

    /**
     * @var array<string, string>
     */
    protected $aliased_classes_flipped = [];

    /**
     * @var array<string, string>
     */
    protected $aliased_functions = [];

    /**
     * @var array<string, string>
     */
    protected $aliased_constants = [];

    /**
     * @var string
     */
    protected $file_name;

    /**
     * @var string
     */
    protected $file_path;

    /**
     * @var string|null
     */
    protected $include_file_name;

    /**
     * @var string|null
     */
    protected $include_file_path;

    /**
     * @var array
     */
    protected $suppressed_issues = [];

    /**
     * @var array<string, bool>
     */
    protected $declared_classes = [];

    /**
     * @param  PhpParser\Node\Stmt\Use_ $stmt
     * @return void
     */
    public function visitUse(PhpParser\Node\Stmt\Use_ $stmt)
    {
        foreach ($stmt->uses as $use) {
            $use_path = implode('\\', $use->name->parts);

            switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliased_functions[strtolower($use->alias)] = $use_path;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliased_constants[$use->alias] = $use_path;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                    $this->aliased_classes[strtolower($use->alias)] = $use_path;
                    $this->aliased_classes_flipped[strtolower($use_path)] = $use->alias;
                    break;
            }
        }
    }

    /**
     * @param  PhpParser\Node\Stmt\GroupUse $stmt
     * @return void
     */
    public function visitGroupUse(PhpParser\Node\Stmt\GroupUse $stmt)
    {
        $use_prefix = implode('\\', $stmt->prefix->parts);

        foreach ($stmt->uses as $use) {
            $use_path = $use_prefix . '\\' . implode('\\', $use->name->parts);

            switch ($use->type !== PhpParser\Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type) {
                case PhpParser\Node\Stmt\Use_::TYPE_FUNCTION:
                    $this->aliased_functions[strtolower($use->alias)] = $use_path;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_CONSTANT:
                    $this->aliased_constants[$use->alias] = $use_path;
                    break;

                case PhpParser\Node\Stmt\Use_::TYPE_NORMAL:
                    $this->aliased_classes[strtolower($use->alias)] = $use_path;
                    $this->aliased_classes_flipped[strtolower($use_path)] = $use->alias;
                    break;
            }
        }
    }

    /**
     * @param   string $class_name
     * @return  bool
     */
    public function containsClass($class_name)
    {
        return isset($this->declared_classes[$class_name]);
    }

    /**
     * @return array
     */
    public function getAliasedClasses()
    {
        return $this->aliased_classes;
    }

    /**
     * @return array
     */
    public function getAliasedClassesFlipped()
    {
        return $this->aliased_classes_flipped;
    }

    /**
     * Gets a list of all aliased constants
     *
     * @return array
     */
    public function getAliasedConstants()
    {
        return $this->aliased_constants;
    }

    /**
     * Gets a list of all aliased functions
     *
     * @return array
     */
    public function getAliasedFunctions()
    {
        return $this->aliased_functions;
    }

    /**
     * Gets a list of the classes declared
     *
     * @return array<string, bool>
     */
    public function getDeclaredClasses()
    {
        return $this->declared_classes;
    }

    /**
     * @return null
     */
    public function getFQCLN()
    {
        return null;
    }

    /**
     * @return null
     */
    public function getClassName()
    {
        return null;
    }

    /**
     * @return null
     */
    public function getClassLikeChecker()
    {
        return null;
    }

    /**
     * @return string|null
     */
    public function getParentClass()
    {
        return null;
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->file_name;
    }

    /**
     * @return string
     */
    public function getFilePath()
    {
        return $this->file_path;
    }

    /**
     * @return null|string
     */
    public function getIncludeFileName()
    {
        return $this->include_file_name;
    }

    /**
     * @return null|string
     */
    public function getIncludeFilePath()
    {
        return $this->include_file_path;
    }

    /**
     * @param string|null $file_name
     * @param string|null $file_path
     * @return void
     */
    public function setIncludeFileName($file_name, $file_path)
    {
        $this->include_file_name = $file_name;
        $this->include_file_path = $file_path;
    }

    /**
     * @return string
     */
    public function getCheckedFileName()
    {
        return $this->include_file_name ?: $this->file_name;
    }

    /**
     * @return string
     */
    public function getCheckedFilePath()
    {
        return $this->include_file_path ?: $this->file_path;
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return false;
    }

    /**
     * @return null
     */
    public function getSource()
    {
        return null;
    }

    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    public function getSuppressedIssues()
    {
        return $this->suppressed_issues;
    }

    public function getNamespace()
    {
        return '';
    }
}
