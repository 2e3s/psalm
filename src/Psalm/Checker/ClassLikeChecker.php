<?php

namespace Psalm\Checker;

use Psalm\Issue\InvalidClass;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedTrait;
use Psalm\IssueBuffer;
use Psalm\Context;
use Psalm\Config;
use Psalm\Type;
use Psalm\StatementsSource;
use PhpParser;
use PhpParser\Error;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

abstract class ClassLikeChecker implements StatementsSource
{
    protected static $SPECIAL_TYPES = ['int', 'string', 'double', 'float', 'bool', 'false', 'object', 'empty', 'callable', 'array'];

    protected $_file_name;
    protected $_class;
    protected $_namespace;
    protected $_aliased_classes;
    protected $_absolute_class;
    protected $_has_custom_get = false;
    protected $_source;

    /** @var string|null */
    protected $_parent_class;

    /**
     * @var array
     */
    protected $_suppressed_issues;

    /**
     * @var array<ClassMethodChecker>
     */
    protected static $_method_checkers = [];

    protected static $_this_class = null;

    protected static $_existing_classes = [];
    protected static $_existing_classes_ci = [];
    protected static $_existing_interfaces_ci = [];
    protected static $_class_implements = [];

    protected static $_class_methods = [];
    protected static $_class_checkers = [];

    protected static $_public_class_properties = [];
    protected static $_protected_class_properties = [];
    protected static $_private_class_properties = [];

    protected static $_public_static_class_properties = [];
    protected static $_protected_static_class_properties = [];
    protected static $_private_static_class_properties = [];

    protected static $_public_class_constants = [];

    protected static $_class_extends = [];

    public function __construct(PhpParser\Node\Stmt\ClassLike $class, StatementsSource $source, $absolute_class)
    {
        $this->_class = $class;
        $this->_namespace = $source->getNamespace();
        $this->_aliased_classes = $source->getAliasedClasses();
        $this->_file_name = $source->getFileName();
        $this->_absolute_class = $absolute_class;

        $this->_suppressed_issues = $source->getSuppressedIssues();

        $this->_parent_class = $this->_class->extends
            ? self::getAbsoluteClassFromName($this->_class->extends, $this->_namespace, $this->_aliased_classes)
            : null;

        self::$_existing_classes[$absolute_class] = true;
        self::$_existing_classes_ci[strtolower($absolute_class)] = true;

        if (self::$_this_class || $class instanceof PhpParser\Node\Stmt\Trait_) {
            self::$_class_checkers[$absolute_class] = $this;
        }
    }

    public function check($check_methods = true, Context $class_context = null)
    {
        if ($this->_parent_class) {
            if (self::checkAbsoluteClassOrInterface(
                    $this->_parent_class,
                    $this->_file_name,
                    $this->_class->getLine(),
                    $this->getSuppressedIssues()
                ) === false
            ) {
                return false;
            }

            if (!isset(self::$_public_class_properties[$this->_parent_class])) {
                self::_registerClassProperties($this->_parent_class);
            }

            if (!isset(self::$_public_class_constants[$this->_parent_class])) {
                self::_registerClassConstants($this->_parent_class);
            }

            $this->_registerInheritedMethods($this->_parent_class);
        }

        $config = Config::getInstance();

        $leftover_stmts = [];

        $method_checkers = [];

        self::$_class_methods[$this->_absolute_class] = [];

        self::$_public_class_properties[$this->_absolute_class] = [];
        self::$_protected_class_properties[$this->_absolute_class] = [];
        self::$_private_class_properties[$this->_absolute_class] = [];

        self::$_public_static_class_properties[$this->_absolute_class] = [];
        self::$_protected_static_class_properties[$this->_absolute_class] = [];
        self::$_private_static_class_properties[$this->_absolute_class] = [];

        self::$_public_class_constants[$this->_absolute_class] = [];


        if ($this->_parent_class) {
            self::$_public_class_properties[$this->_absolute_class] = self::$_public_class_properties[$this->_parent_class];
            self::$_protected_class_properties[$this->_absolute_class] = self::$_protected_class_properties[$this->_parent_class];

            self::$_public_static_class_properties[$this->_absolute_class] = self::$_public_static_class_properties[$this->_parent_class];
            self::$_protected_static_class_properties[$this->_absolute_class] = self::$_protected_static_class_properties[$this->_parent_class];

            self::$_public_class_constants[$this->_absolute_class] = self::$_public_class_constants[$this->_parent_class];
        }

        if (!$class_context) {
            $class_context = new Context();
            $class_context->self = $this->_absolute_class;
            $class_context->parent = $this->_parent_class;
            $class_context->vars_in_scope['this'] = new Type\Union([new Type\Atomic($this->_absolute_class)]);
        }

        foreach ($this->_class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                $method_id = $this->_absolute_class . '::' . $stmt->name;

                if (!isset(self::$_method_checkers[$method_id])) {
                    $method_checker = new ClassMethodChecker($stmt, $this);
                    $method_checkers[$stmt->name] = $method_checker;

                    if (self::$_this_class && !$check_methods) {
                        self::$_method_checkers[$method_id] = $method_checker;
                    }
                }
                else {
                    $method_checker = self::$_method_checkers[$method_id];
                }

                self::$_class_methods[$this->_absolute_class][] = $stmt->name;
            } elseif ($stmt instanceof PhpParser\Node\Stmt\TraitUse) {
                $method_map = [];
                foreach ($stmt->adaptations as $adaptation) {
                    if ($adaptation instanceof PhpParser\Node\Stmt\TraitUseAdaptation\Alias) {
                        $method_map[$adaptation->method] = $adaptation->newName;
                    }
                }

                foreach ($stmt->traits as $trait) {
                    $trait_name = self::getAbsoluteClassFromName($trait, $this->_namespace, $this->_aliased_classes);

                    if (!trait_exists($trait_name)) {
                        if (IssueBuffer::accepts(
                            new UndefinedTrait('Trait ' . $trait_name . ' does not exist', $this->_file_name, $trait->getLine()),
                            $this->_suppressed_issues
                        )) {
                            return false;
                        }
                    }
                    else {
                        try {
                            $reflection_trait = new \ReflectionClass($trait_name);
                        }
                        catch (\ReflectionException $e) {
                            if (IssueBuffer::accepts(
                                new UndefinedTrait('Trait ' . $trait_name . ' has wrong casing', $this->_file_name, $trait->getLine()),
                                $this->_suppressed_issues
                            )) {
                                return false;
                            }

                            continue;
                        }

                        $this->_registerInheritedMethods($trait_name, $method_map);

                        $trait_checker = FileChecker::getClassLikeCheckerFromClass($trait_name);

                        $trait_checker->check(true, $class_context);
                    }
                }
            } else {
                if ($stmt instanceof PhpParser\Node\Stmt\Property) {
                    $comment = $stmt->getDocComment();
                    $type_in_comment = null;

                    if ($comment && $config->use_docblock_types && count($stmt->props) === 1) {
                        $type_in_comment = CommentChecker::getTypeFromComment((string) $comment, null, $this);
                    }

                    $property_type = $type_in_comment ? $type_in_comment : Type::getMixed();

                    foreach ($stmt->props as $property) {
                        if ($stmt->isStatic()) {
                            if ($stmt->isPublic()) {
                                self::$_public_static_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                            elseif ($stmt->isProtected()) {
                                self::$_protected_static_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                            elseif ($stmt->isPrivate()) {
                                self::$_private_static_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                        }
                        else {
                            if ($stmt->isPublic()) {
                                self::$_public_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                            elseif ($stmt->isProtected()) {
                                self::$_protected_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                            elseif ($stmt->isPrivate()) {
                                self::$_private_class_properties[$class_context->self][$property->name] = $property_type;
                            }
                        }

                        if (!$stmt->isStatic()) {
                            $class_context->vars_in_scope['this->' . $property->name] = $property_type;
                        }
                    }
                }
                elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {
                    $comment = $stmt->getDocComment();
                    $type_in_comment = null;

                    if ($comment && $config->use_docblock_types && count($stmt->consts) === 1) {
                        $type_in_comment = CommentChecker::getTypeFromComment((string) $comment, null, $this);
                    }

                    $const_type = $type_in_comment ? $type_in_comment : Type::getMixed();

                    foreach ($stmt->consts as $const) {
                        self::$_public_class_constants[$class_context->self][$const->name] = $const_type;
                    }
                }

                $leftover_stmts[] = $stmt;
            }
        }

        if (method_exists($this->_absolute_class, '__get')) {
            $this->_has_custom_get = true;
        }

        if ($leftover_stmts) {
            (new StatementsChecker($this))->check($leftover_stmts, $class_context);
        }

        $config = Config::getInstance();

        if ($check_methods) {
            // do the method checks after all class methods have been initialised
            foreach ($method_checkers as $method_checker) {
                $method_checker->check(clone $class_context);

                if (!$config->excludeIssueInFile('InvalidReturnType', $this->_file_name)) {
                    $method_checker->checkReturnTypes();
                }
            }
        }
    }

    /**
     * Used in deep method evaluation, we get method checkers on the current or parent
     * classes
     *
     * @param  string $method_id
     * @return ClassMethodChecker
     */
    public static function getMethodChecker($method_id)
    {
        if (isset(self::$_method_checkers[$method_id])) {
            return self::$_method_checkers[$method_id];
        }

        $declaring_method_id = ClassMethodChecker::getDeclaringMethod($method_id);
        $declaring_class = explode('::', $declaring_method_id)[0];

        $class_checker = FileChecker::getClassLikeCheckerFromClass($declaring_class);

        if (!$class_checker) {
            throw new \InvalidArgumentException('Could not get class checker for ' . $declaring_class);
        }

        foreach ($class_checker->_class->stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\ClassMethod) {
                $method_checker = new ClassMethodChecker($stmt, $class_checker);
                $method_id = $class_checker->_absolute_class . '::' . $stmt->name;
                self::$_method_checkers[$method_id] = $method_checker;
                return $method_checker;
            }
        }

        throw new \InvalidArgumentException('Method checker not found');
    }

    /**
     * Returns a class checker for the given class, if one has already been registered
     * @param  string $class_name
     * @return self|null
     */
    public static function getClassLikeCheckerFromClass($class_name)
    {
        if (isset(self::$_class_checkers[$class_name])) {
            return self::$_class_checkers[$class_name];
        }

        return null;
    }

    /**
     * @return bool|null
     */
    public static function checkClassName(PhpParser\Node\Name $class_name, $namespace, array $aliased_classes, $file_name, array $suppressed_issues)
    {
        if ($class_name->parts[0] === 'static') {
            return;
        }

        $absolute_class = self::getAbsoluteClassFromName($class_name, $namespace, $aliased_classes);

        return self::checkAbsoluteClassOrInterface($absolute_class, $file_name, $class_name->getLine(), $suppressed_issues);
    }

    /**
     * @param  string $absolute_class
     * @return bool
     */
    public static function classOrInterfaceExists($absolute_class)
    {
        return ClassChecker::classExists($absolute_class) || InterfaceChecker::interfaceExists($absolute_class);
    }

    /**
     * @param  string $absolute_class
     * @param  string $possible_parent
     * @return bool
     */
    public static function classExtendsOrImplements($absolute_class, $possible_parent)
    {
        return ClassChecker::classExtends($absolute_class, $possible_parent) || self::classImplements($absolute_class, $possible_parent);
    }

    /**
     * @param  string $absolute_class
     * @param  string $file_name
     * @param  int $line_number
     * @param  array<string>  $suppressed_issues
     * @return bool|null
     */
    public static function checkAbsoluteClassOrInterface($absolute_class, $file_name, $line_number, array $suppressed_issues)
    {
        if (empty($absolute_class)) {
            throw new \InvalidArgumentException('$class cannot be empty');
        }

        $absolute_class = preg_replace('/^\\\/', '', $absolute_class);

        if (!self::classOrInterfaceExists($absolute_class)) {
            if (IssueBuffer::accepts(
                new UndefinedClass('Class or interface ' . $absolute_class . ' does not exist', $file_name, $line_number),
                $suppressed_issues
            )) {
                return false;
            }

            return;
        }

        if (isset(self::$_existing_classes_ci[strtolower($absolute_class)])) {
            try {
                $reflection_class = new ReflectionClass($absolute_class);

                if ($reflection_class->getName() !== $absolute_class) {
                    if (IssueBuffer::accepts(
                        new InvalidClass('Class or interface ' . $absolute_class . ' has wrong casing', $file_name, $line_number),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                }
            }
            catch (\ReflectionException $e) {
                // do nothing
            }
        }

        return true;
    }

    public static function getAbsoluteClassFromName(PhpParser\Node\Name $class_name, $namespace, array $aliased_classes)
    {
        if ($class_name instanceof PhpParser\Node\Name\FullyQualified) {
            return implode('\\', $class_name->parts);
        }

        return self::getAbsoluteClassFromString(implode('\\', $class_name->parts), $namespace, $aliased_classes);
    }

    public static function getAbsoluteClassFromString($class, $namespace, array $imported_namespaces)
    {
        if (empty($class)) {
            throw new \InvalidArgumentException('$class cannot be empty');
        }

        if ($class[0] === '\\') {
            return substr($class, 1);
        }

        if (strpos($class, '\\') !== false) {
            $class_parts = explode('\\', $class);
            $first_namespace = array_shift($class_parts);

            if (isset($imported_namespaces[$first_namespace])) {
                return $imported_namespaces[$first_namespace] . '\\' . implode('\\', $class_parts);
            }
        } elseif (isset($imported_namespaces[$class])) {
            return $imported_namespaces[$class];
        }

        return ($namespace ? $namespace . '\\' : '') . $class;
    }

    public function getNamespace()
    {
        return $this->_namespace;
    }

    public function getAliasedClasses()
    {
        return $this->_aliased_classes;
    }

    public function getAbsoluteClass()
    {
        return $this->_absolute_class;
    }

    public function getClassName()
    {
        return $this->_class->name;
    }

    public function getParentClass()
    {
        return $this->_parent_class;
    }

    public function getFileName()
    {
        return $this->_file_name;
    }

    public function getClassLikeChecker()
    {
        return $this;
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return false;
    }

    public function hasCustomGet()
    {
        return $this->_has_custom_get;
    }

    protected static function _registerClassProperties($class_name)
    {
        try {
            $reflected_class = new ReflectionClass($class_name);
        }
        catch (\ReflectionException $e) {
            return false;
        }

        if ($reflected_class->isUserDefined()) {
            $class_file_name = $reflected_class->getFileName();

            (new FileChecker($class_file_name))->check(true, false);
        }
        else {
            $class_properties = $reflected_class->getProperties();

            self::$_public_class_properties[$class_name] = [];
            self::$_protected_class_properties[$class_name] = [];
            self::$_private_class_properties[$class_name] = [];

            self::$_public_static_class_properties[$class_name] = [];
            self::$_protected_static_class_properties[$class_name] = [];
            self::$_private_static_class_properties[$class_name] = [];

            foreach ($class_properties as $class_property) {
                if ($class_property->isStatic()) {
                    if ($class_property->isPublic()) {
                        self::$_public_static_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                    elseif ($class_property->isProtected()) {
                        self::$_protected_static_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                    elseif ($class_property->isPrivate()) {
                        self::$_private_static_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                }
                else {
                    if ($class_property->isPublic()) {
                        self::$_public_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                    elseif ($class_property->isProtected()) {
                        self::$_protected_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                    elseif ($class_property->isPrivate()) {
                        self::$_private_class_properties[$class_name][$class_property->getName()] = Type::getMixed();
                    }
                }

            }
        }
    }

    protected static function _registerClassConstants($class_name)
    {
        try {
            $reflected_class = new ReflectionClass($class_name);
        }
        catch (\ReflectionException $e) {
            return false;
        }

        if ($reflected_class->isUserDefined()) {
            $class_file_name = $reflected_class->getFileName();

            (new FileChecker($class_file_name))->check(true, false);
        }
        else {
            $class_constants = $reflected_class->getConstants();

            self::$_public_class_constants[$class_name] = [];

            foreach ($class_constants as $name => $value) {
                switch (gettype($value)) {
                    case 'boolean':
                        $const_type = Type::getBool();
                        break;

                    case 'integer':
                        $const_type = Type::getInt();
                        break;

                    case 'double':
                        $const_type = Type::getFloat();
                        break;

                    case 'string':
                        $const_type = Type::getString();
                        break;

                    case 'array':
                        $const_type = Type::getArray();
                        break;

                    case 'NULL':
                        $const_type = Type::getNull();
                        break;

                    default:
                        $const_type = Type::getMixed();
                }

                self::$_public_class_properties[$class_name][$name] = $const_type;
            }
        }
    }

    public static function getInstancePropertiesForClass($class_name, $visibility)
    {
        if (!isset(self::$_public_class_properties[$class_name])) {
            if (self::_registerClassProperties($class_name) === false) {
                return [];
            }
        }

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return self::$_public_class_properties[$class_name];
        }
        elseif ($visibility === ReflectionProperty::IS_PROTECTED) {
            return array_merge(
                self::$_public_class_properties[$class_name],
                self::$_protected_class_properties[$class_name]
            );
        }
        elseif ($visibility === ReflectionProperty::IS_PRIVATE) {
            return array_merge(
                self::$_public_class_properties[$class_name],
                self::$_protected_class_properties[$class_name],
                self::$_private_class_properties[$class_name]
            );
        }

        throw new \InvalidArgumentException('Must specify $visibility');
    }

    public static function getStaticPropertiesForClass($class_name, $visibility)
    {
        if (!isset(self::$_public_static_class_properties[$class_name])) {
            if (self::_registerClassProperties($class_name) === false) {
                return [];
            }
        }

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return self::$_public_static_class_properties[$class_name];
        }
        elseif ($visibility === ReflectionProperty::IS_PROTECTED) {
            return array_merge(
                self::$_public_static_class_properties[$class_name],
                self::$_protected_static_class_properties[$class_name]
            );
        }
        elseif ($visibility === ReflectionProperty::IS_PRIVATE) {
            return array_merge(
                self::$_public_static_class_properties[$class_name],
                self::$_protected_static_class_properties[$class_name],
                self::$_private_static_class_properties[$class_name]
            );
        }

        throw new \InvalidArgumentException('Must specify $visibility');
    }

    public static function getConstantsForClass($class_name, $visibility)
    {
        // remove for PHP 7.1 support
        $visibility = ReflectionProperty::IS_PUBLIC;

        if (!isset(self::$_public_class_constants[$class_name])) {
            if (self::_registerClassConstants($class_name) === false) {
                return [];
            }
        }

        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return self::$_public_class_constants[$class_name];
        }

        throw new \InvalidArgumentException('Given $visibility not supported');
    }

    public static function setConstantType($class_name, $const_name, Type\Union $type)
    {
        self::$_public_class_constants[$class_name] = $type;
    }

    public function getSource()
    {
        return null;
    }

    public function getSuppressedIssues()
    {
        return $this->_suppressed_issues;
    }

    /**
     * @return bool
     */
    public static function classImplements($absolute_class, $interface)
    {
        if (isset(self::$_class_implements[$absolute_class][$interface])) {
            return true;
        }

        if (isset(self::$_class_implements[$absolute_class])) {
            return false;
        }

        if (!ClassChecker::classExists($absolute_class)) {
            return false;
        }

        if (in_array($interface, self::$SPECIAL_TYPES)) {
            return false;
        }

        $class_implementations = class_implements($absolute_class);

        if (!isset($class_implementations[$interface])) {
            return false;
        }

        self::$_class_implements[$absolute_class] = $class_implementations;

        return true;
    }

    protected function _registerInheritedMethods($parent_class, array $method_map = null)
    {
        if (!isset(self::$_class_methods[$parent_class])) {
            $class_methods = [];

            $reflection_class = new ReflectionClass($parent_class);

            $reflection_methods = $reflection_class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

            foreach ($reflection_methods as $reflection_method) {
                if (!$reflection_method->isAbstract() && $reflection_method->getDeclaringClass()->getName() === $parent_class) {
                    $method_name = $reflection_method->getName();
                    $class_methods[] = $method_name;
                }
            }

            self::$_class_methods[$parent_class] = $class_methods;
        }
        else {
            $class_methods = self::$_class_methods[$parent_class];
        }

        foreach ($class_methods as $method_name) {
            $parent_method_id = $parent_class . '::' . $method_name;
            $implemented_method_id = $this->_absolute_class . '::' . (isset($method_map[$method_name]) ? $method_map[$method_name] : $method_name);

            ClassMethodChecker::registerInheritedMethod($parent_method_id, $implemented_method_id);
        }
    }

    public static function setThisClass($this_class)
    {
        self::$_this_class = $this_class;

        self::$_class_checkers = [];
    }

    public static function getThisClass()
    {
        return self::$_this_class;
    }

    public static function clearCache()
    {
        self::$_method_checkers = [];

        self::$_this_class = null;

        self::$_existing_classes = [];
        self::$_existing_classes_ci = [];
        self::$_existing_interfaces_ci = [];
        self::$_class_implements = [];

        self::$_class_methods = [];
        self::$_class_checkers = [];

        self::$_public_class_properties = [];
        self::$_protected_class_properties = [];
        self::$_private_class_properties = [];

        self::$_public_static_class_properties = [];
        self::$_protected_static_class_properties = [];
        self::$_private_static_class_properties = [];

        self::$_class_extends = [];
    }
}
