<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\CodeLocation;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Context;
use Psalm\Type;

class TryChecker
{
    /**
     * @param   StatementsChecker               $statements_checker
     * @param   PhpParser\Node\Stmt\TryCatch    $stmt
     * @param   Context                         $context
     * @param   Context|null                    $loop_context
     * @return  false|null
     */
    public static function check(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\TryCatch $stmt,
        Context $context,
        Context $loop_context = null
    ) {
        $statements_checker->check($stmt->stmts, $context, $loop_context);

        // clone context for catches after running the try block, as
        // we optimistically assume it only failed at the very end
        $original_context = clone $context;

        foreach ($stmt->catches as $catch) {
            $catch_context = clone $original_context;

            $fq_catch_classes = [];

            foreach ($catch->types as $catch_type) {
                $fq_catch_classes[]= ClassLikeChecker::getFQCLNFromNameObject(
                    $catch_type,
                    $statements_checker->getNamespace(),
                    $statements_checker->getAliasedClasses()
                );
            }

            if ($context->check_classes) {
                foreach ($fq_catch_classes as $fq_catch_class) {
                    if (ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $fq_catch_class,
                        new CodeLocation($statements_checker->getSource(), $stmt),
                        $statements_checker->getSuppressedIssues()
                    ) === false) {
                        return false;
                    }
                }
            }

            $catch_context->vars_in_scope['$' . $catch->var] = new Type\Union(
                array_map(
                    /**
                     * @param string $fq_catch_class
                     * @return Type\Atomic
                     */
                    function ($fq_catch_class) {
                        return new Type\Atomic($fq_catch_class);
                    },
                    $fq_catch_classes
                )
            );

            $catch_context->vars_possibly_in_scope['$' . $catch->var] = true;

            $statements_checker->registerVariable('$' . $catch->var, $catch->getLine());

            $statements_checker->check($catch->stmts, $catch_context, $loop_context);

            if (!ScopeChecker::doesAlwaysReturnOrThrow($catch->stmts)) {
                foreach ($catch_context->vars_in_scope as $catch_var => $type) {
                    if ($catch->var !== $catch_var &&
                        isset($context->vars_in_scope[$catch_var]) &&
                        (string) $context->vars_in_scope[$catch_var] !== (string) $type
                    ) {
                        $context->vars_in_scope[$catch_var] = Type::combineUnionTypes(
                            $context->vars_in_scope[$catch_var],
                            $type
                        );
                    }
                }

                $context->vars_possibly_in_scope = array_merge(
                    $catch_context->vars_possibly_in_scope,
                    $context->vars_possibly_in_scope
                );
            }
        }

        if ($stmt->finally) {
            $statements_checker->check($stmt->finally->stmts, $context, $loop_context);
        }

        return null;
    }
}
