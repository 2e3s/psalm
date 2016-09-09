<?php

namespace Psalm\Checker;

use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\InaccessibleMethod;
use Psalm\Issue\DeprecatedMethod;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidStaticInvocation;
use Psalm\StatementsSource;
use Psalm\Config;
use Psalm\Type;
use Psalm\IssueBuffer;
use PhpParser;

class MethodChecker extends FunctionLikeChecker
{
    protected static $method_comments = [];
    protected static $method_files = [];
    protected static $method_params = [];
    protected static $method_namespaces = [];
    protected static $method_return_types = [];
    protected static $cased_method_ids = [];
    protected static $static_methods = [];
    protected static $declaring_methods = [];
    protected static $existing_methods = [];
    protected static $have_reflected = [];
    protected static $have_registered = [];
    protected static $inherited_methods = [];
    protected static $declaring_class = [];
    protected static $method_visibility = [];
    protected static $method_suppress = [];
    protected static $deprecated_methods = [];

    const VISIBILITY_PUBLIC = 1;
    const VISIBILITY_PROTECTED = 2;
    const VISIBILITY_PRIVATE = 3;

    public function __construct(PhpParser\Node\FunctionLike $function, StatementsSource $source, array $this_vars = [])
    {
        parent::__construct($function, $source);

        if ($function instanceof PhpParser\Node\Stmt\ClassMethod) {
            $this->registerMethod($function);
            $this->is_static = $function->isStatic();
        }
    }

    public static function getMethodParams($method_id)
    {
        self::registerClassMethod($method_id);

        $method_id = self::getDeclaringMethod($method_id);

        return self::$method_params[$method_id];
    }

    public static function getMethodReturnTypes($method_id)
    {
        self::registerClassMethod($method_id);

        $method_id = self::getDeclaringMethod($method_id);

        return self::$method_return_types[$method_id] ? clone self::$method_return_types[$method_id] : null;
    }

    /**
     * @return void
     */
    public static function extractReflectionMethodInfo(\ReflectionMethod $method)
    {
        $method_id = $method->class . '::' . strtolower($method->name);
        self::$cased_method_ids[$method_id] = $method->class . '::' . $method->name;

        if (isset(self::$have_reflected[$method_id])) {
            return;
        }

        self::$have_reflected[$method_id] = true;

        self::$static_methods[$method_id] = $method->isStatic();
        self::$method_files[$method_id] = $method->getFileName();
        self::$method_namespaces[$method_id] = $method->getDeclaringClass()->getNamespaceName();
        self::$declaring_methods[$method_id] = $method->getDeclaringClass()->name . '::' . strtolower($method->getName());
        self::$method_visibility[$method_id] = $method->isPrivate() ?
                                                    self::VISIBILITY_PRIVATE :
                                                    ($method->isProtected() ? self::VISIBILITY_PROTECTED : self::VISIBILITY_PUBLIC);


        $params = $method->getParameters();

        $method_param_names = [];
        $method_param_types = [];

        self::$method_params[$method_id] = [];

        foreach ($params as $param) {
            $param_array = self::getReflectionParamArray($param);
            self::$method_params[$method_id][] = $param_array;
            $method_param_names[$param->name] = true;
            $method_param_types[$param->name] = $param_array['type'];
        }

        $return_types = null;

        $config = Config::getInstance();

        $return_type = null;

        self::$method_return_types[$method_id] = $return_type;
    }

    /**
     * Determines whether a given method is static or not
     * @param  string  $method_id
     */
    public static function checkMethodStatic($method_id, $file_name, $line_number, array $suppressed_issues)
    {
        self::registerClassMethod($method_id);

        $method_id = self::getDeclaringMethod($method_id);

        if (!self::$static_methods[$method_id]) {
            if (IssueBuffer::accepts(
                new InvalidStaticInvocation('Method ' . MethodChecker::getCasedMethodId($method_id) . ' is not static', $file_name, $line_number),
                $suppressed_issues
            )) {
                return false;
            }
        }
    }

    protected function registerMethod(PhpParser\Node\Stmt\ClassMethod $method)
    {
        $method_id = $this->absolute_class . '::' . strtolower($method->name);
        self::$cased_method_ids[$method_id] = $this->absolute_class . '::' . $method->name;

        if (isset(self::$have_reflected[$method_id]) || isset(self::$have_registered[$method_id])) {
            $this->suppressed_issues = self::$method_suppress[$method_id];

            return;
        }

        self::$have_registered[$method_id] = true;

        self::$declaring_methods[$method_id] = $method_id;
        self::$static_methods[$method_id] = $method->isStatic();

        self::$method_namespaces[$method_id] = $this->namespace;
        self::$method_files[$method_id] = $this->file_name;
        self::$existing_methods[$method_id] = 1;

        if ($method->isPrivate()) {
            self::$method_visibility[$method_id] = self::VISIBILITY_PRIVATE;
        }
        elseif ($method->isProtected()) {
            self::$method_visibility[$method_id] = self::VISIBILITY_PROTECTED;
        }
        else {
            self::$method_visibility[$method_id] = self::VISIBILITY_PUBLIC;
        }

        self::$method_params[$method_id] = [];

        $method_param_names = [];

        foreach ($method->getParams() as $param) {
            $param_array = $this->getParamArray($param);
            self::$method_params[$method_id][] = $param_array;
            $method_param_names[$param->name] = $param_array['type'];
        }

        $config = Config::getInstance();
        $return_type = null;

        $docblock_info = CommentChecker::extractDocblockInfo($method->getDocComment());

        if ($docblock_info['deprecated']) {
            self::$deprecated_methods[$method_id] = true;
        }

        $this->suppressed_issues = $docblock_info['suppress'];
        self::$method_suppress[$method_id] = $this->suppressed_issues;

        if ($config->use_docblock_types) {
            if ($docblock_info['return_type']) {

                $return_type =
                    Type::parseString(
                        $this->fixUpLocalType(
                            $docblock_info['return_type'],
                            $this->absolute_class,
                            $this->namespace,
                            $this->getAliasedClasses()
                        )
                    );
            }

            if ($docblock_info['params']) {
                $this->improveParamsFromDocblock(
                    $docblock_info['params'],
                    $method_param_names,
                    self::$method_params[$method_id],
                    $method->getLine()
                );
            }
        }

        self::$method_return_types[$method_id] = $return_type;
    }

    protected static function fixUpReturnType($return_type, $method_id)
    {
        if (strpos($return_type, '[') !== false) {
            $return_type = Type::convertSquareBrackets($return_type);
        }

        $return_type_tokens = Type::tokenize($return_type);

        foreach ($return_type_tokens as &$return_type_token) {
            if ($return_type_token[0] === '\\') {
                $return_type_token = substr($return_type_token, 1);
                continue;
            }

            if (in_array($return_type_token, ['<', '>', '|'])) {
                continue;
            }

            $return_type_token = Type::fixScalarTerms($return_type_token);

            if ($return_type_token[0] === strtoupper($return_type_token[0])) {
                $absolute_class = explode('::', $method_id)[0];

                if ($return_type_token === '$this') {
                    $return_type_token = $absolute_class;
                    continue;
                }

                $return_type_token = FileChecker::getAbsoluteClassFromNameInFile($return_type_token, self::$method_namespaces[$method_id], self::$method_files[$method_id]);
            }
        }

        return implode('', $return_type_tokens);
    }

    /**
     * @return bool|null
     */
    public static function checkMethodExists($method_id, $file_name, $line_number, array $suppresssed_issues)
    {
        // remove trailing backslash if it exists
        $method_id = preg_replace('/^\\\\/', '', $method_id);

        $cased_method_id = $method_id;
        $method_parts = explode('::', $method_id);
        $method_id = $method_parts[0] . '::' . strtolower($method_parts[1]);

        self::registerClassMethod($method_id);

        if (isset(self::$declaring_methods[$method_id])) {
            return true;
        }

        if (IssueBuffer::accepts(
            new UndefinedMethod('Method ' . $cased_method_id . ' does not exist', $file_name, $line_number),
            $suppresssed_issues
        )) {
            return false;
        }
    }

    public static function registerClassMethod($method_id)
    {
        ClassLikeChecker::registerClass(explode('::', $method_id)[0]);
    }

    public static function checkMethodNotDeprecated($method_id, $file_name, $line_number, array $suppresssed_issues)
    {
        self::registerClassMethod($method_id);

        if (isset(self::$deprecated_methods[$method_id])) {
            if (IssueBuffer::accepts(
                new DeprecatedMethod('The method ' . MethodChecker::getCasedMethodId($method_id) . ' has been marked as deprecated', $file_name, $line_number),
                $suppresssed_issues
            )) {
                return false;
            }
        }
    }

    /**
     * @param  string           $method_id
     * @param  string           $calling_context
     * @param  StatementsSource $source
     * @param  int              $line_number
     * @param  array            $suppresssed_issues
     * @return false|null
     */
    public static function checkMethodVisibility($method_id, $calling_context, StatementsSource $source, $line_number, array $suppresssed_issues)
    {
        self::registerClassMethod($method_id);

        $declared_method_id = self::getDeclaringMethod($method_id);

        $method_class = explode('::', $method_id)[0];
        $method_name = explode('::', $method_id)[1];

        if (!isset(self::$method_visibility[$declared_method_id])) {
            if (IssueBuffer::accepts(
                new InaccessibleMethod('Cannot access method ' . $method_id, $source->getFileName(), $line_number),
                $suppresssed_issues
            )) {
                return false;
            }
        }

        if ($source->getSource() instanceof TraitChecker && $method_class === $source->getAbsoluteClass()) {
            return;
        }

        switch (self::$method_visibility[$declared_method_id]) {
            case self::VISIBILITY_PUBLIC:
                return;

            case self::VISIBILITY_PRIVATE:
                if (!$calling_context || $method_class !== $calling_context) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access private method ' . MethodChecker::getCasedMethodId($method_id) . ' from context ' . $calling_context,
                            $source->getFileName(),
                            $line_number
                        ),
                        $suppresssed_issues
                    )) {
                        return false;
                    }
                }
                return;

            case self::VISIBILITY_PROTECTED:
                if ($method_class === $calling_context) {
                    return;
                }

                if (!$calling_context) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod('Cannot access protected method ' . $method_id, $source->getFileName(), $line_number),
                        $suppresssed_issues
                    )) {
                        return false;
                    }
                }

                if (ClassChecker::classExtends($method_class, $calling_context) && method_exists($calling_context, $method_name)) {
                    return;
                }

                if (!ClassChecker::classExtends($calling_context, $method_class)) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access protected method ' . MethodChecker::getCasedMethodId($method_id) . ' from context ' . $calling_context,
                            $source->getFileName(),
                            $line_number
                        ),
                        $suppresssed_issues
                    )) {
                        return false;
                    }
                }
        }
    }

    public static function setDeclaringMethod($method_id, $declaring_method_id)
    {
        self::$declaring_methods[$method_id] = $declaring_method_id;
    }

    public static function getDeclaringMethod($method_id)
    {
        return self::$declaring_methods[$method_id];
    }

    public static function getCasedMethodId($method_id)
    {
        $method_id = self::getDeclaringMethod($method_id);
        return self::$cased_method_ids[$method_id];
    }

    public static function clearCache()
    {
        self::$method_comments = [];
        self::$method_files = [];
        self::$method_params = [];
        self::$cased_method_ids = [];
        self::$method_namespaces = [];
        self::$method_return_types = [];
        self::$static_methods = [];
        self::$declaring_methods = [];
        self::$existing_methods = [];
        self::$have_reflected = [];
        self::$have_registered = [];
        self::$inherited_methods = [];
        self::$declaring_class = [];
        self::$method_visibility = [];
    }
}
