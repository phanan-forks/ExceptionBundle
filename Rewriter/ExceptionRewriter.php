<?php
namespace InterNations\Bundle\ExceptionBundle\Rewriter;

use SplFileObject;
use PHPParser_Parser as Parser;
use PHPParser_Lexer as Lexer;
use PHPParser_NodeTraverser as NodeTraverser;
use PHPParser_NodeVisitor_NameResolver as NameResolverVisitor;
use PHPParser_NodeAbstract as AbstractNode;
use PHPParser_Node_Stmt_Use as UseStmt;
use InterNations\Bundle\ExceptionBundle\Visitor\ExceptionVisitor;

class ExceptionRewriter
{
    /**
     * @var array
     */
    private $bundleExceptions = [];

    /**
     * @var array
     */
    private $specializedExceptions = [];

    /**
     * @var string
     */
    private $bundleNamespace;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @param string $bundleNamespace
     */
    public function __construct($bundleNamespace)
    {
        $this->bundleNamespace = $bundleNamespace;
        $this->parser = new Parser(new Lexer());
    }

    public function registerBundleException($exceptionClassName)
    {
        $this->bundleExceptions[] = $exceptionClassName;
        $this->specializedExceptions[] = substr($exceptionClassName, strrpos($exceptionClassName, '\\') + 1);
    }

    public function rewrite(SplFileObject $file)
    {
        $lines = [];
        $buffer = '';
        while (!$file->eof()) {
            $buffer .= $lines[] = $file->fgets();
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolverVisitor());
        $exceptionVisitor = new ExceptionVisitor();
        $traverser->addVisitor($exceptionVisitor);

        $traverser->traverse($this->parser->parse($buffer));

        $useStatementsProcessed = [];
        foreach ($exceptionVisitor->getThrowStatements(['PHPParser_Node_Expr_New', 'PHPParser_Node_Expr_StaticCall'], '\\') as $throwStmt) {

            $exceptionClassName = $throwStmt->expr->class->toString();

            if (!$this->hasSpecializedException($exceptionClassName)) {
                continue;
            }

            $useStatementFound = false;
            /** @var $useStmt UseStmt */
            foreach ($exceptionVisitor->getUseStatements() as $useStmt) {
                foreach ($useStmt->uses as $usageStmt) {
                    if ($usageStmt->name->toString() === $exceptionClassName) {
                        $useStatementFound = true;
                        break 2;
                    }
                }
            }

            $namespaceStmt = $throwStmt->getAttribute('namespace');
            $throwPosition = $this->getArrayPosition($throwStmt->expr->class);
            $replacement = $namespaceStmt ? $exceptionClassName : $this->prependNamespace($exceptionClassName);
            $lines[$throwPosition] = preg_replace($this->getRegex($exceptionClassName), $replacement, $lines[$throwPosition]);

            if (!$useStatementFound && $namespaceStmt && !in_array($exceptionClassName, $useStatementsProcessed)) {

                $namespacePosition = $this->getArrayPosition($throwStmt->getAttribute('namespace'));
                $lines[$namespacePosition] .= sprintf("\nuse %s;", $this->prependNamespace($exceptionClassName));
                $useStatementsProcessed[] = $exceptionClassName;

            } elseif ($useStatementFound && !in_array($exceptionClassName, $useStatementsProcessed)) {

                $usagePosition = $this->getArrayPosition($usageStmt->name);
                $lines[$usagePosition] = preg_replace(
                    $this->getRegex($usageStmt->name->toString()),
                    $this->prependNamespace($exceptionClassName),
                    $lines[$usagePosition],
                    1
                );
                $useStatementsProcessed[] = $exceptionClassName;

            }
        }

        $file->seek(0);
        $file->fwrite(join('', $lines));
    }

    protected function getBundleExceptionNamespace()
    {
        return $this->bundleNamespace . '\\' . 'Exception';
    }

    protected function prependNamespace($className)
    {
        return sprintf('%s\\%s', $this->getBundleExceptionNamespace(), $className);
    }

    protected function getRegex($name)
    {
        return '/\\\\?' . preg_quote($name, '/') . '/';
    }

    protected function getArrayPosition(AbstractNode $node)
    {
        $startLine = $node->getAttribute('startLine');
        $endLine = $node->getAttribute('endLine');

        assert($startLine === $endLine);
        assert($startLine - 1 > 0);

        return $startLine - 1;
    }

    protected function hasSpecializedException($exceptionClassName)
    {
        return in_array($exceptionClassName, $this->specializedExceptions);
    }
}
