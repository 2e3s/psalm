<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Context;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Type;

class ForChecker
{
    /**
     * @param   StatementsChecker           $statements_checker
     * @param   PhpParser\Node\Stmt\For_    $stmt
     * @param   Context                     $context
     * @return  false|null
     */
    public static function check(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\For_ $stmt,
        Context $context
    ) {
        $for_context = clone $context;
        $for_context->in_loop = true;

        foreach ($stmt->init as $init) {
            if (ExpressionChecker::check($statements_checker, $init, $for_context) === false) {
                return false;
            }
        }

        foreach ($stmt->cond as $condition) {
            if (ExpressionChecker::check($statements_checker, $condition, $for_context) === false) {
                return false;
            }
        }

        foreach ($stmt->loop as $expr) {
            if (ExpressionChecker::check($statements_checker, $expr, $for_context) === false) {
                return false;
            }
        }

        $statements_checker->check($stmt->stmts, $for_context, $context);

        foreach ($context->vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($for_context->vars_in_scope[$var]->isMixed()) {
                $context->vars_in_scope[$var] = $for_context->vars_in_scope[$var];
            }

            if ((string) $for_context->vars_in_scope[$var] !== (string) $type) {
                $context->vars_in_scope[$var] = Type::combineUnionTypes(
                    $context->vars_in_scope[$var],
                    $for_context->vars_in_scope[$var]
                );
            }
        }

        $context->vars_possibly_in_scope = array_merge(
            $for_context->vars_possibly_in_scope,
            $context->vars_possibly_in_scope
        );

        return null;
    }
}
