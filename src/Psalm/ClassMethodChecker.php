<?php

namespace Psalm;

use Psalm\Issue\UndefinedMethod;
use Psalm\Issue\InaccessibleMethod;
use Psalm\Issue\DeprecatedMethod;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidStaticInvocation;
use PhpParser;

class ClassMethodChecker extends FunctionChecker
{
    protected static $_method_comments = [];
    protected static $_method_files = [];
    protected static $_method_params = [];
    protected static $_method_namespaces = [];
    protected static $_method_return_types = [];
    protected static $_static_methods = [];
    protected static $_declaring_classes = [];
    protected static $_existing_methods = [];
    protected static $_have_reflected = [];
    protected static $_have_registered = [];
    protected static $_inherited_methods = [];
    protected static $_declaring_class = [];
    protected static $_method_visibility = [];
    protected static $_method_suppress = [];
    protected static $_deprecated_methods = [];

    const VISIBILITY_PUBLIC = 1;
    const VISIBILITY_PROTECTED = 2;
    const VISIBILITY_PRIVATE = 3;

    public function __construct(PhpParser\Node\FunctionLike $function, StatementsSource $source, array $this_vars = [])
    {
        parent::__construct($function, $source);

        if ($function instanceof PhpParser\Node\Stmt\ClassMethod) {
            $this->_registerMethod($function);
            $this->_is_static = $function->isStatic();
        }
    }

    public static function getMethodParams($method_id)
    {
        self::_populateData($method_id);

        return self::$_method_params[$method_id];
    }

    public static function getMethodReturnTypes($method_id)
    {
        self::_populateData($method_id);

        return self::$_method_return_types[$method_id] ? clone self::$_method_return_types[$method_id] : null;
    }

    /**
     * @return void
     */
    public static function extractReflectionMethodInfo($method_id)
    {
        if (isset(self::$_have_reflected[$method_id]) || isset(self::$_have_registered[$method_id])) {
            return;
        }

        try {
            $method = new \ReflectionMethod($method_id);
        }
        catch (\ReflectionException $e) {
            // maybe it's an old-timey constructor

            $absolute_class = explode('::', $method_id)[0];
            $class_name = array_pop(explode('\\', $absolute_class));

            $alt_method_id = $absolute_class . '::' . $class_name;

            $method = new \ReflectionMethod($alt_method_id);
        }

        self::$_have_reflected[$method_id] = true;

        self::$_static_methods[$method_id] = $method->isStatic();
        self::$_method_files[$method_id] = $method->getFileName();
        self::$_method_namespaces[$method_id] = $method->getDeclaringClass()->getNamespaceName();
        self::$_declaring_classes[$method_id] = $method->getDeclaringClass()->name . '::' . $method->getName();
        self::$_method_visibility[$method_id] = $method->isPrivate() ?
                                                    self::VISIBILITY_PRIVATE :
                                                    ($method->isProtected() ? self::VISIBILITY_PROTECTED : self::VISIBILITY_PUBLIC);


        $params = $method->getParameters();

        $method_param_names = [];
        $method_param_types = [];

        self::$_method_params[$method_id] = [];
        foreach ($params as $param) {
            $param_type_string = null;

            if ($param->isArray()) {
                $param_type_string = 'array';

            }
            else {
                $param_class = null;

                try {
                    $param_class = $param->getClass();
                }
                catch (\ReflectionException $e) {
                    // do nothing
                }

                if ($param_class && self::$_method_files[$method_id]) {
                    $param_type_string = $param->getClass()->getName();
                }
            }

            $is_nullable = false;

            $is_optional = $param->isOptional();

            try {
                $is_nullable = $param->getDefaultValue() === null;

                if ($param_type_string && $is_nullable) {
                    $param_type_string .= '|null';
                }
            }
            catch (\ReflectionException $e) {
                // do nothing
            }

            $param_name = $param->getName();
            $param_type = $param_type_string ? Type::parseString($param_type_string) : Type::getMixed();

            $method_param_names[$param_name] = true;
            $method_param_types[$param_name] = $param_type;

            self::$_method_params[$method_id][] = [
                'name' => $param_name,
                'by_ref' => $param->isPassedByReference(),
                'type' => $param_type,
                'is_nullable' => $is_nullable,
                'is_optional' => $is_optional,
            ];
        }

        $return_types = null;

        $config = Config::getInstance();

        $return_type = null;

        $docblock_info = CommentChecker::extractDocblockInfo($method->getDocComment());

        if ($docblock_info['deprecated']) {
            self::$_deprecated_methods[$method_id] = true;
        }

        self::$_method_return_types[$method_id] = [];
        self::$_method_suppress[$method_id] = $docblock_info['suppress'];

        if ($config->use_docblock_types) {
            if ($docblock_info['return_type']) {

                $return_type = Type::parseString(
                    self::_fixUpReturnType($docblock_info['return_type'], $method_id)
                );
            }

            if ($docblock_info['params']) {
                foreach ($docblock_info['params'] as $docblock_param) {
                    $docblock_param_name = $docblock_param['name'];

                    if (isset($method_param_names[$docblock_param_name])) {
                        foreach (self::$_method_params[$method_id] as &$param_info) {
                            if ($param_info['name'] === $docblock_param_name) {
                                $docblock_param_type_string = $docblock_param['type'];

                                $existing_param_type = $param_info['type'];

                                $new_param_type = Type::parseString(
                                    self::_fixUpReturnType($docblock_param_type_string, $method_id)
                                );

                                // only fix the type if we're dealing with an undefined or generic type
                                if ($existing_param_type->isMixed() || $new_param_type->hasGeneric()) {
                                    $existing_param_type_nullable = $param_info['is_nullable'];

                                    if ($existing_param_type_nullable && !$new_param_type->isNullable()) {
                                        $new_param_type->types['null'] = Type::getNull(false);
                                    }

                                    $param_info['type'] = $new_param_type;
                                }

                            }
                        }
                    }
                }
            }
        }

        self::$_method_return_types[$method_id] = $return_type;
    }

    protected static function _copyToChildMethod($method_id, $child_method_id)
    {
        if (!isset(self::$_have_registered[$method_id]) && !isset(self::$_have_reflected[$method_id])) {
            self::extractReflectionMethodInfo($method_id);
        }

        if (self::$_method_visibility[$method_id] !== self::VISIBILITY_PRIVATE) {
            self::$_method_files[$child_method_id] = self::$_method_files[$method_id];
            self::$_method_params[$child_method_id] = self::$_method_params[$method_id];
            self::$_method_namespaces[$child_method_id] = self::$_method_namespaces[$method_id];
            self::$_method_return_types[$child_method_id] = self::$_method_return_types[$method_id];
            self::$_static_methods[$child_method_id] = self::$_static_methods[$method_id];
            self::$_method_visibility[$child_method_id] = self::$_method_visibility[$method_id];

            self::$_declaring_classes[$child_method_id] = self::$_declaring_classes[$method_id];
            self::$_existing_methods[$child_method_id] = 1;
        }
    }

    /**
     * Determines whether a given method is static or not
     * @param  string  $method_id
     */
    public static function checkMethodStatic($method_id, $file_name, $line_number, array $suppressed_issues)
    {
        self::_populateData($method_id);

        if (!self::$_static_methods[$method_id]) {
            if (IssueBuffer::accepts(
                new InvalidStaticInvocation('Method ' . $method_id . ' is not static', $file_name, $line_number),
                $suppressed_issues
            )) {
                return false;
            }
        }
    }

    protected function _registerMethod(PhpParser\Node\Stmt\ClassMethod $method)
    {
        $method_id = $this->_absolute_class . '::' . $method->name;

        if (isset(self::$_have_reflected[$method_id]) || isset(self::$_have_registered[$method_id])) {
            $this->_suppressed_issues = self::$_method_suppress[$method_id];

            return;
        }

        self::$_have_registered[$method_id] = true;

        self::$_declaring_classes[$method_id] = $method_id;
        self::$_static_methods[$method_id] = $method->isStatic();

        self::$_method_namespaces[$method_id] = $this->_namespace;
        self::$_method_files[$method_id] = $this->_file_name;
        self::$_existing_methods[$method_id] = 1;

        if ($method->isPrivate()) {
            self::$_method_visibility[$method_id] = self::VISIBILITY_PRIVATE;
        }
        elseif ($method->isProtected()) {
            self::$_method_visibility[$method_id] = self::VISIBILITY_PROTECTED;
        }
        else {
            self::$_method_visibility[$method_id] = self::VISIBILITY_PUBLIC;
        }

        self::$_method_params[$method_id] = [];

        $method_param_names = [];

        foreach ($method->getParams() as $param) {
            $param_array = $this->getParamArray($param);
            self::$_method_params[$method_id][] = $param_array;
            $method_param_names[$param->name] = $param_array['type'];
        }

        $config = Config::getInstance();
        $return_type = null;

        $docblock_info = CommentChecker::extractDocblockInfo($method->getDocComment());

        if ($docblock_info['deprecated']) {
            self::$_deprecated_methods[$method_id] = true;
        }

        $this->_suppressed_issues = $docblock_info['suppress'];
        self::$_method_suppress[$method_id] = $this->_suppressed_issues;

        if ($config->use_docblock_types) {
            if ($docblock_info['return_type']) {
                $return_type =
                    Type::parseString(
                        $this->fixUpLocalType(
                            $docblock_info['return_type'],
                            $this->_absolute_class,
                            $this->_namespace,
                            $this->_aliased_classes
                        )
                    );
            }

            if ($docblock_info['params']) {
                $this->improveParamsFromDocblock(
                    $docblock_info['params'],
                    $method_param_names,
                    self::$_method_params[$method_id],
                    $method->getLine()
                );
            }
        }

        self::$_method_return_types[$method_id] = $return_type;
    }

    protected static function _fixUpReturnType($return_type, $method_id)
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

                $return_type_token = FileChecker::getAbsoluteClassFromNameInFile($return_type_token, self::$_method_namespaces[$method_id], self::$_method_files[$method_id]);
            }
        }

        return implode('', $return_type_tokens);
    }

    /**
     * @return bool|null
     */
    public static function checkMethodExists($method_id, $file_name, $line_number, array $suppresssed_issues)
    {
        if (isset(self::$_existing_methods[$method_id])) {
            return true;
        }

        $method_parts = explode('::', $method_id);

        if (method_exists($method_parts[0], $method_parts[1])) {
            self::$_existing_methods[$method_id] = 1;
            return true;
        }

        if (isset(self::$_have_registered[$method_id])) {
            self::$_existing_methods[$method_id] = 1;
            return true;
        }

        if (IssueBuffer::accepts(
            new UndefinedMethod('Method ' . $method_id . ' does not exist', $file_name, $line_number),
            $suppresssed_issues
        )) {
            return false;
        }
    }

    protected static function _populateData($method_id)
    {
        if (!isset(self::$_have_reflected[$method_id]) && !isset(self::$_have_registered[$method_id])) {
            if (isset(self::$_inherited_methods[$method_id])) {
                self::_copyToChildMethod(self::$_inherited_methods[$method_id], $method_id);
            }
            else {
                self::extractReflectionMethodInfo($method_id);
            }
        }
    }

    public static function checkMethodNotDeprecated($method_id, $file_name, $line_number, array $suppresssed_issues)
    {
        self::_populateData($method_id);

        if (isset(self::$_deprecated_methods[$method_id])) {
            if (IssueBuffer::accepts(
                new DeprecatedMethod('The method ' . $method_id . ' has been marked as deprecated', $file_name, $line_number),
                $suppresssed_issues
            )) {
                return false;
            }
        }
    }

    /**
     * @param  string           $method_id
     * @param  string           $calling_context
     * @param  StatementsSource $file_name
     * @param  int              $line_number
     * @param  array            $suppresssed_issues
     * @return false|null
     */
    public static function checkMethodVisibility($method_id, $calling_context, StatementsSource $source, $line_number, array $suppresssed_issues)
    {
        self::_populateData($method_id);

        $method_class = explode('::', $method_id)[0];
        $method_name = explode('::', $method_id)[1];

        if (!isset(self::$_method_visibility[$method_id])) {
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

        switch (self::$_method_visibility[$method_id]) {
            case self::VISIBILITY_PUBLIC:
                return;

            case self::VISIBILITY_PRIVATE:
                if (!$calling_context || $method_class !== $calling_context) {
                    if (IssueBuffer::accepts(
                        new InaccessibleMethod(
                            'Cannot access private method ' . $method_id . ' from context ' . $calling_context,
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
                            'Cannot access protected method ' . $method_id . ' from context ' . $calling_context,
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

    public static function registerInheritedMethod($parent_method_id, $method_id)
    {
        // only register the method if it's not already there
        if (!isset(self::$_declaring_classes[$method_id])) {
            self::$_declaring_classes[$method_id] = $parent_method_id;
        }

        self::$_inherited_methods[$method_id] = $parent_method_id;
    }

    public static function getDeclaringMethod($method_id)
    {
        if (isset(self::$_declaring_classes[$method_id])) {
            return self::$_declaring_classes[$method_id];
        }

        $method_name = explode('::', $method_id)[1];

        $parent_method_id = (new \ReflectionMethod($method_id))->getDeclaringClass()->getName() . '::' . $method_name;

        self::$_declaring_classes[$method_id] = $parent_method_id;

        return $parent_method_id;
    }

    public static function getNewDocblocksForFile($file_name)
    {
        return isset(self::$_new_docblocks[$file_name]) ? self::$_new_docblocks[$file_name] : [];
    }

    public static function clearCache()
    {
        self::$_method_comments = [];
        self::$_method_files = [];
        self::$_method_params = [];
        self::$_method_namespaces = [];
        self::$_method_return_types = [];
        self::$_static_methods = [];
        self::$_declaring_classes = [];
        self::$_existing_methods = [];
        self::$_have_reflected = [];
        self::$_have_registered = [];
        self::$_inherited_methods = [];
        self::$_declaring_class = [];
        self::$_method_visibility = [];
        self::$_new_docblocks = [];
    }
}
