<?php

namespace CodeInspector;

use PhpParser;

use CodeInspector\Exception\ForbiddenCodeException;
use CodeInspector\Exception\InvalidArgumentException;
use CodeInspector\Exception\InvalidNamespaceException;
use CodeInspector\Exception\IteratorException;
use CodeInspector\Exception\ParentNotFoundException;
use CodeInspector\Exception\ScopeException;
use CodeInspector\Exception\StaticInvocationException;
use CodeInspector\Exception\StaticVariableException;
use CodeInspector\Exception\TypeResolutionException;
use CodeInspector\Exception\UndefinedConstantException;
use CodeInspector\Exception\UndefinedFunctionException;
use CodeInspector\Exception\UndefinedPropertyException;
use CodeInspector\Exception\UndefinedVariableException;

class StatementsChecker
{
    protected $_stmts;

    protected $_source;
    protected $_all_vars = [];
    protected $_warn_vars = [];
    protected $_check_classes = true;
    protected $_check_variables = true;
    protected $_check_methods = true;
    protected $_check_consts = true;
    protected $_check_functions = true;
    protected $_class_name;
    protected $_class_extends;

    protected $_namespace;
    protected $_aliased_classes;
    protected $_file_name;
    protected $_is_static;
    protected $_absolute_class;
    protected $_type_checker;

    protected $_available_functions = [];

    protected $_require_file_name = null;

    protected static $_method_call_index = [];
    protected static $_existing_functions = [];
    protected static $_reflection_functions = [];
    protected static $_this_assignments = [];
    protected static $_this_calls = [];

    protected static $_existing_static_vars = [];
    protected static $_existing_properties = [];
    protected static $_check_string_fn = null;
    protected static $_mock_interfaces = [];

    public function __construct(StatementsSource $source, $enforce_variable_checks = false)
    {
        $this->_source = $source;
        $this->_check_classes = true;
        $this->_check_methods = true;

        $this->_check_consts = true;

        $this->_file_name = $this->_source->getFileName();
        $this->_aliased_classes = $this->_source->getAliasedClasses();
        $this->_namespace = $this->_source->getNamespace();
        $this->_is_static = $this->_source->isStatic();
        $this->_absolute_class = $this->_source->getAbsoluteClass();
        $this->_class_name = $this->_source->getClassName();
        $this->_class_extends = $this->_source->getParentClass();

        $this->_check_variables = FileChecker::shouldCheckVariables($this->_file_name) || $enforce_variable_checks;
        $this->_check_nulls = FileChecker::shouldCheckNulls($this->_file_name);

        $this->_type_checker = new TypeChecker($source, $this);
    }

    public function check(array $stmts, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope = [])
    {
        $has_returned = false;

        // register all functions first
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);
                $file_checker->registerFunction($stmt);
            }
        }

        foreach ($stmts as $stmt) {
            if ($has_returned && !($stmt instanceof PhpParser\Node\Stmt\Nop) && !($stmt instanceof PhpParser\Node\Stmt\InlineHTML)) {
                echo('Warning: Expressions after return/throw/continue in ' . $this->_file_name . ' on line ' . $stmt->getLine() . PHP_EOL);
                break;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $this->_checkIf($stmt, $vars_in_scope, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $this->_checkTryCatch($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $this->_checkFor($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                $this->_checkForeach($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $this->_checkWhile($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $this->_checkDo($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    $this->_checkExpression($const->value, $vars_in_scope, $vars_possibly_in_scope);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Unset_) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $has_returned = true;
                $this->_checkReturn($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                $has_returned = true;
                $this->_checkThrow($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $this->_checkSwitch($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                $has_returned = true;

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Static_) {
                $this->_checkStatic($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Echo_) {
                foreach ($stmt->exprs as $expr) {
                    $this->_checkExpression($expr, $vars_in_scope, $vars_possibly_in_scope);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $function_checker = new FunctionChecker($stmt, $this->_source);
                $function_checker->check();

            } elseif ($stmt instanceof PhpParser\Node\Expr) {
                $this->_checkExpression($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\InlineHTML) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $this->_aliased_classes[$use->alias] = implode('\\', $use->name->parts);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Global_) {
                foreach ($stmt->vars as $var) {
                    if ($var instanceof PhpParser\Node\Expr\Variable) {
                        if (is_string($var->name)) {
                            $vars_in_scope[$var->name] = 'mixed';
                            $vars_possibly_in_scope[$var->name] = true;
                        } else {
                            $this->_checkExpression($var, $vars_in_scope, $vars_possibly_in_scope);
                        }
                    }
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->default) {
                        $this->_checkExpression($prop->default, $vars_in_scope, $vars_possibly_in_scope);
                    }

                    self::$_existing_static_vars[$this->_absolute_class . '::$' . $prop->name] = 1;
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {


            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                (new ClassChecker($stmt, $this->_source, $stmt->name))->check();

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                if ($this->_namespace) {
                    throw new InvalidNamespaceException('Cannot redeclare namespace', $this->_require_file_name, $stmt->getLine());
                }

                $namespace_checker = new NamespaceChecker($stmt, $this->_source);
                $namespace_checker->check(true);
            } else {
                var_dump('Unrecognised statement in ' . $this->_file_name);
                var_dump($stmt);
            }
        }
    }

    protected function _checkIf(PhpParser\Node\Stmt\If_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        $this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope);

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        $can_negate_if_types = !($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd);

        $negated_types = $if_types && $can_negate_if_types ? TypeChecker::negateTypes($if_types) : [];
        $negated_if_types = $negated_types;

        // if the if has an or as the main component, we cannot safely reason about it
        if ($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp && self::_containsBooleanOr($stmt->cond)) {
            $if_vars = array_merge([], $vars_in_scope);
            $if_vars_possibly_in_scope = array_merge([], $vars_possibly_in_scope);
        }
        else {
            $if_vars = TypeChecker::reconcileTypes($if_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
            $if_vars_possibly_in_scope = TypeChecker::reconcileTypes($if_types, $vars_possibly_in_scope, false, $this->_file_name, $stmt->getLine());
        }

        $old_if_vars = $if_vars;

        $this->check($stmt->stmts, $if_vars, $if_vars_possibly_in_scope, $for_vars_possibly_in_scope);

        $new_vars = null;
        $new_vars_possibly_in_scope = [];
        $redefined_vars = null;
        $possibly_redefined_vars = null;
        $has_left = false;
        $post_type_assertions = [];

        if (count($stmt->stmts)) {
            $has_leaving_statments = ScopeChecker::doesLeaveBlock($stmt->stmts, true);

            if (!$has_leaving_statments) {
                $new_vars = array_diff_key($if_vars, $vars_in_scope);

                $redefined_vars = [];

                foreach ($old_if_vars as $if_var => $type) {
                    if ($if_vars[$if_var] !== $type) {
                        $redefined_vars[$if_var] = $if_vars[$if_var];
                    }
                }

                $possibly_redefined_vars = $redefined_vars;

                foreach ($redefined_vars as $redefined_var => $type) {
                    if (isset($if_types[$redefined_var]) && TypeChecker::isNegation($redefined_var, $if_types[$redefined_var])) {
                        $post_type_assertions[$redefined_var] = $type;
                    }
                }
            }
            else {
                $has_left = true;
                $post_type_assertions = $negated_types;
            }

            $has_ending_statments = ScopeChecker::doesLeaveBlock($stmt->stmts, false);

            if (!$has_ending_statments) {
                $vars = array_diff_key($if_vars_possibly_in_scope, $vars_possibly_in_scope);

                // if we're leaving this block, add vars to outer for loop scope
                if ($has_leaving_statments) {
                    $for_vars_possibly_in_scope = array_merge($for_vars_possibly_in_scope, $vars);
                }
                else {
                    $new_vars_possibly_in_scope = $vars;
                }
            }
        }

        foreach ($stmt->elseifs as $elseif) {
            if ($negated_types) {
                $elseif_vars = TypeChecker::reconcileTypes($negated_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
            }
            else {
                $elseif_vars = array_merge([], $vars_in_scope);
            }

            $old_elseif_vars = $elseif_vars;

            $elseif_vars_possibly_in_scope = array_merge([], $vars_possibly_in_scope);

            $elseif_types = $this->_type_checker->getTypeAssertions($elseif->cond, true);

            if (!($elseif->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd)) {
                $negated_types = array_merge($negated_types, TypeChecker::negateTypes($elseif_types));
            }
            else {
                $elseif_vars = TypeChecker::reconcileTypes($elseif_types, $elseif_vars, true, $this->_file_name, $stmt->getLine());
            }

            $this->_checkElseIf($elseif, $elseif_vars, $elseif_vars_possibly_in_scope, $for_vars_possibly_in_scope);

            if (count($elseif->stmts)) {
                $has_leaving_statements = ScopeChecker::doesLeaveBlock($elseif->stmts, true);

                if (!$has_leaving_statements) {
                    $elseif_redefined_vars = [];

                    foreach ($old_elseif_vars as $elseif_var => $type) {
                        if ($elseif_vars[$elseif_var] !== $type) {
                            $elseif_redefined_vars[$elseif_var] = $elseif_vars[$elseif_var];
                        }
                    }

                    if ($redefined_vars === null) {
                        $redefined_vars = $elseif_redefined_vars;
                        $possibly_redefined_vars = $redefined_vars;

                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (isset($elseif_types[$redefined_var]) && TypeChecker::isNegation($redefined_var, $if_types[$redefined_var])) {
                                $post_type_assertions[$redefined_var] = $type;
                            }
                        }
                    }
                    else {
                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (!isset($elseif_redefined_vars[$redefined_var])) {
                                unset($redefined_vars[$redefined_var]);
                            }
                        }

                        foreach ($elseif_redefined_vars as $var => $type) {
                            if ($type === 'mixed') {
                                $possibly_redefined_vars[$var] = 'mixed';
                            }
                            else if (isset($possibly_redefined_vars[$var])) {
                                $possibly_redefined_vars[$var] = $type . '|' . $possibly_redefined_vars[$var];
                            }
                            else {
                                $possibly_redefined_vars[$var] = $type;
                            }
                        }
                    }

                    if ($new_vars === null) {
                        $new_vars = array_diff_key($elseif_vars, $vars_in_scope);
                    }
                    else {
                        foreach ($new_vars as $new_var => $type) {
                            if (!isset($elseif_vars[$new_var])) {
                                unset($new_vars[$new_var]);
                            }
                        }
                    }
                }
                else {
                    $post_type_assertions = $negated_types;
                }

                // has a return/throw at end
                $has_ending_statments = ScopeChecker::doesLeaveBlock($elseif->stmts, false);

                if (!$has_ending_statments) {
                    $vars = array_diff_key($elseif_vars_possibly_in_scope, $vars_possibly_in_scope);

                    // if we're leaving this block, add vars to outer for loop scope
                    if ($has_leaving_statements) {
                        $for_vars_possibly_in_scope = array_merge($vars, $for_vars_possibly_in_scope);
                    }
                    else {
                        $new_vars_possibly_in_scope = array_merge($vars, $new_vars_possibly_in_scope);
                    }
                }
            }
        }

        if ($stmt->else) {
            if ($negated_types) {
                $else_vars = TypeChecker::reconcileTypes($negated_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
            }
            else {
                $else_vars = array_merge([], $vars_in_scope);
            }

            $old_else_vars = $else_vars;

            $else_vars_possibly_in_scope = array_merge([], $vars_possibly_in_scope);

            $this->_checkElse($stmt->else, $else_vars, $else_vars_possibly_in_scope, $for_vars_possibly_in_scope);

            if (count($stmt->else->stmts)) {
                $has_leaving_statements = ScopeChecker::doesLeaveBlock($stmt->else->stmts, true);

                // if it doesn't end in a return
                if (!$has_leaving_statements) {
                    $else_redefined_vars = [];

                    foreach ($old_else_vars as $else_var => $type) {
                        if ($else_vars[$else_var] !== $type) {
                            $else_redefined_vars[$else_var] = $else_vars[$else_var];
                        }
                    }

                    if ($redefined_vars === null) {
                        $redefined_vars = $else_redefined_vars;
                        $possibly_redefined_vars = $redefined_vars;
                    }
                    else {
                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (!isset($else_redefined_vars[$redefined_var])) {
                                unset($redefined_vars[$redefined_var]);
                            }
                        }

                        foreach ($else_redefined_vars as $var => $type) {
                            if ($type === 'mixed') {
                                $possibly_redefined_vars[$var] = 'mixed';
                            }
                            else if (isset($possibly_redefined_vars[$var])) {
                                $possibly_redefined_vars[$var] = $type . '|' . $possibly_redefined_vars[$var];
                            }
                            else {
                                $possibly_redefined_vars[$var] = $type;
                            }
                        }
                    }

                    if ($new_vars === null) {
                        $new_vars = array_diff_key($else_vars, $vars_in_scope);
                    }
                    else {
                        foreach ($new_vars as $new_var => $type) {
                            if (!isset($else_vars[$new_var])) {
                                unset($new_vars[$new_var]);
                            }
                        }
                    }
                }

                // has a return/throw at end
                $has_ending_statments = ScopeChecker::doesLeaveBlock($stmt->else->stmts, false);

                if (!$has_ending_statments) {
                    $vars = array_diff_key($else_vars_possibly_in_scope, $vars_possibly_in_scope);

                    if ($has_leaving_statements) {
                        $for_vars_possibly_in_scope = array_merge($vars, $for_vars_possibly_in_scope);
                    }
                    else {
                        $new_vars_possibly_in_scope = array_merge($vars, $new_vars_possibly_in_scope);
                    }
                }

                if ($new_vars) {
                    // only update vars if there is an else
                    $vars_in_scope = array_merge($vars_in_scope, $new_vars);
                }

                if ($redefined_vars) {
                    $vars_in_scope = array_merge($vars_in_scope, $redefined_vars);
                    $redefined_vars = null;
                }
            }
        }

        $vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $new_vars_possibly_in_scope);

        if ($if_types) {
            /**
             * let's get the type assertions from the condition if it's a terminator
             * so that we can negate them going forward
             */
            if (ScopeChecker::doesLeaveBlock($stmt->stmts, false) && $negated_if_types) {
                $vars_in_scope = TypeChecker::reconcileTypes($negated_if_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
                $vars_possibly_in_scope = TypeChecker::reconcileTypes($negated_if_types, $vars_possibly_in_scope, false, $this->_file_name, $stmt->getLine());
            }
            elseif ($redefined_vars) {
                foreach ($if_types as $var => $type) {
                    if (in_array($type, ['empty', 'null'])) {
                        if (isset($redefined_vars[$var])) {
                            $vars_in_scope[$var] = $redefined_vars[$var];
                            unset($redefined_vars[$var]);
                        }
                    }
                    elseif ($type === '!array' && isset($redefined_vars[$var]) && $redefined_vars[$var] === 'array') {
                        $vars_in_scope[$var] = $redefined_vars[$var];
                        unset($redefined_vars[$var]);
                    }
                }
            }
        }

        if ($possibly_redefined_vars) {
            foreach ($possibly_redefined_vars as $var => $type) {
                if (isset($vars_in_scope[$var])) {
                    if ($vars_in_scope[$var] !== 'mixed' && $type !== 'mixed') {
                        $existing_types = explode('|', $vars_in_scope[$var]);
                        $new_types = explode('|', $type);
                        $new_types = array_merge($new_types, $existing_types);
                        $new_types = array_unique($new_types);
                        $vars_in_scope[$var] = implode('|', $new_types);
                    }
                    else {
                        $vars_in_scope[$var] = 'mixed';
                    }
                }
            }
        }

        if ($post_type_assertions) {
            $vars_in_scope = TypeChecker::reconcileTypes($post_type_assertions, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
        }
    }

    protected function _checkElseIf(PhpParser\Node\Stmt\ElseIf_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        $this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope);

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond);

        $elseif_vars = TypeChecker::reconcileTypes($if_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());

        $this->check($stmt->stmts, $elseif_vars, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

        $vars_in_scope = $elseif_vars;
    }

    protected function _checkElse(PhpParser\Node\Stmt\Else_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        $this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope, $for_vars_possibly_in_scope);
    }

    protected function _checkCondition(PhpParser\Node\Expr $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkStatic(PhpParser\Node\Stmt\Static_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope = [])
    {
        foreach ($stmt->vars as $var) {
            if ($var instanceof PhpParser\Node\Stmt\StaticVar) {
                if (is_string($var->name)) {
                    if ($this->_check_variables) {
                        $vars_in_scope[$var->name] = 'mixed';
                        $vars_possibly_in_scope[$var->name] = true;
                        $this->registerVariable($var->name, $var->getLine());
                    }
                } else {
                    $this->_checkExpression($var->name, $vars_in_scope, $vars_possibly_in_scope);
                }

                if ($var->default) {
                    $this->_checkExpression($var->default, $vars_in_scope, $vars_possibly_in_scope);
                }
            } else {
                $this->_checkExpression($var, $vars_in_scope, $vars_possibly_in_scope);
            }
        }
    }

    /**
     * @return void
     */
    protected function _checkExpression(PhpParser\Node\Expr $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope = [])
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            $this->_checkVariable($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
            $this->_checkAssignment($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp) {
            $this->_checkAssignmentOperation($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\MethodCall) {
            $this->_checkMethodCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticCall) {
            $this->_checkStaticCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch) {
            $this->_checkConstFetch($stmt);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\String_) {
            if (self::$_check_string_fn) {
                call_user_func(self::$_check_string_fn, $stmt, $this->_file_name);
            }
            $stmt->returnType = 'string';

        } elseif ($stmt instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Scalar\LNumber) {
            $stmt->returnType = 'int';

        } elseif ($stmt instanceof PhpParser\Node\Scalar\DNumber) {
            $stmt->returnType = 'float';

        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryMinus) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryPlus) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($stmt->vars as $isset_var) {
                if ($isset_var instanceof PhpParser\Node\Expr\PropertyFetch &&
                    $isset_var->var instanceof PhpParser\Node\Expr\Variable &&
                    $isset_var->var->name === 'this' &&
                    is_string($isset_var->name)
                ) {
                    $property_id = 'this->' . $isset_var->name;
                    $vars_in_scope[$property_id] = 'mixed';
                    $vars_possibly_in_scope[$property_id] = true;
                }
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\ClassConstFetch) {
            $this->_checkClassConstFetch($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PropertyFetch) {
            $this->_checkPropertyFetch($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            $this->_checkStaticPropertyFetch($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BitwiseNot) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            $this->_checkBinaryOp($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc) {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PostDec) {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PreInc) {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PreDec) {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\New_) {
            $this->_checkNew($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Array_) {
            $this->_checkArray($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\Encapsed) {
            $this->_checkEncapsulatedString($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
            $this->_checkFunctionCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Ternary) {
            $this->_checkTernary($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BooleanNot) {
            $this->_checkBooleanNot($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Empty_) {
            $this->_checkEmpty($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Closure) {
            $closure_checker = new ClosureChecker($stmt, $this->_source);

            $this->_checkClosureUses($stmt, $vars_in_scope, $vars_possibly_in_scope);

            $use_vars = [];

            if (!$this->_is_static) {
                $use_vars['this'] = ClassChecker::getThisClass() && is_subclass_of(ClassChecker::getThisClass(), $this->_absolute_class) ?
                    ClassChecker::getThisClass() :
                    $this->_absolute_class;
            }

            foreach ($vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') === 0) {
                    $use_vars[$var] = $type;
                }
            }

            foreach ($stmt->uses as $use) {
                $use_vars[$use->var] = isset($vars_in_scope[$use->var]) ? $vars_in_scope[$use->var] : 'mixed';
            }

            $closure_checker->check($use_vars);

        } elseif ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            $this->_checkArrayAccess($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Int_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'int';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Double) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'double';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Bool_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'bool';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\String_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'string';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Object_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'object';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Array_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $stmt->returnType = 'array';

        } elseif ($stmt instanceof PhpParser\Node\Expr\Clone_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

            if (property_exists($stmt->expr, 'returnType')) {
                $stmt->returnType = $stmt->expr->returnType;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\Instanceof_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

            if ($stmt->class instanceof PhpParser\Node\Name && !in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                if ($this->_check_classes) {
                    ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name);
                }
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\Exit_) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Expr\Include_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

            $path_to_file = null;

            if ($stmt->expr instanceof PhpParser\Node\Scalar\String_) {
                $path_to_file = $stmt->expr->value;

                // attempts to resolve using get_include_path dirs
                $include_path = self::_resolveIncludePath($path_to_file, dirname($this->_file_name));
                $path_to_file = $include_path ? $include_path : $path_to_file;

                if ($path_to_file[0] !== '/') {
                    $path_to_file = getcwd() . '/' . $path_to_file;
                }
            }
            else {
                $path_to_file = self::_getPathTo($stmt->expr, $this->_file_name);
            }

            if ($path_to_file) {
                $reduce_pattern = '/\/[^\/]+\/\.\.\//';

                while (preg_match($reduce_pattern, $path_to_file)) {
                    $path_to_file = preg_replace($reduce_pattern, '/', $path_to_file);
                }

                // if the file is already included, we can't check much more
                if (in_array($path_to_file, get_included_files())) {
                    return;
                }

                if (in_array($path_to_file, FileChecker::getIncludesToIgnore())) {
                    return;
                }

                if (file_exists($path_to_file)) {
                    $include_stmts = FileChecker::getStatements($path_to_file);

                    $this->_require_file_name = $path_to_file;
                    $this->check($include_stmts, $vars_in_scope, $vars_possibly_in_scope);
                    return;
                }
            }

            $this->_check_classes = false;
            $this->_check_variables = false;

        } elseif ($stmt instanceof PhpParser\Node\Expr\Eval_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
            $this->_check_classes = false;
            $this->_check_variables = false;

        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignRef) {
            if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
                $vars_in_scope[$stmt->var->name] = 'mixed';
                $vars_possibly_in_scope[$stmt->var->name] = true;
                $this->registerVariable($stmt->var->name, $stmt->var->getLine());
            } else {
                $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
            }

            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\ErrorSuppress) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Expr\ShellExec) {
            throw new ForbiddenCodeException('Use of shell_exec', $this->_file_name, $stmt->getLine());

        } elseif ($stmt instanceof PhpParser\Node\Expr\Print_) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } else {
            var_dump('Unrecognised expression in ' . $this->_file_name);
            var_dump($stmt);
        }
    }

    /**
     * @return void
     */
    protected function _checkVariable(PhpParser\Node\Expr\Variable $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $method_id = null, $argument_offset = -1)
    {
        if ($this->_is_static && $stmt->name === 'this') {
            throw new StaticVariableException('Invalid reference to $this in a static context', $this->_file_name, $stmt->getLine());
        }

        if (!$this->_check_variables) {
            $stmt->returnType = 'mixed';

            if (is_string($stmt->name)) {
                $vars_in_scope[$stmt->name] = 'mixed';
                $vars_possibly_in_scope[$stmt->name] = true;
            }

            return;
        }

        if (in_array($stmt->name, ['this', '_SERVER', '_GET', '_POST', '_COOKIE', '_REQUEST', '_FILES', '_ENV', 'GLOBALS', 'argv'])) {
            return;
        }

        if (!is_string($stmt->name)) {
            $this->_checkExpression($stmt->name, $vars_in_scope, $vars_possibly_in_scope);
            return;
        }

        if ($method_id && $this->_isPassedByReference($method_id, $argument_offset)) {
            $this->_checkMethodParam($stmt, $method_id, $vars_in_scope, $vars_possibly_in_scope);
            return;
        }

        $var_name = $stmt->name;

        if (!isset($vars_in_scope[$var_name])) {
            if (!isset($vars_possibly_in_scope[$var_name]) || !isset($this->_all_vars[$var_name])) {
                throw new UndefinedVariableException('Cannot find referenced variable $' . $var_name, $this->_file_name, $stmt->getLine());

            } elseif (isset($this->_all_vars[$var_name]) && !isset($this->_warn_vars[$var_name])) {
                if (FileChecker::$show_notices) {
                    echo('Notice: ' . $this->_file_name . ' - possibly undefined variable $' . $var_name . ' on line ' . $stmt->getLine() . ', first seen on line ' . $this->_all_vars[$var_name] . PHP_EOL);
                }

                $this->_warn_vars[$var_name] = true;
            }

        } else {
            $stmt->returnType = $vars_in_scope[$var_name];
        }
    }

    protected function _checkMethodParam(PhpParser\Node\Expr $stmt, $method_id, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            $property_id = $stmt->name;
        }
        else if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $stmt->var->name === 'this') {
            $property_id = $stmt->var->name . '->' . $stmt->name;
        }
        else {
            throw new \InvalidArgumentException('Bad property passed to _checkMethodParam');
        }

        if (!isset($vars_in_scope[$property_id])) {
            $vars_possibly_in_scope[$property_id] = true;
            $this->registerVariable($property_id, $stmt->getLine());

            if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $this->_source->getMethodId()) {
                $this_method_id = $this->_source->getMethodId();

                if (!isset(self::$_this_assignments[$this_method_id])) {
                    self::$_this_assignments[$this_method_id] = [];
                }

                self::$_this_assignments[$this_method_id][$stmt->name] = 'mixed';
            }
        }

        $vars_in_scope[$property_id] = 'mixed';
    }

    protected function _checkPropertyFetch(PhpParser\Node\Expr\PropertyFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if ($stmt->var->name === 'this') {
                if (is_string($stmt->name)) {
                    if (!ClassChecker::getThisClass() && !FileChecker::shouldCheckClassProperties($this->_file_name)) {
                        // ignore this property
                    } else {
                        $class_checker = $this->_source->getClassChecker();

                        if ($class_checker) {
                            if (is_string($stmt->name)) {
                                $property_names = $class_checker->getPropertyNames();

                                if (!in_array($stmt->name, $property_names)) {
                                    $property_id = $this->_absolute_class . '::' . $stmt->name;
                                    $var_id = $stmt->var->name . '->' . $stmt->name;

                                    $var_defined = isset($vars_in_scope[$var_id]) || isset($vars_possibly_in_scope[$var_id]);

                                    if ((ClassChecker::getThisClass() && !$var_defined) || (!ClassChecker::getThisClass() && !$var_defined && !self::_propertyExists($property_id))) {
                                        throw new UndefinedPropertyException('$' . $var_id . ' is not defined', $this->_file_name, $stmt->getLine());
                                    }
                                }
                            }
                        } else {
                            throw new ScopeException('Cannot use $this when not inside class', $this->_file_name, $stmt->getLine());
                        }
                    }
                }
                else {
                    $this->_checkExpression($stmt->name, $vars_in_scope, $vars_possibly_in_scope);
                }

            } else {
                $this->_checkVariable($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
            }
        } else {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
        }

        if (!is_string($stmt->name)) {
            $this->_checkExpression($stmt->name, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkNew(PhpParser\Node\Expr\New_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $absolute_class = null;

        if ($stmt->class instanceof PhpParser\Node\Name && !in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($this->_check_classes) {
                ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name);

                $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
                $stmt->returnType = $absolute_class;
            }
        }

        if ($absolute_class) {
            $method_id = $absolute_class . '::__construct';

            $this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkArray(PhpParser\Node\Expr\Array_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($stmt->items as $item) {
            if ($item->key) {
                $this->_checkExpression($item->key, $vars_in_scope, $vars_possibly_in_scope);
            }

            $this->_checkExpression($item->value, $vars_in_scope, $vars_possibly_in_scope);
        }

        $stmt->returnType = 'array';
    }

    protected function _checkTryCatch(PhpParser\Node\Stmt\TryCatch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope);

        foreach ($stmt->catches as $catch) {
            $vars_in_scope[$catch->var] = ClassChecker::getAbsoluteClassFromName($catch->type, $this->_namespace, $this->_aliased_classes);
            $vars_possibly_in_scope[$catch->var] = true;
            $this->registerVariable($catch->var, $catch->getLine());

            if ($this->_check_classes) {
                ClassChecker::checkClassName($catch->type, $this->_namespace, $this->_aliased_classes, $this->_file_name);
            }

            $this->check($catch->stmts, $vars_in_scope, $vars_possibly_in_scope);
        }

        if ($stmt->finallyStmts) {
            $this->check($stmt->finallyStmts, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkFor(PhpParser\Node\Stmt\For_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $for_vars = array_merge([], $vars_in_scope);

        foreach ($stmt->init as $init) {
            $this->_checkExpression($init, $for_vars, $vars_possibly_in_scope);
        }

        foreach ($stmt->cond as $condition) {
            $this->_checkCondition($init, $for_vars, $vars_possibly_in_scope);
        }

        foreach ($stmt->loop as $expr) {
            $this->_checkExpression($expr, $for_vars, $vars_possibly_in_scope);
        }

        $for_vars_possibly_in_scope = [];

        $this->check($stmt->stmts, $for_vars, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

        foreach ($vars_in_scope as $var => $type) {
            if ($for_vars[$var] !== $type) {
                if ($type === 'mixed' || $for_vars[$var] === 'mixed') {
                    $vars_in_scope[$var] = 'mixed';
                }
                elseif (strpos($type, $for_vars[$var]) === false) {
                    $vars_in_scope[$var] = $type . '|' . $for_vars[$var];
                }
            }
        }

        $vars_possibly_in_scope = TypeChecker::reconcileTypes($for_vars_possibly_in_scope, $vars_possibly_in_scope, false, $stmt, $stmt->getLine());
    }

    protected function _checkForeach(PhpParser\Node\Stmt\Foreach_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        $foreach_vars = [];

        if ($stmt->keyVar) {
            $foreach_vars[$stmt->keyVar->name] = 'mixed';
            $vars_possibly_in_scope[$stmt->keyVar->name] = true;
            $this->registerVariable($stmt->keyVar->name, $stmt->getLine());
        }

        if ($stmt->valueVar) {
            $value_type = null;

            $iterator_type = $this->_type_checker->getType($stmt->expr, $vars_in_scope);

            if ($iterator_type) {
                foreach (explode('|', $iterator_type) as $return_type) {
                    if ($return_type === 'mixed') {
                        // ugh do nothing
                    }
                    elseif ($return_type === 'array') {
                        // do nothing
                    }
                    elseif (in_array($return_type, ['string', 'void', 'int'])) {
                        throw new IteratorException('Cannot iterate over ' . $return_type, $this->_file_name, $stmt->getLine());
                    }
                    elseif ($return_type === 'null') {
                        if ($this->_check_nulls) {
                            throw new IteratorException('Cannot iterate over null', $this->_file_name, $stmt->getLine());
                        }
                    }
                    else {
                        if (strpos($return_type, '<') !== false && strpos($return_type, '>') !== false) {
                            $value_type = substr($return_type, strpos($return_type, '<') + 1, -1);
                            $return_type = preg_replace('/\<' . preg_quote($value_type) . '\>/', '', $return_type, 1);
                        }

                        if ($return_type !== 'array' && $return_type !== 'Traversable' && $return_type !== $this->_class_name) {
                            ClassChecker::checkAbsoluteClass($return_type, $stmt, $this->_file_name);
                        }
                    }
                }
            }

            $foreach_vars[$stmt->valueVar->name] = $value_type ? $value_type : 'mixed';
            $vars_possibly_in_scope[$stmt->valueVar->name] = true;
            $this->registerVariable($stmt->valueVar->name, $stmt->getLine());
        }

        $foreach_vars = array_merge($vars_in_scope, $foreach_vars);

        $foreach_vars_possibly_in_scope = [];

        $this->check($stmt->stmts, $foreach_vars, $vars_possibly_in_scope, $foreach_vars_possibly_in_scope);

        foreach ($vars_in_scope as $var => $type) {
            if ($foreach_vars[$var] !== $type) {
                if ($type === 'mixed' || $foreach_vars[$var] === 'mixed') {
                    $vars_in_scope[$var] = 'mixed';
                }
                elseif (strpos($type, $foreach_vars[$var]) === false) {
                    $vars_in_scope[$var] = $type . '|' . $foreach_vars[$var];
                }
            }
        }

        $vars_possibly_in_scope = TypeChecker::reconcileTypes($foreach_vars_possibly_in_scope, $vars_possibly_in_scope, false, $stmt, $stmt->getLine());
    }

    protected function _checkWhile(PhpParser\Node\Stmt\While_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope);

        $while_vars_in_scope = array_merge([], $vars_in_scope);

        $while_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        // if the while has an or as the main component, we cannot safely reason about it
        if ($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp && self::_containsBooleanOr($stmt->cond)) {
            $while_vars_in_scope = array_merge([], $vars_in_scope);
        }
        else {
            $while_vars_in_scope = TypeChecker::reconcileTypes($while_types, $while_vars_in_scope, true, $this->_file_name, $stmt->getLine());
        }

        $this->check($stmt->stmts, $while_vars_in_scope, $vars_possibly_in_scope);

        foreach ($vars_in_scope as $var => $type) {
            if ($while_vars_in_scope[$var] !== $type) {
                if ($type === 'mixed' || $while_vars_in_scope[$var] === 'mixed') {
                    $vars_in_scope[$var] = 'mixed';
                }
                elseif (strpos($type, $while_vars_in_scope[$var]) === false) {
                    $vars_in_scope[$var] = $type . '|' . $while_vars_in_scope[$var];
                }
            }
        }
    }

    protected function _checkDo(PhpParser\Node\Stmt\Do_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope);

        $vars_in_scope_copy = array_merge([], $vars_in_scope);

        $this->_checkCondition($stmt->cond, $vars_in_scope_copy, $vars_possibly_in_scope);
    }

    protected function _checkBinaryOp(PhpParser\Node\Expr\BinaryOp $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $nesting = 0)
    {
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat && $nesting > 20) {
            // ignore deeply-nested string concatenation
        }
        else if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd) {
            $left_type_assertions = $this->_type_checker->getTypeAssertions($stmt->left, true);

            $this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope);

            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            $op_vars_in_scope = TypeChecker::reconcileTypes($left_type_assertions, $vars_in_scope, true, $this->_file_name, $stmt->getLine());

            $this->_checkExpression($stmt->right, $op_vars_in_scope, $vars_possibly_in_scope);
        }
        else if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            $left_type_assertions = $this->_type_checker->getTypeAssertions($stmt->left, true);

            $negated_type_assertions = TypeChecker::negateTypes($left_type_assertions);

            $this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope);

            // while in an or, we allow scope to boil over to support
            // statements of the form if ($x === null || $x->foo())
            $op_vars_in_scope = TypeChecker::reconcileTypes($negated_type_assertions, $vars_in_scope, true, $this->_file_name, $stmt->getLine());

            $this->_checkExpression($stmt->right, $op_vars_in_scope, $vars_possibly_in_scope);
        }
        else {
            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
                $stmt->returnType = 'string';
            }

            if ($stmt->left instanceof PhpParser\Node\Expr\BinaryOp) {
                $this->_checkBinaryOp($stmt->left, $vars_in_scope, $vars_possibly_in_scope, ++$nesting);
            }
            else {
                $this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope);
            }

            if ($stmt->right instanceof PhpParser\Node\Expr\BinaryOp) {
                $this->_checkBinaryOp($stmt->right, $vars_in_scope, $vars_possibly_in_scope, ++$nesting);
            }
            else {
                $this->_checkExpression($stmt->right, $vars_in_scope, $vars_possibly_in_scope);
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Equal ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotEqual ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Identical ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Greater ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Smaller ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual
        ) {
            $stmt->returnType = 'bool';
        }
    }

    protected function _checkAssignment(PhpParser\Node\Expr\Assign $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        $type_in_comments = null;
        $doc_comment = $stmt->getDocComment();

        if ($doc_comment) {
            $comments = self::parseDocComment($doc_comment);

            if ($comments && isset($comments['specials']['var'][0])) {
                $type_in_comments = explode(' ', $comments['specials']['var'][0])[0];

                if ($type_in_comments[0] === strtoupper($type_in_comments[0])) {
                    $type_in_comments = ClassChecker::getAbsoluteClassFromString($type_in_comments, $this->_namespace, $this->_aliased_classes);
                }
            }
        }

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable && is_string($stmt->var->name)) {
            if ($type_in_comments) {
                $vars_in_scope[$stmt->var->name] = $type_in_comments;

            } elseif (isset($stmt->expr->returnType)) {
                $var_name = $stmt->var->name;
                $this->_typeAssignment($var_name, $stmt->expr, $vars_in_scope);
            }
            else {
                $vars_in_scope[$stmt->var->name] = 'mixed';
            }

            $vars_possibly_in_scope[$stmt->var->name] = true;
            $this->registerVariable($stmt->var->name, $stmt->var->getLine());

        } elseif ($stmt->var instanceof PhpParser\Node\Expr\List_) {
            foreach ($stmt->var->vars as $var) {
                if ($var) {
                    $vars_in_scope[$var->name] = 'mixed';
                    $vars_possibly_in_scope[$var->name] = true;
                    $this->registerVariable($var->name, $var->getLine());
                }
            }

        } else if ($stmt->var instanceof PhpParser\Node\Expr\ArrayDimFetch && $stmt->var->var instanceof PhpParser\Node\Expr\Variable) {
            // if it's an array assignment
            $vars_in_scope[$stmt->var->var->name] = 'mixed';
            $vars_possibly_in_scope[$stmt->var->var->name] = true;
            $this->registerVariable($stmt->var->var->name, $stmt->var->var->getLine());

        } else if ($stmt->var instanceof PhpParser\Node\Expr\PropertyFetch) {
            if ($stmt->var->var instanceof PhpParser\Node\Expr\Variable) {
                if ($stmt->var->var->name === 'this' && is_string($stmt->var->name)) {
                    $method_id = $this->_source->getMethodId();

                    if (!isset(self::$_this_assignments[$method_id])) {
                        self::$_this_assignments[$method_id] = [];
                    }

                    $property_id = $this->_absolute_class . '::' . $stmt->var->name;
                    $var_id = $stmt->var->var->name . '->' . $stmt->var->name;
                    self::$_existing_properties[$property_id] = 1;

                    if ($type_in_comments) {
                        $vars_in_scope[$var_id] = $type_in_comments;
                    }
                    elseif (isset($stmt->expr->returnType)) {
                        $this->_typeAssignment($var_id, $stmt->expr, $vars_in_scope);
                    }
                    else {
                        $vars_in_scope[$var_id] = 'mixed';
                    }

                    $vars_possibly_in_scope[$var_id] = true;

                    // right now we have to settle for mixed
                    self::$_this_assignments[$method_id][$stmt->var->name] = 'mixed';
                    //self::$_this_assignments[$method_id][$stmt->var->name] = $vars_in_scope[$property_id];
                }
            }
        }
    }

    protected function _typeAssignment($var_name, PhpParser\Node\Expr $expr, array &$vars_in_scope)
    {
        if ($expr->returnType === 'void') {
            throw new TypeResolutionException('Cannot assign $' . $var_name . ' to type void', $this->_file_name, $expr->getLine());
        } else {
            $vars_in_scope[$var_name] = $expr->returnType;
        }
    }

    protected function _checkAssignmentOperation(PhpParser\Node\Expr\AssignOp $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkMethodCall(PhpParser\Node\Expr\MethodCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        $class_type = null;
        $method_id = null;

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if (!is_string($stmt->var->name)) {
                $this->_checkExpression($stmt->var->name, $vars_in_scope, $vars_possibly_in_scope);
            }
            else if ($stmt->var->name === 'this' && !$this->_class_name) {
                throw new ScopeException('Use of $this in non-class context', $this->_file_name, $stmt->getLine());
            }
        } elseif ($stmt->var instanceof PhpParser\Node\Expr) {
            $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
        }

        $class_type = $this->_type_checker->getType($stmt->var, $vars_in_scope);

        // make sure we stay vague here
        if (!$class_type) {
            $stmt->returnType = 'mixed';
        }

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable && $stmt->var->name === 'this' && is_string($stmt->name)) {
            $this_method_id = $this->_source->getMethodId();

            if (!isset(self::$_this_calls[$this_method_id])) {
                self::$_this_calls[$this_method_id] = [];
            }

            self::$_this_calls[$this_method_id][] = $stmt->name;

            if (ClassChecker::getThisClass() &&
                (
                    ClassChecker::getThisClass() === $this->_absolute_class ||
                    is_subclass_of(ClassChecker::getThisClass(), $this->_absolute_class) ||
                    trait_exists($this->_absolute_class)
                )) {

                $method_id = $this->_absolute_class . '::' . $stmt->name;

                $method_checker = ClassChecker::getMethodChecker($method_id);

                if ($method_checker->getMethodId() !== $this->_source->getMethodId()) {
                    $this_vars_in_scope = [];

                    $this_vars_possibly_in_scope = [];

                    foreach ($vars_possibly_in_scope as $var => $type) {
                        if (strpos($var, 'this->') === 0) {
                            $this_vars_possibly_in_scope[$var] = true;
                        }
                    }

                    foreach ($vars_in_scope as $var => $type) {
                        if (strpos($var, 'this->') === 0) {
                            $this_vars_in_scope[$var] = $type;
                        }
                    }

                    $method_checker->check($this_vars_in_scope, $this_vars_possibly_in_scope);
                }
            }
        }

        if ($class_type && $this->_check_methods && is_string($stmt->name) && is_string($class_type)) {
            foreach (explode('|', $class_type) as $absolute_class) {
                $absolute_class = preg_replace('/^\\\/', '', $absolute_class);

                // strip out generics
                $absolute_class = preg_replace('/\<[A-Za-z0-9' . '\\\\' . ']+\>/', '', $absolute_class);

                if ($absolute_class === 'null') {
                    if ($this->_check_nulls) {
                        throw new InvalidArgumentException('Cannot call method ' . $stmt->name . ' on nullable variable ' . $class_type, $this->_file_name, $stmt->getLine());
                    }

                    return;
                }

                if (in_array($absolute_class, ['int', 'bool', 'array'])) {
                    throw new InvalidArgumentException('Cannot call method ' . $stmt->name . ' on ' . $class_type . ' variable', $this->_file_name, $stmt->getLine());
                }

                if ($absolute_class && $absolute_class[0] === strtoupper($absolute_class[0]) && !method_exists($absolute_class, '__call') && !self::isMock($absolute_class)) {
                    ClassChecker::checkAbsoluteClass($absolute_class, $stmt, $this->_file_name);

                    $method_id = $absolute_class . '::' . $stmt->name;

                    if (!isset(self::$_method_call_index[$method_id])) {
                        self::$_method_call_index[$method_id] = [];
                    }

                    if ($this->_source instanceof ClassMethodChecker) {
                        self::$_method_call_index[$method_id][] = $this->_source->getMethodId();
                    }
                    else {
                        self::$_method_call_index[$method_id][] = $this->_source->getFileName();
                    }

                    ClassMethodChecker::checkMethodExists($method_id, $this->_file_name, $stmt);

                    if (!($this->_source->getSource() instanceof TraitChecker)) {
                        ClassMethodChecker::checkMethodVisibility($method_id, $this->_absolute_class, $this->_file_name, $stmt->getLine());
                    }

                    $return_types = ClassMethodChecker::getMethodReturnTypes($method_id);

                    if ($return_types) {
                        $return_types = self::_fleshOutReturnTypes($return_types, $stmt->args, $method_id);

                        $stmt->returnType = implode('|', $return_types);
                    }
                }
            }
        }

        $this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkClosureUses(PhpParser\Node\Expr\Closure $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($stmt->uses as $use) {
            if (!isset($vars_in_scope[$use->var])) {
                if ($use->byRef) {
                    $vars_in_scope[$use->var] = 'mixed';
                    $vars_possibly_in_scope[$use->var] = true;
                    $this->registerVariable($use->var, $use->getLine());

                } elseif (!isset($vars_possibly_in_scope[$use->var])) {
                    throw new UndefinedVariableException('Cannot find referenced variable $' . $use->var, $this->_file_name, $use->getLine());

                } elseif (isset($this->_all_vars[$use->var])) {
                    if (!isset($this->_warn_vars[$use->var])) {
                        if (FileChecker::$show_notices) {
                            echo('Notice: ' . $this->_file_name . ' - possibly undefined variable $' . $use->var . ' on line ' . $use->getLine() . ', first seen on line ' . $this->_all_vars[$use->var] . PHP_EOL);
                        }

                        $this->_warn_vars[$use->var] = true;
                    }

                } else {
                    throw new UndefinedVariableException('Cannot find referenced variable $' . $use->var, $this->_file_name, $use->getLine());
                }
            }
        }
    }

    /**
     * @return void
     */
    protected function _checkStaticCall(PhpParser\Node\Expr\StaticCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable || $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // this is when calling $some_class::staticMethod() - which is a shitty way of doing things
            // because it can't be statically type-checked
            return;
        }

        $method_id = null;
        $absolute_class = null;

        if (count($stmt->class->parts) === 1 && in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($stmt->class->parts[0] === 'parent') {
                if ($this->_class_extends === null) {
                    throw new ParentNotFoundException('Cannot call method on parent as this class does not extend another', $this->_file_name, $stmt->getLine());
                }

                $absolute_class = $this->_class_extends;
            } else {
                $absolute_class = ($this->_namespace ? $this->_namespace . '\\' : '') . $this->_class_name;
            }

        } elseif ($this->_check_classes) {
            ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name);
            $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
        }

        if ($absolute_class && $this->_check_methods && is_string($stmt->name) && !method_exists($absolute_class, '__callStatic') && !self::isMock($absolute_class)) {
            $method_id = $absolute_class . '::' . $stmt->name;

            if (!isset(self::$_method_call_index[$method_id])) {
                self::$_method_call_index[$method_id] = [];
            }

            if ($this->_source instanceof ClassMethodChecker) {
                self::$_method_call_index[$method_id][] = $this->_source->getMethodId();
            }
            else {
                self::$_method_call_index[$method_id][] = $this->_source->getFileName();
            }

            ClassMethodChecker::checkMethodExists($method_id, $this->_file_name, $stmt);
            ClassMethodChecker::checkMethodVisibility($method_id, $this->_absolute_class, $this->_file_name, $stmt->getLine());

            if ($this->_is_static) {
                if (!ClassMethodChecker::isGivenMethodStatic($method_id)) {
                    throw new StaticInvocationException('Method ' . $method_id . ' is not static', $this->_file_name, $stmt->getLine());
                }
            }
            else {
                if ($stmt->class->parts[0] === 'self' && $stmt->name !== '__construct') {
                    if (!ClassMethodChecker::isGivenMethodStatic($method_id)) {
                        throw new StaticInvocationException('Cannot call non-static method ' . $method_id . ' as if it were static', $this->_file_name, $stmt->getLine());
                    }
                }
            }

            $return_types = ClassMethodChecker::getMethodReturnTypes($method_id);

            if ($return_types) {
                $return_types = self::_fleshOutReturnTypes($return_types, $stmt->args, $method_id);
                $stmt->returnType = implode('|', $return_types);
            }
        }

        $this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected static function _fleshOutReturnTypes(array $return_types, array $args, $method_id)
    {
        $absolute_class = explode('::', $method_id)[0];

        foreach ($return_types as &$return_type) {
            $return_type_parts = TypeChecker::tokenize($return_type);

            foreach ($return_type_parts as &$return_type_part) {
                if ($return_type_part === '$this' || $return_type_part === 'static') {
                    $return_type_part = $absolute_class;
                }
                else if ($return_type_part[0] === '$') {
                    $method_params = ClassMethodChecker::getMethodParams($method_id);

                    foreach ($args as $i => $arg) {
                        $method_param = $method_params[$i];

                        if ($return_type_part === '$' . $method_param['name']) {
                            if ($arg->value instanceof PhpParser\Node\Scalar\String_) {
                                $return_type_part = $arg->value->value;
                                break;
                            }
                        }
                    }

                    if ($return_type_part[0] === '$') {
                        $return_type_part = 'mixed';
                    }
                }
            }

            $return_type = implode('', $return_type_parts);
        }

        return $return_types;
    }

    protected static function _getMethodFromCallBlock($call, array $args, $method_id)
    {
        $absolute_class = explode('::', $method_id)[0];

        $original_call = $call;

        $call = preg_replace('/^\$this(->|::)/', $absolute_class . '::', $call);

        $call = preg_replace('/\(\)$/', '', $call);

        if (strpos($call, '$') !== false) {
            $method_params = ClassMethodChecker::getMethodParams($method_id);

            foreach ($args as $i => $arg) {
                $method_param = $method_params[$i];
                $preg_var_name = preg_quote('$' . $method_param['name']);

                if (preg_match('/::' . $preg_var_name . '$/', $call)) {
                    if ($arg->value instanceof PhpParser\Node\Scalar\String_) {
                        $call = preg_replace('/' . $preg_var_name . '$/', $arg->value->value, $call);
                        break;
                    }
                }
            }
        }

        return $original_call === $call || strpos($call, '$') !== false ? null : $call;
    }

    protected function _checkMethodParams(array $args, $method_id, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($args as $i => $arg) {
            if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch &&
                $arg->value->var instanceof PhpParser\Node\Expr\Variable &&
                $arg->value->var->name === 'this' &&
                is_string($arg->value->name)) {

                if ($method_id) {
                    if ($this->_isPassedByReference($method_id, $i)) {
                        $this->_checkMethodParam($arg->value, $method_id, $vars_in_scope, $vars_possibly_in_scope);
                    }
                    else {
                        $this->_checkPropertyFetch($arg->value, $vars_in_scope, $vars_possibly_in_scope);
                    }
                } else {
                    $property_id = 'this' . '->' . $arg->value->name;
                    // we don't know if it exists, assume it's passed by reference
                    $vars_in_scope[$property_id] = 'mixed';
                    $vars_possibly_in_scope[$property_id] = true;
                    $this->registerVariable($property_id, $arg->value->getLine());
                }
            }
            elseif ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                if ($method_id) {
                    $this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope, $method_id, $i);
                } elseif (is_string($arg->value->name)) {
                    // we don't know if it exists, assume it's passed by reference
                    $vars_in_scope[$arg->value->name] = 'mixed';
                    $vars_possibly_in_scope[$arg->value->name] = true;
                    $this->registerVariable($arg->value->name, $arg->value->getLine());
                }
            } else {
                $this->_checkExpression($arg->value, $vars_in_scope, $vars_possibly_in_scope);
            }

            if ($method_id && isset($arg->value->returnType)) {
                foreach (explode('|', $arg->value->returnType) as $return_type) {
                    TypeChecker::checkMethodParam($return_type, $method_id, $i, $this->_absolute_class, $this->_check_nulls, $this->_file_name, $arg->value->getLine());
                }

            }
        }
    }

    protected function _checkConstFetch(PhpParser\Node\Expr\ConstFetch $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name) {
            switch ($stmt->name->parts) {
                case ['null']:
                    $stmt->returnType = 'null';
                    break;

                case ['false']:
                    // false is a subtype of bool
                    $stmt->returnType = 'false';
                    break;

                case ['true']:
                    $stmt->returnType = 'bool';
                    break;
            }
        }
    }

    protected function _checkClassConstFetch(PhpParser\Node\Expr\ClassConstFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_check_consts && $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts !== ['static']) {
            if ($stmt->class->parts === ['self']) {
                $absolute_class = $this->_absolute_class;
            } else {
                $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
                ClassChecker::checkAbsoluteClass($absolute_class, $stmt, $this->_file_name);
            }

            $const_id = $absolute_class . '::' . $stmt->name;

            if (!defined($const_id)) {
                throw new UndefinedConstantException('Const ' . $const_id . ' is not defined', $this->_file_name, $stmt->getLine());
            }
        } elseif ($stmt->class instanceof PhpParser\Node\Expr) {
            $this->_checkExpression($stmt->class, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    /**
     * @return void
     */
    protected function _checkStaticPropertyFetch(PhpParser\Node\Expr\StaticPropertyFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable || $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // this is when calling $some_class::staticMethod() - which is a shitty way of doing things
            // because it can't be statically type-checked
            return;
        }

        $method_id = null;
        $absolute_class = null;

        if (count($stmt->class->parts) === 1 && in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($stmt->class->parts[0] === 'parent') {
                $absolute_class = $this->_class_extends;
            } else {
                $absolute_class = ($this->_namespace ? $this->_namespace . '\\' : '') . $this->_class_name;
            }
        } elseif ($this->_check_classes) {
            ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name);
            $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
        }

        if ($absolute_class && $this->_check_variables && is_string($stmt->name) && !self::isMock($absolute_class)) {
            $var_id = $absolute_class . '::$' . $stmt->name;

            if (!self::_staticVarExists($var_id)) {
                throw new UndefinedVariableException('Static variable ' . $var_id . ' does not exist', $this->_file_name, $stmt->getLine());
            }
        }
    }

    protected function _checkReturn(PhpParser\Node\Stmt\Return_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $type_in_comments = null;
        $doc_comment = $stmt->getDocComment();

        if ($doc_comment) {
            $comments = self::parseDocComment($doc_comment);

            if ($comments && isset($comments['specials']['var'][0])) {
                $type_in_comments = explode(' ', $comments['specials']['var'][0])[0];

                if ($type_in_comments[0] === strtoupper($type_in_comments[0])) {
                    $type_in_comments = ClassChecker::getAbsoluteClassFromString($type_in_comments, $this->_namespace, $this->_aliased_classes);
                }
            }
        }

        if ($stmt->expr) {
            $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

            if ($type_in_comments) {
                $stmt->returnType = $type_in_comments;
            }
            elseif (isset($stmt->expr->returnType)) {
                $stmt->returnType = $stmt->expr->returnType;
            }
            else {
                $stmt->returnType = 'mixed';
            }
        }
        else {
            $stmt->returnType = 'void';
        }

        if ($this->_source instanceof FunctionChecker) {
            $this->_source->addReturnTypes($stmt->expr ? $stmt->returnType : '', $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkTernary(PhpParser\Node\Expr\Ternary $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope);

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        $can_negate_if_types = !($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd);

        if ($stmt->if) {
            $t_if_vars_in_scope = TypeChecker::reconcileTypes($if_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
            $this->_checkExpression($stmt->if, $t_if_vars_in_scope, $vars_possibly_in_scope);
        }

        if ($can_negate_if_types) {
            $negated_if_types = TypeChecker::negateTypes($if_types);
            $t_else_vars_in_scope = TypeChecker::reconcileTypes($negated_if_types, $vars_in_scope, true, $this->_file_name, $stmt->getLine());
        }
        else {
            $t_else_vars_in_scope = $vars_in_scope;
        }

        $this->_checkExpression($stmt->else, $t_else_vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkBooleanNot(PhpParser\Node\Expr\BooleanNot $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkEmpty(PhpParser\Node\Expr\Empty_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkThrow(PhpParser\Node\Stmt\Throw_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkSwitch(PhpParser\Node\Stmt\Switch_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $type_candidate_var = null;

        if ($stmt->cond instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->cond->name instanceof PhpParser\Node\Name &&
            $stmt->cond->name->parts === ['get_class']) {

            $var = $stmt->cond->args[0]->value;

            if ($var instanceof PhpParser\Node\Expr\Variable && is_string($var->name)) {
                $type_candidate_var = $var->name;
            }
        }

        $this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope);

        $case_types = [];

        $new_vars_in_scope = null;
        $new_vars_possibly_in_scope = [];

        $redefined_vars = null;

        foreach ($stmt->cases as $case) {
            if ($case->cond) {
                $this->_checkCondition($case->cond, $vars_in_scope, $vars_possibly_in_scope);

                if ($type_candidate_var && $case->cond instanceof PhpParser\Node\Scalar\String_) {
                    $case_types[] = $case->cond->value;
                }
            }

            $last_stmt = null;

            if ($case->stmts) {
                $switch_vars = $type_candidate_var && !empty($case_types) ?
                                [$type_candidate_var => implode('|', $case_types)] :
                                [];

                $case_vars_in_scope = array_merge($vars_in_scope, $switch_vars);
                $old_case_vars = $case_vars_in_scope;
                $case_vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $switch_vars);

                $this->check($case->stmts, $case_vars_in_scope, $case_vars_possibly_in_scope);

                $last_stmt = $case->stmts[count($case->stmts) - 1];

                if (!($last_stmt instanceof PhpParser\Node\Stmt\Return_)) {
                    $case_redefined_vars = [];

                    foreach ($old_case_vars as $case_var => $type) {
                        if ($case_vars_in_scope[$case_var] !== $type) {
                            $case_redefined_vars[$case_var] = $case_vars_in_scope[$case_var];
                        }
                    }

                    if ($redefined_vars === null) {
                        $redefined_vars = $case_redefined_vars;
                    }
                    else {
                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (!isset($case_redefined_vars[$redefined_var])) {
                                unset($redefined_vars[$redefined_var]);
                            }
                        }
                    }

                    if ($new_vars_in_scope === null) {
                        $new_vars_in_scope = array_diff_key($case_vars_in_scope, $vars_in_scope);
                        $new_vars_possibly_in_scope = array_diff_key($case_vars_possibly_in_scope, $vars_possibly_in_scope);
                    }
                    else {
                        foreach ($new_vars_in_scope as $new_var => $type) {
                            if (!isset($case_vars_in_scope[$new_var])) {
                                unset($new_vars_in_scope[$new_var]);
                            }
                        }

                        $new_vars_possibly_in_scope = array_merge(
                            array_diff_key(
                                $case_vars_possibly_in_scope,
                                $vars_possibly_in_scope
                            ),
                            $new_vars_possibly_in_scope
                        );
                    }
                }
            }

            if ($type_candidate_var && ($last_stmt instanceof PhpParser\Node\Stmt\Break_ || $last_stmt instanceof PhpParser\Node\Stmt\Return_)) {
                $case_types = [];
            }

            // only update vars if there is a default
            if ($case->cond === null && !($last_stmt instanceof PhpParser\Node\Stmt\Return_)) {
                if ($new_vars_in_scope) {
                    $vars_in_scope = array_merge($vars_in_scope, $new_vars_in_scope);
                }

                if ($redefined_vars) {
                    $vars_in_scope = array_merge($vars_in_scope, $redefined_vars);
                }
            }
        }

        $vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $new_vars_possibly_in_scope);
    }

    protected function _checkFunctionCall(PhpParser\Node\Expr\FuncCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $method = $stmt->name;

        if ($method instanceof PhpParser\Node\Name) {
            if ($method->parts === ['method_exists']) {
                $this->_check_methods = false;

            } elseif ($method->parts === ['function_exists']) {
                $this->_check_functions = false;

            } elseif ($method->parts === ['defined']) {
                $this->_check_consts = false;

            } elseif ($method->parts === ['extract']) {
                $this->_check_variables = false;

            } elseif ($method->parts === ['var_dump'] || $method->parts === ['die'] || $method->parts === ['exit']) {
                if (FileChecker::shouldCheckVarDumps($this->_file_name)) {
                    throw new ForbiddenCodeException('Unsafe ' . implode('', $method->parts), $this->_file_name, $stmt->getLine());
                }
            }
        }

        $method_id = null;

        if ($stmt->name instanceof PhpParser\Node\Name && $this->_check_functions) {
            $method_id = implode('', $stmt->name->parts);

            if ($this->_absolute_class) {
                //$method_id = $this->_absolute_class . '::' . $method_id;
            }

            $this->_checkFunctionExists($method_id, $stmt);

            $stmt->returnType = 'mixed';
        }

        foreach ($stmt->args as $i => $arg) {
            if ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                if ($method_id) {
                    $this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope, $method_id, $i);
                } else {
                    $this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope);
                }
            } else {
                $this->_checkExpression($arg->value, $vars_in_scope, $vars_possibly_in_scope);
            }
        }
    }

    protected function _checkArrayAccess(PhpParser\Node\Expr\ArrayDimFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
        if ($stmt->dim) {
            $this->_checkExpression($stmt->dim, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkEncapsulatedString(PhpParser\Node\Scalar\Encapsed $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($stmt->parts as $part) {
            $this->_checkExpression($part, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    public function registerVariable($var_name, $line_number)
    {
        if (!isset($this->_all_vars[$var_name])) {
            $this->_all_vars[$var_name] = $line_number;
        }
    }

    public static function _getClassProperties(\ReflectionClass $reflection_class, $absolute_class_name)
    {
        $properties = $reflection_class->getProperties();
        $props_arr = [];

        foreach ($properties as $reflection_property){
            if ($reflection_property->isPrivate() || $reflection_property->isStatic()) {
                continue;
            }

            self::$_existing_properties[$absolute_class_name . '::' . $reflection_property->getName()] = 1;
        }

        $parent_reflection_class = $reflection_class->getParentClass();

        if ($parent_reflection_class){
            self::_getClassProperties($parent_reflection_class, $absolute_class_name);
        }
    }

    protected static function _propertyExists($property_id)
    {
        if (isset(self::$_existing_properties[$property_id])) {
            return true;
        }

        $absolute_class = explode('::', $property_id)[0];

        $reflection_class = new \ReflectionClass($absolute_class);

        self::_getClassProperties($reflection_class, $absolute_class);

        return isset(self::$_existing_properties[$property_id]);
    }

    /**
     * @return void
     */
    public function _checkFunctionExists($method_id, $stmt)
    {
        if (isset(self::$_existing_functions[$method_id])) {
            return;
        }

        $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);

        if ($file_checker->hasFunction($method_id)) {
            return;
        }

        if (strpos($method_id, '::') !== false) {
            $method_id = preg_replace('/^[^:]+::/', '', $method_id);
        }

        try {
            (new \ReflectionFunction($method_id));
        }
        catch (\ReflectionException $e) {
            throw new UndefinedFunctionException('Function ' . $method_id . ' does not exist', $this->_file_name, $stmt->getLine());
        }

        self::$_existing_functions[$method_id] = 1;
    }

    protected static function _staticVarExists($var_id)
    {
        if (isset(self::$_existing_static_vars[$var_id])) {
            return true;
        }

        $absolute_class = explode('::', $var_id)[0];

        try {
            $reflection_class = new \ReflectionClass($absolute_class);
        }
        catch (\ReflectionException $e) {
            return false;
        }

        $static_properties = $reflection_class->getStaticProperties();

        foreach ($static_properties as $property => $value) {
            self::$_existing_static_vars[$absolute_class . '::$' . $property] = 1;
        }

        return isset(self::$_existing_static_vars[$var_id]);
    }

    /**
     * Parse a docblock comment into its parts.
     *
     * Taken from advanced api docmaker
     * Which was taken from https://github.com/facebook/libphutil/blob/master/src/parser/docblock/PhutilDocblockParser.php
     *
     * @return array Array of the main comment and specials
     */
    public static function parseDocComment($docblock)
    {
        // Strip off comments.
        $docblock = trim($docblock);
        $docblock = preg_replace('@^/\*\*@', '', $docblock);
        $docblock = preg_replace('@\*/$@', '', $docblock);
        $docblock = preg_replace('@^\s*\*@m', '', $docblock);

        // Normalize multi-line @specials.
        $lines = explode("\n", $docblock);
        $last = false;
        foreach ($lines as $k => $line) {
            if (preg_match('/^\s?@\w/i', $line)) {
                $last = $k;
            } elseif (preg_match('/^\s*$/', $line)) {
                $last = false;
            } elseif ($last !== false) {
                $lines[$last] = rtrim($lines[$last]).' '.trim($line);
                unset($lines[$k]);
            }
        }
        $docblock = implode("\n", $lines);

        $special = array();

        // Parse @specials.
        $matches = null;
        $have_specials = preg_match_all('/^\s?@(\w+)\s*([^\n]*)/m', $docblock, $matches, PREG_SET_ORDER);
        if ($have_specials) {
            $docblock = preg_replace('/^\s?@(\w+)\s*([^\n]*)/m', '', $docblock);
            foreach ($matches as $match) {
                list($_, $type, $data) = $match;

                if (empty($special[$type])) {
                    $special[$type] = array();
                }

                $special[$type][] = $data;
            }
        }

        $docblock = str_replace("\t", '  ', $docblock);

        // Smush the whole docblock to the left edge.
        $min_indent = 80;
        $indent = 0;
        foreach (array_filter(explode("\n", $docblock)) as $line) {
            for ($ii = 0; $ii < strlen($line); $ii++) {
                if ($line[$ii] != ' ') {
                    break;
                }
                $indent++;
            }

            $min_indent = min($indent, $min_indent);
        }

        $docblock = preg_replace('/^' . str_repeat(' ', $min_indent) . '/m', '', $docblock);
        $docblock = rtrim($docblock);

        // Trim any empty lines off the front, but leave the indent level if there
        // is one.
        $docblock = preg_replace('/^\s*\n/', '', $docblock);

        return array('description' => $docblock, 'specials' => $special);
    }

    /**
     * @return string
     */
    public static function renderDocComment(array $parsed_doc_comment)
    {
        $doc_comment_text = '/**' . PHP_EOL;

        $description_lines = null;

        $trimmed_description = trim($parsed_doc_comment['description']);

        if (!empty($trimmed_description)) {
            $description_lines = explode(PHP_EOL, $parsed_doc_comment['description']);

            foreach ($description_lines as $line) {
                $doc_comment_text .= ' * ' . $line . PHP_EOL;
            }
        }

        if ($description_lines && $parsed_doc_comment['specials']) {
            $doc_comment_text .= ' *' . PHP_EOL;
        }

        if ($parsed_doc_comment['specials']) {
            $type_lengths = array_map('strlen', array_keys($parsed_doc_comment['specials']));
            $type_width = max($type_lengths) + 1;

            foreach ($parsed_doc_comment['specials'] as $type => $lines) {
                foreach ($lines as $line) {
                    $doc_comment_text .= ' * @' . str_pad($type, $type_width) . $line . PHP_EOL;
                }
            }
        }



        $doc_comment_text .= ' */';

        return $doc_comment_text;
    }

    protected function _isPassedByReference($method_id, $argument_offset)
    {
        if (strpos($method_id, '::') !== false) {
            try {
                $method_params = ClassMethodChecker::getMethodParams($method_id);

                return $argument_offset < count($method_params) && $method_params[$argument_offset]['by_ref'];
            }
            catch (\ReflectionException $e) {
                // we fall through to the functions below
            }
        }

        $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);

        if ($file_checker->hasFunction($method_id)) {
            return $file_checker->isPassedByReference($method_id, $argument_offset);
        }

        if (strpos($method_id, '::') !== false) {
            $method_id = preg_replace('/^[^:]+::/', '', $method_id);
        }

        try {
            $reflection_parameters = (new \ReflectionFunction($method_id))->getParameters();

            // if value is passed by reference
            return $argument_offset < count($reflection_parameters) && $reflection_parameters[$argument_offset]->isPassedByReference();
        }
        catch (\ReflectionException $e) {
            return false;
        }
    }



    public static function customCheckString(callable $function)
    {
        self::$_check_string_fn = $function;
    }

    /**
     * @return string
     */
    public static function findEntryPoints($method_id)
    {
        $output = 'Entry points for ' . $method_id;
        if (empty(self::$_method_call_index[$method_id])) {
            list($absolute_class, $method_name) = explode('::', $method_id);

            $reflection_class = new \ReflectionClass($absolute_class);
            $parent_class = $reflection_class->getParentClass();

            if ($parent_class) {
                try {
                    $parent_class->getMethod($method_name);
                    $method_id = $parent_class->getName() . '::' . $method_name;
                    return $output . ' - NONE - it extends ' . $method_id . ' though';
                }
                catch (\ReflectionException $e) {
                    // do nothing
                }
            }

            return $output . ' - NONE';
        }

        $parents = self::$_method_call_index[$method_id];
        $ignore = [$method_id];
        $entry_points = [];

        while (!empty($parents)) {
            $parent_method_id = array_shift($parents);
            $ignore[] = $parent_method_id;
            $new_parents = self::_findParents($parent_method_id, $ignore);

            if ($new_parents === null) {
                $entry_points[] = $parent_method_id;
            }
            else {
                $parents = array_merge($parents, $new_parents);
            }
        }

        $entry_points = array_unique($entry_points);

        if (count($entry_points) > 20) {
            return $output . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', array_slice($entry_points, 0, 20)) . ' and more...';
        }

        return $output . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $entry_points);
    }

    protected static function _findParents($method_id, array $ignore)
    {
        if (empty(self::$_method_call_index[$method_id])) {
            return null;
        }

        return array_diff(array_unique(self::$_method_call_index[$method_id]), $ignore);
    }

    protected static function _getPathTo(PhpParser\Node\Expr $stmt, $file_name)
    {
        if ($file_name[0] !== '/') {
            $file_name = getcwd() . '/' . $file_name;
        }

        if ($stmt instanceof PhpParser\Node\Scalar\String_) {
            return $stmt->value;

        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $left_string = self::_getPathTo($stmt->left, $file_name);
            $right_string = self::_getPathTo($stmt->right, $file_name);

            if ($left_string && $right_string) {
                return $left_string . $right_string;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->name instanceof PhpParser\Node\Name &&
            $stmt->name->parts === ['dirname']) {

            if ($stmt->args) {
                $evaled_path = self::_getPathTo($stmt->args[0]->value, $file_name);

                if (!$evaled_path) {
                    return;
                }

                return dirname($evaled_path);
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch && $stmt->name instanceof PhpParser\Node\Name) {
            $const_name = implode('', $stmt->name->parts);

            if (defined($const_name)) {
                return constant($const_name);
            }

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\Dir) {
            return dirname($file_name);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\File) {
            return $file_name;
        }

        return null;
    }

    /**
     * @return string
     */
    protected static function _resolveIncludePath($file_name, $current_directory)
    {
        $paths = PATH_SEPARATOR == ':' ?
            preg_split('#(?<!phar):#', get_include_path()) :
            explode(PATH_SEPARATOR, get_include_path());

        foreach ($paths as $prefix) {
            $ds = substr($prefix, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;

            if ($prefix === '.') {
                $prefix = $current_directory;
            }

            $file = $prefix . $ds . $file_name;

            if (file_exists($file)) {
                return $file;
            }
        }
    }

    public static function setMockInterfaces(array $classes)
    {
        self::$_mock_interfaces = $classes;
    }

    public static function isMock($absolute_class)
    {
        return in_array($absolute_class, self::$_mock_interfaces);
    }

    /**
     * @return bool
     */
    protected static function _containsBooleanOr(PhpParser\Node\Expr\BinaryOp $stmt)
    {
        // we only want to discount expressions where either the whole thing is an or
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            return true;
        }

        // or both sides are ors
        if (($stmt->left instanceof PhpParser\Node\Expr\BinaryOp && $stmt->left instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) &&
            ($stmt->right instanceof PhpParser\Node\Expr\BinaryOp && $stmt->left instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr)) {
            return true;
        }

        return false;
    }

    public function getAliasedClasses()
    {
        return $this->_aliased_classes;
    }

    public static function getThisAssignments($method_id, $include_constructor = false)
    {
        $absolute_class = explode('::', $method_id)[0];

        $this_assignments = [];

        if ($include_constructor && isset(self::$_this_assignments[$absolute_class . '::__construct'])) {
            $this_assignments = self::$_this_assignments[$absolute_class . '::__construct'];
        }

        if (isset(self::$_this_assignments[$method_id])) {
            $this_assignments = TypeChecker::combineTypes($this_assignments, self::$_this_assignments[$method_id]);
        }

        if (isset(self::$_this_calls[$method_id])) {
            foreach (self::$_this_calls[$method_id] as $call) {
                $call_assingments = self::getThisAssignments($absolute_class . '::' . $call);
                $this_assignments = TypeChecker::combineTypes($this_assignments, $call_assingments);
            }
        }

        return $this_assignments;
    }
}
