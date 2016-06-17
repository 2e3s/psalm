<?php

namespace CodeInspector;

use CodeInspector\Type\Atomic;
use CodeInspector\Type\Generic;
use CodeInspector\Type\Union;
use CodeInspector\Type\ParseTree;

abstract class Type
{
    /**
     * Parses a string type representation
     * @param  string $string
     * @return self
     */
    public static function parseString($type_string, $enclose_with_union = true)
    {
        $type_tokens = TypeChecker::tokenize($type_string);

        if (count($type_tokens) === 1) {
            $parsed_type = new Atomic($type_tokens[0]);

            if ($enclose_with_union) {
                $parsed_type = new Union([$parsed_type]);
            }

            return $parsed_type;
        }

        // We construct a parse tree corresponding to the type
        $parse_tree = new ParseTree(null, null);

        $current_leaf = $parse_tree;

        while ($type_tokens) {
            $type_token = array_shift($type_tokens);

            switch ($type_token) {
                case '<':
                    $current_parent = $current_leaf->parent;
                    $new_parent_leaf = new ParseTree(ParseTree::GENERIC, $current_parent);
                    $new_parent_leaf->children = [$current_leaf];
                    $current_leaf->parent = $new_parent_leaf;

                    if ($current_parent) {
                        array_pop($current_parent->children);
                        $current_parent->children[] = $new_parent_leaf;
                    }
                    else {
                        $parse_tree = $new_parent_leaf;
                    }

                    break;

                case '>':
                    while ($current_leaf->value !== ParseTree::GENERIC) {
                        if ($current_leaf->parent === null) {
                            throw new \InvalidArgumentException('Cannot parse generic type');
                        }

                        $current_leaf = $current_leaf->parent;
                    }

                    break;

                case ',':
                    if ($current_parent->value !== ParseTree::GENERIC) {
                        throw new \InvalidArgumentException('Cannot parse comma in non-generic type');
                    }
                    break;

                case '|':
                    $current_parent = $current_leaf->parent;

                    if ($current_parent && $current_parent->value === ParseTree::UNION) {
                        continue;
                    }

                    $new_parent_leaf = new ParseTree(ParseTree::UNION, $current_parent);
                    $new_parent_leaf->children = [$current_leaf];
                    $current_leaf->parent = $new_parent_leaf;

                    if ($current_parent) {
                        array_pop($current_parent->children);
                        $current_parent->children[] = $new_parent_leaf;
                    }
                    else {
                        $parse_tree = $new_parent_leaf;
                    }

                    break;

                default:
                    if ($current_leaf->value === null) {
                        $current_leaf->value = $type_token;
                        continue;
                    }

                    $new_leaf = new ParseTree($type_token, $current_leaf->parent);
                    $current_leaf->parent->children[] = $new_leaf;

                    $current_leaf = $new_leaf;
            }
        }

        $parsed_type = self::getTypeFromTree($parse_tree);

        if ($enclose_with_union && !($parsed_type instanceof Union)) {
            $parsed_type = new Union([$parsed_type]);
        }

        return $parsed_type;
    }

    private static function getTypeFromTree(ParseTree $parse_tree)
    {
        if ($parse_tree->value === ParseTree::GENERIC) {
            $generic_type = array_shift($parse_tree->children);

            $generic_params = array_map(
                function (ParseTree $child_tree) {
                    return self::getTypeFromTree($child_tree);
                },
                $parse_tree->children
            );

            if (!$generic_params) {
                throw new \InvalidArgumentException('No generic params provided for type');
            }

            $is_empty = count($generic_params) === 1 && $generic_params[0]->value === 'empty';

            return new Generic($generic_type->value, $generic_params, $is_empty);
        }

        if ($parse_tree->value === ParseTree::UNION) {
            $union_types = array_map(
                function (ParseTree $child_tree) {
                    return self::getTypeFromTree($child_tree);
                },
                $parse_tree->children
            );

            return new Union($union_types);
        }

        return new Atomic($parse_tree->value);
    }

    public static function getInt($enclose_with_union = true)
    {
        $type = new Atomic('int');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getString($enclose_with_union = true)
    {
        $type = new Atomic('string');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getNull($enclose_with_union = true)
    {
        $type = new Atomic('null');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getMixed($enclose_with_union = true)
    {
        $type = new Atomic('mixed');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getBool($enclose_with_union = true)
    {
        $type = new Atomic('bool');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getDouble($enclose_with_union = true)
    {
        $type = new Atomic('double');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getFloat($enclose_with_union = true)
    {
        $type = new Atomic('float');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getObject($enclose_with_union = true)
    {
        $type = new Atomic('object');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getArray($enclose_with_union = true)
    {
        $type = new Atomic('array');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getVoid($enclose_with_union = true)
    {
        $type = new Atomic('void');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public static function getFalse($enclose_with_union = true)
    {
        $type = new Atomic('false');

        if ($enclose_with_union) {
            return new Union([$type]);
        }

        return $type;
    }

    public function isMixed()
    {
        if ($this instanceof Atomic) {
            return $this->value === 'mixed';
        }

        if ($this instanceof Union) {
            return isset($this->types['mixed']);
        }
    }

    public function isNull()
    {
        if ($this instanceof Atomic) {
            return $this->value === 'null';
        }

        if ($this instanceof Union) {
            return count($this->types) === 1 && isset($this->types['null']);
        }
    }

    public function isVoid()
    {
        if ($this instanceof Atomic) {
            return $this->value === 'void';
        }

        if ($this instanceof Union) {
            return isset($this->types['void']);
        }
    }

    public function isNullable()
    {
        if ($this instanceof Atomic) {
            return $this->value === 'null';
        }

        if ($this instanceof Union) {
            return isset($this->types['null']);
        }

        return false;
    }

    public function isScalar()
    {
        if ($this instanceof Atomic) {
            return $this->value === 'int' ||
                    $this->value === 'string' ||
                    $this->value === 'double' ||
                    $this->value === 'float' ||
                    $this->value === 'bool' ||
                    $this->value === 'false';
        }
    }

    /**
     * Combines two union types into one
     * @param  Union  $type_1
     * @param  Union  $type_2
     * @return Union
     */
    public static function combineUnionTypes(Union $type_1, Union $type_2)
    {
        return self::combineTypes(array_merge(array_values($type_1->types), array_values($type_2->types)));
    }

    /**
     * Combines types together
     * so int + string = int|string
     * so array<int> + array<string> = array<int|string>
     * and array<int> + string = array<int>|string
     * and array<empty> + array<empty> = array<empty>
     * and array<string> + array<empty> = array<string>
     * and array + array<string> = array<mixed>
     *
     * @param  array<Atomic>    $types
     * @return Union
     */
    public static function combineTypes(array $types)
    {
        if (in_array(null, $types)) {
            return Type::getMixed();
        }

        if (count($types) === 1) {
            if ($types[0]->value === 'false') {
                $types[0]->value = 'bool';
            }

            return new Union([$types[0]]);
        }

        if (!$types) {
            throw new \InvalidArgumentException('You must pass at least one type to combineTypes');
        }

        $value_types = [];

        foreach ($types as $type) {
            if ($type instanceof Union) {
                throw new \InvalidArgumentException('Union type not expected here');
            }

            // if we see the magic empty value and there's more than one type, ignore it
            if ($type->value === 'empty') {
                continue;
            }

            if ($type->value === 'mixed') {
                return Type::getMixed();
            }

            if ($type->value === 'void') {
                $type->value = 'null';
            }

            // deal with false|bool => bool
            if ($type->value === 'false' && isset($value_types['bool'])) {
                continue;
            }
            elseif ($type->value === 'bool' && isset($value_types['false'])) {
                unset($value_types['false']);
            }

            if (!isset($value_types[$type->value])) {
                $value_types[$type->value] = [];
            }

            // @todo this doesn't support multiple type params right now
            $value_types[$type->value][(string) $type] = $type instanceof Generic ? $type->type_params[0] : null;
        }

        if (count($value_types) === 1) {
            if (isset($value_types['false'])) {
                return self::getBool();
            }
        }

        $new_types = [];

        foreach ($value_types as $key => $value_type) {
            if (count($value_type) === 1) {
                $value_type_param = array_values($value_type)[0];
                $new_types[] = $value_type_param ? new Generic($key, [$value_type_param]) : new Atomic($key);
                continue;
            }

            $expanded_value_types = [];

            foreach ($value_types[$key] as $expandable_value_type) {
                if ($expandable_value_type instanceof Union) {
                    $expanded_value_types = array_merge($expanded_value_types, $expandable_value_type->types);
                    continue;
                }

                $expanded_value_types[] = $expandable_value_type;
            }

            // we have a generic type with
            $new_types[] = new Generic($key, [self::combineTypes($expanded_value_types)]);
        }

        $new_types = array_values($new_types);
        return new Union($new_types);
    }
}
