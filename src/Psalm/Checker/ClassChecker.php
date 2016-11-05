<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\StatementsSource;

class ClassChecker extends ClassLikeChecker
{
    /**
     * @var PhpParser\Node\Stmt\Class_
     */
    protected $class;

    /**
     * A lookup table of existing classes
     *
     * @var array<string, bool>
     */
    protected static $existing_classes = [];

    /**
     * A lookup table of existing classes, all lowercased
     *
     * @var array<string, bool>
     */
    protected static $existing_classes_ci = [];

    /**
     * A lookup table used for caching the results of classExtends calls
     *
     * @var array<string, array<string, bool>>
     */
    protected static $class_extends = [];

    /**
     * @var integer
     */
    protected static $anonymous_class_count = 0;

    /**
     * @param PhpParser\Node\Stmt\ClassLike $class
     * @param StatementsSource              $source
     * @param string|null                   $absolute_class
     */
    public function __construct(PhpParser\Node\Stmt\ClassLike $class, StatementsSource $source, $absolute_class)
    {
        if (!$class instanceof PhpParser\Node\Stmt\Class_) {
            throw new \InvalidArgumentException('Bad');
        }

        if ($absolute_class === null) {
            $absolute_class = 'PsalmAnonymousClass' . (self::$anonymous_class_count++);
        }

        parent::__construct($class, $source, $absolute_class);

        self::$existing_classes[$absolute_class] = true;
        self::$existing_classes_ci[strtolower($absolute_class)] = true;

        self::$class_implements[$absolute_class] = [];

        if ($this->class->extends) {
            $this->parent_class = self::getAbsoluteClassFromName(
                $this->class->extends,
                $this->namespace,
                $this->aliased_classes
            );
        }

        foreach ($class->implements as $interface_name) {
            $absolute_interface_name = self::getAbsoluteClassFromName(
                $interface_name,
                $this->namespace,
                $this->aliased_classes
            );

            self::$class_implements[$absolute_class][strtolower($absolute_interface_name)] = $absolute_interface_name;
        }
    }

    /**
     * Determine whether or not a given class exists
     *
     * @param  string $absolute_class
     * @return bool
     */
    public static function classExists($absolute_class)
    {
        if (isset(self::$existing_classes_ci[strtolower($absolute_class)])) {
            return self::$existing_classes_ci[strtolower($absolute_class)];
        }

        if (in_array($absolute_class, self::$SPECIAL_TYPES)) {
            return false;
        }

        $old_level = error_reporting();
        error_reporting(0);
        $class_exists = class_exists($absolute_class);
        error_reporting($old_level);

        if ($class_exists) {
            $old_level = error_reporting();
            error_reporting(0);
            $reflected_class = new \ReflectionClass($absolute_class);
            error_reporting($old_level);

            self::$existing_classes_ci[strtolower($absolute_class)] = true;
            self::$existing_classes[$reflected_class->getName()] = true;

            return true;
        }

        // we can only be sure that the case-sensitive version does not exist
        self::$existing_classes[$absolute_class] = false;

        return false;
    }

    /**
     * Determine whether or not a class has the correct casing
     *
     * @param  string  $absolute_class
     * @return bool
     */
    public static function hasCorrectCasing($absolute_class)
    {
        if (!self::classExists($absolute_class)) {
            throw new \InvalidArgumentException('Cannot check casing on nonexistent class ' . $absolute_class);
        }

        return isset(self::$existing_classes[$absolute_class]);
    }

    /**
     * Determine whether or not a class extends a parent
     *
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

    /**
     * Get all the interfaces a given class implements
     *
     * @param  string $absolute_class
     * @return array<string>
     */
    public static function getInterfacesForClass($absolute_class)
    {
        if (!isset(self::$class_implements[$absolute_class])) {
            /** @var string[] */
            $class_implements = class_implements($absolute_class);

            self::$class_implements[$absolute_class] = [];

            foreach ($class_implements as $interface) {
                 self::$class_implements[$absolute_class][strtolower($interface)] = $interface;
            }
        }

        return self::$class_implements[$absolute_class];
    }

    /**
     * Check whether a class implements an interface
     *
     * @param  string $absolute_class
     * @param  string $interface
     * @return bool
     */
    public static function classImplements($absolute_class, $interface)
    {
        $interface_id = strtolower($interface);

        if ($interface_id === 'callable' && $absolute_class === 'Closure') {
            return true;
        }

        if (isset(self::$class_implements[$absolute_class][$interface_id])) {
            return true;
        }

        if (isset(self::$class_implements[$absolute_class])) {
            return false;
        }

        if (!ClassChecker::classExists($absolute_class)) {
            return false;
        }

        if (in_array($interface_id, self::$SPECIAL_TYPES)) {
            return false;
        }

        $class_implementations = self::getInterfacesForClass($absolute_class);

        return isset($class_implementations[$interface_id]);
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$existing_classes = [];
        self::$existing_classes_ci = [];

        self::$class_extends = [];

        self::$anonymous_class_count = 0;

        MethodChecker::clearCache();
    }
}
