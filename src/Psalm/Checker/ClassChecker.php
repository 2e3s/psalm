<?php

namespace Psalm\Checker;

use PhpParser;
use Psalm\StatementsSource;
use Psalm\Context;

class ClassChecker extends ClassLikeChecker
{
    protected static $existing_classes = [];
    protected static $existing_classes_ci = [];
    protected static $class_extends = [];
    protected static $class_implements = [];

    public function __construct(PhpParser\Node\Stmt\Class_ $class, StatementsSource $source, $absolute_class)
    {
        parent::__construct($class, $source, $absolute_class);

        self::$existing_classes[$absolute_class] = true;
        self::$existing_classes_ci[strtolower($absolute_class)] = true;

        self::$class_implements[$absolute_class] = [];

        if ($this->class->extends) {
            $this->parent_class = self::getAbsoluteClassFromName($this->class->extends, $this->namespace, $this->aliased_classes);
        }

        foreach ($class->implements as $interface_name) {
            $absolute_interface_name = self::getAbsoluteClassFromName($interface_name, $this->namespace, $this->aliased_classes);



            self::$class_implements[$absolute_class][$absolute_interface_name] = true;
        }
    }

    public function check($check_methods = true, Context $class_context = null)
    {
        if ($this->parent_class) {
            if (self::checkAbsoluteClassOrInterface(
                $this->parent_class,
                $this->file_name,
                $this->class->getLine(),
                $this->getSuppressedIssues()
            ) === false
            ) {
                return false;
            }

            if (!isset(self::$registered_classes[$this->parent_class])) {
                self::registerClass($this->parent_class);
            }

            $this->registerInheritedMethods($this->parent_class);

            self::$class_implements[$this->absolute_class] += self::$class_implements[$this->parent_class];
        }

        foreach (self::$class_implements[$this->absolute_class] as $interface_name => $_) {
            if (self::checkAbsoluteClassOrInterface(
                $interface_name,
                $this->file_name,
                $this->class->getLine(),
                $this->getSuppressedIssues()
            ) === false
            ) {
                return false;
            }

            if (!isset(self::$registered_classes[$interface_name])) {
                self::registerClass($interface_name);
            }
        }

        parent::check($check_methods, $class_context);
    }


    public static function classExists($absolute_class)
    {
        if (isset(self::$existing_classes_ci[strtolower($absolute_class)])) {
            return self::$existing_classes_ci[strtolower($absolute_class)];
        }

        if (in_array($absolute_class, self::$SPECIAL_TYPES)) {
            return false;
        }

        if (class_exists($absolute_class)) {
            $reflected_class = new \ReflectionClass($absolute_class);

            self::$existing_classes_ci[strtolower($absolute_class)] = true;
            self::$existing_classes[$reflected_class->getName()] = true;

            return true;
        }

        // we can only be sure that the case-sensitive version does not exist
        self::$existing_classes[$absolute_class] = false;

        return false;
    }

    public static function hasCorrectCasing($absolute_class)
    {
        if (!self::classExists($absolute_class)) {
            throw new \InvalidArgumentException('Cannot check casing on nonexistent class ' . $absolute_class);
        }

        return isset(self::$existing_classes[$absolute_class]);
    }

    /**
     * @param  string $absolute_class
     * @param  string $possible_parent
     * @return bool
     */
    public static function classExtends($absolute_class, $possible_parent)
    {
        if (isset(self::$class_extends[$absolute_class][$possible_parent])) {
            return self::$class_extends[$absolute_class][$possible_parent];
        }

        if (!self::classExists($absolute_class) || !self::classExists($possible_parent)) {
            return false;
        }

        if (!isset(self::$class_extends[$absolute_class])) {
            self::$class_extends[$absolute_class] = [];
        }

        self::$class_extends[$absolute_class][$possible_parent] = is_subclass_of($absolute_class, $possible_parent);

        return self::$class_extends[$absolute_class][$possible_parent];
    }

    public static function getInterfacesForClass($absolute_class)
    {
        if (!isset(self::$class_implements[$absolute_class])) {
            self::$class_implements[$absolute_class] = class_implements($absolute_class);
        }

        return self::$class_implements[$absolute_class];
    }

    /**
     * @return bool
     */
    public static function classImplements($absolute_class, $interface)
    {
        if (isset(self::$class_implements[$absolute_class][$interface])) {
            return true;
        }

        if (isset(self::$class_implements[$absolute_class])) {
            return false;
        }

        if (!ClassChecker::classExists($absolute_class)) {
            return false;
        }

        if (in_array($interface, self::$SPECIAL_TYPES)) {
            return false;
        }

        $class_implementations = self::getInterfacesForClass($absolute_class);

        return isset($class_implementations[$interface]);
    }
}
