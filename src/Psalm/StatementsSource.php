<?php

namespace Psalm;

interface StatementsSource
{
    public function getNamespace();

    public function getAliasedClasses();

    public function getAbsoluteClass();

    public function getClassName();

    public function getClassLikeChecker();

    /**
     * @return \PhpParser\Node\Name
     */
    public function getParentClass();

    public function getFileName();

    public function isStatic();

    public function getSource();

    /**
     * Get a list of suppressed issues
     * @return array
     */
    public function getSuppressedIssues();
}
