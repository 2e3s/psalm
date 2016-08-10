<?php

namespace Psalm;

use Psalm\Issue\InvalidArgument;
use Psalm\Issue\FailedTypeResolution;
use Psalm\IssueBuffer;
use PhpParser;

class TypeChecker
{
    protected $_absolute_class;
    protected $_namespace;
    protected $_checker;
    protected $_check_nulls;

    const ASSIGNMENT_TO_RIGHT = 1;
    const ASSIGNMENT_TO_LEFT = -1;

    public function __construct(StatementsSource $source, StatementsChecker $statements_checker)
    {
        $this->_absolute_class = $source->getAbsoluteClass();
        $this->_namespace = $source->getNamespace();
        $this->_checker = $statements_checker;
    }

    /**
     * Gets all the type assertions in a conditional that are && together
     * @param  PhpParser\Node\Expr $conditional [description]
     * @return array<string>
     */
    public function getReconcilableTypeAssertions(PhpParser\Node\Expr $conditional)
    {
        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            $left_assertions = $this->getReconcilableTypeAssertions($conditional->left);
            $right_assertions = $this->getReconcilableTypeAssertions($conditional->right);

            $keys = array_intersect(array_keys($left_assertions), array_keys($right_assertions));

            $if_types = [];

            foreach ($keys as $key) {
                if ($left_assertions[$key][0] !== '!' && $right_assertions[$key][0] !== '!') {
                    $if_types[$key] = $left_assertions[$key] . '|' . $right_assertions[$key];
                }
            }

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd) {
            $left_assertions = $this->getReconcilableTypeAssertions($conditional->left);
            $right_assertions = $this->getReconcilableTypeAssertions($conditional->right);

            return self::combineTypeAssertions($left_assertions, $right_assertions);
        }

        return $this->getTypeAssertions($conditional);
    }

    public function getNegatableTypeAssertions(PhpParser\Node\Expr $conditional)
    {
        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd) {
            return [];
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            $left_assertions = $this->getNegatableTypeAssertions($conditional->left);
            $right_assertions = $this->getNegatableTypeAssertions($conditional->right);

            return self::combineTypeAssertions($left_assertions, $right_assertions);
        }

        return $this->getTypeAssertions($conditional);
    }

    private static function combineTypeAssertions(array $left_assertions, array $right_assertions)
    {
        $keys = array_merge(array_keys($left_assertions), array_keys($right_assertions));
        $keys = array_unique($keys);

        $if_types = [];

        foreach ($keys as $key) {
            if (isset($left_assertions[$key]) && isset($right_assertions[$key])) {
                if ($left_assertions[$key][0] !== '!' && $right_assertions[$key][0] !== '!') {
                    $if_types[$key] = $left_assertions[$key] . '&' . $right_assertions[$key];
                }
                else {
                    $if_types[$key] = $right_assertions[$key];
                }
            }
            else if (isset($left_assertions[$key])) {
                $if_types[$key] = $left_assertions[$key];
            }
            else {
                $if_types[$key] = $right_assertions[$key];
            }
        }

        return $if_types;
    }

    /**
     * Gets all the type assertions in a conditional
     *
     * @param  PhpParser\Node\Expr $conditional
     * @return array
     */
    public function getTypeAssertions(PhpParser\Node\Expr $conditional)
    {
        $if_types = [];

        if ($conditional instanceof PhpParser\Node\Expr\Instanceof_) {
            $instanceof_type = $this->_getInstanceOfTypes($conditional);

            if ($instanceof_type) {
                $var_name = StatementsChecker::getVarId($conditional->expr);
                if ($var_name) {
                    $if_types[$var_name] = $instanceof_type;
                }
            }
        }
        else if ($var_name = StatementsChecker::getVarId($conditional)) {
            $if_types[$var_name] = '!empty';
        }
        else if ($conditional instanceof PhpParser\Node\Expr\Assign) {
            $var_name = StatementsChecker::getVarId($conditional->var);
            $if_types[$var_name] = '!empty';
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BooleanNot) {
            if ($conditional->expr instanceof PhpParser\Node\Expr\Instanceof_) {
                $instanceof_type = $this->_getInstanceOfTypes($conditional->expr);

                if ($instanceof_type) {
                    $var_name = StatementsChecker::getVarId($conditional->expr->expr);
                    if ($var_name) {
                        $if_types[$var_name] = '!' . $instanceof_type;
                    }
                }
            }
            else if ($var_name = StatementsChecker::getVarId($conditional->expr)) {
                $if_types[$var_name] = 'empty';
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\Assign) {
                $var_name = StatementsChecker::getVarId($conditional->expr->var);
                $if_types[$var_name] = 'empty';
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Identical || $conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Equal) {
                $null_position = self::_hasNullVariable($conditional->expr);
                $false_position = self::_hasNullVariable($conditional->expr);

                if ($null_position !== null) {
                    if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = StatementsChecker::getVarId($conditional->expr->left);
                    }
                    else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = StatementsChecker::getVarId($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                            $if_types[$var_name] = '!null';
                        }
                        else {
                            // we do this because == null gives us a weaker idea than === null
                            $if_types[$var_name] = '!empty';
                        }
                    }
                }
                elseif ($false_position !== null) {
                    if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = StatementsChecker::getVarId($conditional->expr->left);
                    }
                    else if ($false_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = StatementsChecker::getVarId($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                            $if_types[$var_name] = '!false';
                        }
                        else {
                            // we do this because == null gives us a weaker idea than === null
                            $if_types[$var_name] = '!empty';
                        }
                    }
                }
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical || $conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\NotEqual) {
                $null_position = self::_hasNullVariable($conditional->expr);
                $false_position = self::_hasNullVariable($conditional->expr);

                if ($null_position !== null) {
                    if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = StatementsChecker::getVarId($conditional->expr->left);
                    }
                    else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = StatementsChecker::getVarId($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                            $if_types[$var_name] = 'null';
                        }
                        else {
                            $if_types[$var_name] = 'empty';
                        }
                    }
                }
                elseif ($false_position !== null) {
                    if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = StatementsChecker::getVarId($conditional->expr->left);
                    }
                    else if ($false_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = StatementsChecker::getVarId($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                            $if_types[$var_name] = 'false';
                        }
                        else {
                            // we do this because == null gives us a weaker idea than === null
                            $if_types[$var_name] = 'empty';
                        }
                    }
                }
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\Empty_) {
                $var_name = StatementsChecker::getVarId($conditional->expr->expr);

                if ($var_name) {
                    $if_types[$var_name] = '!empty';
                }
            }
            else if (self::_hasNullCheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!null';
            }
            else if (self::_hasIsACheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!' . $conditional->expr->args[1]->value->value;
            }
            else if (self::_hasArrayCheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!array';
            }
            else if (self::_hasBoolCheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!bool';
            }
            else if (self::_hasStringCheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!string';
            }
            else if (self::_hasObjectCheck($conditional->expr)) {
                $var_name = StatementsChecker::getVarId($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!object';
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\Isset_) {
                foreach ($conditional->expr->vars as $isset_var) {
                    $var_name = StatementsChecker::getVarId($isset_var);
                    if ($var_name) {
                        $if_types[$var_name] = 'null';
                    }
                }
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal) {
            $null_position = self::_hasNullVariable($conditional);
            $false_position = self::_hasFalseVariable($conditional);

            if ($null_position !== null) {
                if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = StatementsChecker::getVarId($conditional->left);
                }
                else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = StatementsChecker::getVarId($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                        $if_types[$var_name] = 'null';
                    }
                    else {
                        $if_types[$var_name] = 'empty';
                    }
                }
            }
            elseif ($false_position) {
                if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = StatementsChecker::getVarId($conditional->left);
                }
                else if ($false_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = StatementsChecker::getVarId($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                        $if_types[$var_name] = 'false';
                    }
                    else {
                        $if_types[$var_name] = 'empty';
                    }
                }
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical || $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotEqual) {
            $null_position = self::_hasNullVariable($conditional);
            $false_position = self::_hasFalseVariable($conditional);

            if ($null_position !== null) {
                if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = StatementsChecker::getVarId($conditional->left);
                }
                else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = StatementsChecker::getVarId($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                        $if_types[$var_name] = '!null';
                    }
                    else {
                        $if_types[$var_name] = '!empty';
                    }
                }
            }
            elseif ($false_position) {
                if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = StatementsChecker::getVarId($conditional->left);
                }
                else if ($false_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = StatementsChecker::getVarId($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                        $if_types[$var_name] = '!false';
                    }
                    else {
                        $if_types[$var_name] = '!empty';
                    }
                }
            }
        }
        else if (self::_hasNullCheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = 'null';
        }
        else if (self::_hasIsACheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = $conditional->args[1]->value->value;
        }
        else if (self::_hasArrayCheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = 'array';
        }
        else if (self::_hasStringCheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = 'string';
        }
        else if (self::_hasBoolCheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = 'bool';
        }
        else if (self::_hasObjectCheck($conditional)) {
            $var_name = StatementsChecker::getVarId($conditional->args[0]->value);
            $if_types[$var_name] = 'object';
        }
        else if ($conditional instanceof PhpParser\Node\Expr\Empty_) {
            $var_name = StatementsChecker::getVarId($conditional->expr);
            if ($var_name) {
                $if_types[$var_name] = 'empty';
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($conditional->vars as $isset_var) {
                $var_name = StatementsChecker::getVarId($isset_var);
                if ($var_name) {
                    $if_types[$var_name] = '!null';
                }
            }
        }

        return $if_types;
    }

    protected function _getInstanceOfTypes(PhpParser\Node\Expr\Instanceof_ $stmt)
    {
        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                $instanceof_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_checker->getAliasedClasses());
                return $instanceof_class;

            } elseif ($stmt->class->parts === ['self']) {
                return $this->_absolute_class;
            }
        }

        return null;
    }

    protected static function _hasNullVariable(PhpParser\Node\Expr $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->right->name instanceof PhpParser\Node\Name &&
            $conditional->right->name->parts === ['null']) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->left->name instanceof PhpParser\Node\Name &&
            $conditional->left->name->parts === ['null']) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    protected static function _hasFalseVariable(PhpParser\Node\Expr $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->right->name instanceof PhpParser\Node\Name &&
            $conditional->right->name->parts === ['false']) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->left->name instanceof PhpParser\Node\Name &&
            $conditional->left->name->parts === ['false']) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @return bool
     */
    protected static function _hasNullCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_null']) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected static function _hasIsACheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_a'] &&
            $stmt->args[1]->value instanceof PhpParser\Node\Scalar\String_) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected static function _hasArrayCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_array']) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected static function _hasStringCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_string']) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected static function _hasBoolCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_bool']) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected static function _hasObjectCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_object']) {
            return true;
        }

        return false;
    }

    /**
     * Takes two arrays and consolidates them, removing null values from existing types where applicable
     *
     * @param  array  $new_types
     * @param  array  $existing_types
     * @return array|false
     */
    public static function reconcileKeyedTypes(array $new_types, array $existing_types, $file_name, $line_number, array $suppressed_issues = null)
    {
        $keys = array_merge(array_keys($new_types), array_keys($existing_types));
        $keys = array_unique($keys);

        $result_types = [];

        if (empty($new_types)) {
            return $existing_types;
        }

        foreach ($keys as $key) {
            if (!isset($new_types[$key])) {
                $result_types[$key] = $existing_types[$key];
                continue;
            }

            $new_type_parts = explode('&', $new_types[$key]);

            $result_type = isset($existing_types[$key]) ? clone $existing_types[$key] : null;

            foreach ($new_type_parts as $new_type_part) {
                $result_type = self::reconcileTypes(
                    (string) $new_type_part,
                    $result_type,
                    $key,
                    $file_name,
                    $line_number,
                    $suppressed_issues
                );
            }

            //echo((string) $new_types[$key] . ' and ' . (isset($existing_types[$key]) ? (string) $existing_types[$key] : '') . ' => ' . $result_type . PHP_EOL);

            if ($result_type === false) {
                return false;
            }

            $result_types[$key] = $result_type;
        }

        return $result_types;
    }

    /**
     * Reconciles types
     *
     * think of this as a set of functions e.g. empty(T), notEmpty(T), null(T), notNull(T) etc. where
     * empty(Object) => null,
     * empty(bool) => false,
     * notEmpty(Object|null) => Object,
     * notEmpty(Object|false) => Object
     *
     * @param  string       $new_var_type
     * @param  Type\Union   $existing_var_type
     * @param  string       $key
     * @param  string       $file_name
     * @param  int          $line_number
     * @return Type\Union|false
     */
    public static function reconcileTypes($new_var_type, Type\Union $existing_var_type = null, $key = null, $file_name = null, $line_number = null, array $suppressed_issues = null)
    {
        $result_var_types = null;

        if (!$existing_var_type) {
            return Type::getMixed();
        }

        if ($new_var_type === 'mixed' && $existing_var_type->isMixed()) {
            return $existing_var_type;
        }

        if ($new_var_type === 'null') {
            return Type::getNull();
        }

        if ($new_var_type[0] === '!') {
            if (in_array($new_var_type, ['!empty', '!null'])) {
                $existing_var_type->removeType('null');

                if ($new_var_type === '!empty') {
                    $existing_var_type->removeType('false');
                }

                if (empty($existing_var_type->types)) {
                    // @todo - I think there's a better way to handle this, but for the moment
                    // mixed will have to do.
                    return Type::getMixed();
                }

                return $existing_var_type;
            }

            $negated_type = substr($new_var_type, 1);

            $existing_var_type->removeType($negated_type);

            if (empty($existing_var_type->types)) {
                if ($key) {
                    if (IssueBuffer::accepts(
                        new FailedTypeResolution('Cannot resolve types for ' . $key, $file_name, $line_number),
                        $suppressed_issues
                    )) {
                        return false;
                    }

                    return Type::getMixed();
                }
            }

            return $existing_var_type;
        }

        if ($new_var_type === 'empty') {
            if ($existing_var_type->hasType('bool')) {
                $existing_var_type->removeType('bool');
                $existing_var_type->types['false'] = Type::getFalse(false);
            }

            $existing_var_type->removeObjects();

            if (empty($existing_var_type->types)) {
                return Type::getNull();
            }

            return $existing_var_type;
        }

        return Type::parseString($new_var_type);
    }

    public static function isNegation($type, $existing_type)
    {
        if ($type === 'mixed' || $existing_type === 'mixed') {
            return false;
        }

        if ($type === '!' . $existing_type || $existing_type === '!' . $type) {
            return true;
        }

        if (in_array($type, ['empty', 'false', 'null']) && !in_array($existing_type, ['empty', 'false', 'null'])) {
            return true;
        }

        if (in_array($existing_type, ['empty', 'false', 'null']) && !in_array($type, ['empty', 'false', 'null'])) {
            return true;
        }

        return false;
    }

    /**
     * Takes two arrays of types and merges them
     *
     * @param  array<UnionType>  $new_types
     * @param  array<UnionType>  $existing_types
     * @return array
     */
    public static function combineKeyedTypes(array $new_types, array $existing_types)
    {
        $keys = array_merge(array_keys($new_types), array_keys($existing_types));
        $keys = array_unique($keys);

        $result_types = [];

        if (empty($new_types)) {
            return $existing_types;
        }

        if (empty($existing_types)) {
            return $new_types;
        }

        foreach ($keys as $key) {
            if (!isset($existing_types[$key])) {
                $result_types[$key] = $new_types[$key];
                continue;
            }

            if (!isset($new_types[$key])) {
                $result_types[$key] = $existing_types[$key];
                continue;
            }

            $existing_var_types = $existing_types[$key];
            $new_var_types = $new_types[$key];

            if ((string) $new_var_types === (string) $existing_var_types) {
                $result_types[$key] = $new_var_types;
            }
            else {
                $result_types[$key] = Type::combineUnionTypes($new_var_types, $existing_var_types);
            }
        }

        return $result_types;
    }

    public static function reduceTypes(array $all_types)
    {
        if (in_array('mixed', $all_types)) {
            return ['mixed'];
        }

        $array_types = array_filter($all_types, function($type) {
            return preg_match('/^array(\<|$)/', $type);
        });

        $all_types = array_flip($all_types);

        if (isset($all_types['array<empty>']) && count($array_types) > 1) {
            unset($all_types['array<empty>']);
        }

        if (isset($all_types['array<mixed>'])) {
            unset($all_types['array<mixed>']);

            $all_types['array'] = true;
        }

        return array_keys($all_types);
    }

    public static function negateTypes(array $types)
    {
        return array_map(function ($type) {
            if ($type === 'mixed') {
                return $type;
            }

            $type_parts = explode('&', $type);

            foreach ($type_parts as &$type_part) {
                $type_part = $type_part[0] === '!' ? substr($type_part, 1) : '!' . $type_part;
            }

            return implode('&', $type_parts);
        }, $types);
    }
}
