<?php

namespace CodeInspector;

ini_set('xdebug.max_nesting_level', 512);

use PhpParser;

class FunctionChecker implements StatementsSource
{
    protected $_function;
    protected $_aliased_classes = [];
    protected $_namespace;
    protected $_file_name;
    protected $_is_static = false;
    protected $_absolute_class;
    protected $_statements_checker;
    protected $_source;
    protected $_return_vars_in_scope = [];
    protected $_return_vars_possibly_in_scope = [];

    protected static $_no_effects_hashes = [];

    protected $_function_params = [];

    public function __construct(PhpParser\Node\FunctionLike $function, StatementsSource $source)
    {
        $this->_function = $function;
        $this->_aliased_classes = $source->getAliasedClasses();
        $this->_namespace = $source->getNamespace();
        $this->_class_name = $source->getClassName();
        $this->_class_extends = $source->getParentClass();
        $this->_file_name = $source->getFileName();
        $this->_absolute_class = $source->getAbsoluteClass();
        $this->_source = $source;
    }

    public function check(&$vars_in_scope = [], &$vars_possibly_in_scope = [])
    {
        if ($this->_function->stmts) {
            if (ClassChecker::getThisClass() && $this instanceof ClassMethodChecker) {
                $hash = $this->getMethodId() . json_encode([$vars_in_scope, $vars_possibly_in_scope]);

                // if we know that the function has no effects on vars, we don't bother checking
                if (isset(self::$_no_effects_hashes[$hash])) {
                    list($vars_in_scope, $vars_possibly_in_scope) = self::$_no_effects_hashes[$hash];

                    return;
                }
            }

            $statements_checker = new StatementsChecker($this, !empty($this->_function->params));

            foreach ($this->_function->params as $param) {
                if ($param->type) {
                    if (is_object($param->type)) {
                        if (!in_array($param->type->parts[0], ['self', 'parent'])) {
                            ClassChecker::checkClassName($param->type, $this->_namespace, $this->_aliased_classes, $this->_file_name);
                        }
                    }
                }

                $is_nullable = $param->default !== null &&
                                $param->default instanceof \PhpParser\Node\Expr\ConstFetch &&
                                $param->default->name instanceof PhpParser\Node\Name &&
                                $param->default->name->parts = ['null'];

                if ($param->type && is_object($param->type)) {
                    $vars_in_scope[$param->name] =
                        $param->type->parts === ['self'] ?
                            $this->_absolute_class :
                            ClassChecker::getAbsoluteClassFromName($param->type, $this->_namespace, $this->_aliased_classes);

                    if ($is_nullable) {
                        $vars_in_scope[$param->name] .= '|null';
                    }
                }
                else {
                    $vars_in_scope[$param->name] = 'mixed';
                }

                $vars_possibly_in_scope[$param->name] = true;
                $statements_checker->registerVariable($param->name, $param->getLine());
            }

            $statements_checker->check($this->_function->stmts, $vars_in_scope, $vars_possibly_in_scope);

            if (isset($this->_return_vars_in_scope[''])) {
                $vars_in_scope = TypeChecker::combineTypes($vars_in_scope, $this->_return_vars_in_scope['']);
            }

            if (isset($this->_return_vars_possibly_in_scope[''])) {
                $vars_possibly_in_scope = TypeChecker::combineTypes($vars_possibly_in_scope, $this->_return_vars_possibly_in_scope['']);
            }

            foreach ($vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($vars_in_scope[$var]);
                }
            }

            foreach ($vars_possibly_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($vars_possibly_in_scope[$var]);
                }
            }

            if (ClassChecker::getThisClass() && $this instanceof ClassMethodChecker) {
                self::$_no_effects_hashes[$hash] = [$vars_in_scope, $vars_possibly_in_scope];
            }
        }
    }

    public function addReturnTypes($return_type, $vars_in_scope, $vars_possibly_in_scope)
    {
        if (isset($this->_return_vars_in_scope[$return_type])) {
            $this->_return_vars_in_scope[$return_type] = TypeChecker::combineTypes($vars_in_scope, $this->_return_vars_in_scope[$return_type]);
        }
        else {
            $this->_return_vars_in_scope[$return_type] = $vars_in_scope;
        }

        if (isset($this->_return_vars_possibly_in_scope[$return_type])) {
            $this->_return_vars_possibly_in_scope[$return_type] = TypeChecker::combineTypes($vars_possibly_in_scope, $this->_return_vars_possibly_in_scope[$return_type]);
        }
        else {
            $this->_return_vars_possibly_in_scope[$return_type] = $vars_possibly_in_scope;
        }
    }

    /**
     * @return string
     */
    public function getMethodId()
    {
        if ($this->_function instanceof PhpParser\Node\Expr\Closure) {
            return null;
        }

        return $this->getAbsoluteClass() . '::' . $this->_function->name;
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
        return $this->_class_name;
    }

    public function getClassChecker()
    {
        return $this->_source->getClassChecker();
    }

    public function getParentClass()
    {
        return $this->_class_extends;
    }

    public function getFileName()
    {
        return $this->_file_name;
    }

    public function isStatic()
    {
        return $this->_is_static;
    }

    public function getSource()
    {
        return $this->_source;
    }
}
