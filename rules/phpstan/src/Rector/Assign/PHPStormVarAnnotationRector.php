<?php

declare(strict_types=1);

namespace Rector\PHPStan\Rector\Assign;

use Nette\Utils\Strings;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * @see https://github.com/shopsys/shopsys/pull/524
 * @see \Rector\PHPStan\Tests\Rector\Assign\PHPStormVarAnnotationRector\PHPStormVarAnnotationRectorTest
 */
final class PHPStormVarAnnotationRector extends AbstractRector
{
    /**
     * @var string
     */
    private const SINGLE_ASTERISK_COMMENT_START_REGEX = '#^\/\* #';

    /**
     * @var string
     */
    private const VAR_ANNOTATION_REGEX = '#\@var(\s)+\$#';

    /**
     * @var string
     */
    private const VARIABLE_NAME_AND_TYPE_MATCH_REGEX = '#(?<variableName>\$\w+)(?<space>\s+)(?<type>[\\\\\w]+)#';

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Change various @var annotation formats to one PHPStorm understands', [
            new CodeSample(
                <<<'CODE_SAMPLE'
$config = 5;
/** @var \Shopsys\FrameworkBundle\Model\Product\Filter\ProductFilterConfig $config */
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
/** @var \Shopsys\FrameworkBundle\Model\Product\Filter\ProductFilterConfig $config */
$config = 5;
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Assign::class];
    }

    /**
     * @param Assign $node
     */
    public function refactor(Node $node): ?Node
    {
        /** @var Expression|null $expression */
        $expression = $node->getAttribute(AttributeKey::CURRENT_STATEMENT);

        // unable to analyze
        if ($expression === null) {
            return null;
        }

        /** @var Node|null $nextNode */
        $nextNode = $expression->getAttribute(AttributeKey::NEXT_NODE);
        if ($nextNode === null) {
            return null;
        }

        $docContent = $this->getDocContent($nextNode);
        if ($docContent === '') {
            return null;
        }

        if (! Strings::contains($docContent, '@var')) {
            return null;
        }

        if (! $node->var instanceof Variable) {
            return null;
        }

        $varName = '$' . $this->getName($node->var);
        $varPattern = '# ' . preg_quote($varName, '#') . ' #';
        if (! Strings::match($docContent, $varPattern)) {
            return null;
        }

        // switch docs
        $expression->setDocComment($this->createDocComment($nextNode));

        $expressionPhpDocInfo = $this->phpDocInfoFactory->createFromNode($expression);
        $expression->setAttribute(AttributeKey::PHP_DOC_INFO, $expressionPhpDocInfo);

        // invoke override
        $expression->setAttribute(AttributeKey::ORIGINAL_NODE, null);

        // remove otherwise empty node
        if ($nextNode instanceof Nop) {
            $this->removeNode($nextNode);
            return null;
        }

        // remove commnets
        $nextNode->setAttribute(AttributeKey::PHP_DOC_INFO, null);
        $nextNode->setAttribute(AttributeKey::COMMENTS, null);

        return $node;
    }

    private function getDocContent(Node $node): string
    {
        if ($node->getDocComment() !== null) {
            return $node->getDocComment()
                ->getText();
        }

        if ($node->getComments() !== []) {
            $docContent = '';
            foreach ($node->getComments() as $comment) {
                $docContent .= $comment->getText();
            }

            return $docContent;
        }

        return '';
    }

    private function createDocComment(Node $node): Doc
    {
        if ($node->getDocComment() !== null) {
            return $node->getDocComment();
        }

        $docContent = $this->getDocContent($node);

        // normalize content

        // starts with "/*", instead of "/**"
        if (Strings::startsWith($docContent, '/* ')) {
            $docContent = Strings::replace($docContent, self::SINGLE_ASTERISK_COMMENT_START_REGEX, '/** ');
        }

        // $value is first, instead of type is first
        if (Strings::match($docContent, self::VAR_ANNOTATION_REGEX)) {
            $docContent = Strings::replace($docContent, self::VARIABLE_NAME_AND_TYPE_MATCH_REGEX, '$3$2$1');
        }

        return new Doc($docContent);
    }
}
