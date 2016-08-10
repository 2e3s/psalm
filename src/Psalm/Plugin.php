<?php

namespace Psalm;

use PhpParser;

abstract class Plugin
{
    /**
     * checks an expression
     * @param  PhpParser\Node\Expr  $stmt
     * @param  Context              $context
     * @param  string               $file_name
     * @return null|false
     * @suppress InvalidReturnType
     */
    public function checkExpression(PhpParser\Node\Expr $stmt, Context $context, $file_name)
    {
        return;
    }

    /**
     * checks a statement
     * @param  PhpParser\Node       $stmt
     * @param  Context              $context
     * @param  string               $file_name
     * @return null|false
     * @suppress InvalidReturnType
     */
    public function checkStatement(PhpParser\Node $stmt, Context $context, $file_name)
    {
        return;
    }
}
