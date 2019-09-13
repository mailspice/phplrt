<?php
/**
 * This file is part of Phplrt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Phplrt\Parser;

use Phplrt\Contracts\Ast\NodeInterface;
use Phplrt\Contracts\Lexer\Exception\LexerExceptionInterface;
use Phplrt\Contracts\Lexer\Exception\RuntimeExceptionInterface as LexerRuntimeExceptionInterface;
use Phplrt\Contracts\Lexer\LexerInterface;
use Phplrt\Contracts\Lexer\TokenInterface;
use Phplrt\Contracts\Parser\ParserInterface;
use Phplrt\Lexer\Token\Renderer;
use Phplrt\Parser\Buffer\BufferInterface;
use Phplrt\Parser\Buffer\EagerBuffer;
use Phplrt\Parser\Builder\BuilderInterface;
use Phplrt\Parser\Builder\Common;
use Phplrt\Parser\Exception\ParserRuntimeException;
use Phplrt\Parser\Rule\ProductionInterface;
use Phplrt\Parser\Rule\RuleInterface;
use Phplrt\Parser\Rule\TerminalInterface;
use Phplrt\Source\Exception\NotAccessibleException;
use Phplrt\Source\File;
use Phplrt\Source\ReadableInterface;

/**
 * A recurrence recursive descent parser implementation.
 *
 * Is a kind of top-down parser built from a set of mutually recursive methods
 * defined in:
 *  - Phplrt\Parser\Rule\ProductionInterface::reduce()
 *  - Phplrt\Parser\Rule\TerminalInterface::reduce()
 *
 * Where each such class implements one of the terminals or productions of the
 * grammar. Thus the structure of the resulting program closely mirrors that
 * of the grammar it recognizes.
 *
 * A "recurrence" means that instead of predicting, the parser simply tries to
 * apply all the alternative rules in order, until one of the attempts succeeds.
 *
 * Such a parser may require exponential work time, and does not always
 * guarantee completion, depending on the grammar.
 *
 * NOTE: Vulnerable to left recursion, like:
 *
 * <code>
 *      Digit = "0" | "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" ;
 *      Operator = "+" | "-" | "*" | "/" ;
 *      Number = Digit { Digit } ;
 *
 *      Expression = Number | Number Operator ;
 *      (*           ^^^^^^   ^^^^^^
 *          In this case, the grammar is incorrect and should be replaced by:
 *
 *          Expression = Number { Operator } ;
 *      *)
 * </code>
 */
class Parser implements ParserInterface
{
    /**
     * @var string
     */
    private const ERROR_XDEBUG_NOTICE_MESSAGE =
        'Please note that if Xdebug is enabled, a "Fatal error: Maximum function nesting level of "%d" ' .
        'reached, aborting!" errors may occur. In the second case, it is worth increasing the ini value ' .
        'or disabling the extension.';

    /**
     * Contains the readonly token object which was last successfully processed
     * in the rules chain.
     *
     * It is required so that in case of errors it is possible to report that
     * it was on it that the problem arose.
     *
     * Note: This is a stateful data and may cause a race condition error. In
     * the future, it is necessary to delete this data with a replacement for
     * the stateless structure.
     *
     * @var TokenInterface|null
     */
    private $token;

    /**
     * Contains the readonly NodeInterface object which was last successfully
     * processed while parsing.
     *
     * Note: This is a stateful data and may cause a race condition error. In
     * the future, it is necessary to delete this data with a replacement for
     * the stateless structure.
     *
     * @var NodeInterface|null
     */
    private $node;

    /**
     * @var BuilderInterface
     */
    private $builder;

    /**
     * @var string|int|null
     */
    private $initial;

    /**
     * @var LexerInterface
     */
    private $lexer;

    /**
     * @var array|RuleInterface[]
     */
    private $rules;

    /**
     * Parser constructor.
     *
     * @param LexerInterface $lexer
     * @param array|RuleInterface[] $rules
     * @param null $initial
     */
    public function __construct(LexerInterface $lexer, array $rules, $initial = null)
    {
        $this->lexer = $lexer;
        $this->rules = $rules;

        $this->builder = $this->getBuilder();
        $this->initial = $initial ?? $this->getInitialRule($this->rules);

        $this->boot();
    }

    /**
     * @return BuilderInterface
     */
    protected function getBuilder(): BuilderInterface
    {
        return new Common();
    }

    /**
     * {@inheritDoc}
     */
    protected function getInitialRule(array $rules)
    {
        return \count($rules) ? \array_key_first($rules) : 0;
    }

    /**
     * @return void
     */
    protected function boot(): void
    {
        if (\function_exists('\\xdebug_is_enabled')) {
            @\trigger_error(\vsprintf(self::ERROR_XDEBUG_NOTICE_MESSAGE, [
                \ini_get('xdebug.max_nesting_level'),
            ]));
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param string|resource|ReadableInterface|mixed $source
     * @throws \Throwable
     */
    public function parse($source): iterable
    {
        if (\count($this->rules) === 0) {
            return [];
        }

        return $this->run($this->open($source));
    }

    /**
     * @param ReadableInterface $source
     * @return iterable
     * @throws \Throwable
     */
    private function run(ReadableInterface $source): iterable
    {
        $buffer = $this->getBuffer($this->lex($source));

        $this->reset($buffer);

        return $this->parseOrFail($source, $buffer);
    }

    /**
     * @param \Generator $stream
     * @return BufferInterface
     */
    protected function getBuffer(\Generator $stream): BufferInterface
    {
        return new EagerBuffer($stream);
    }

    /**
     * @param ReadableInterface $source
     * @return \Generator
     * @throws LexerRuntimeExceptionInterface
     * @throws LexerExceptionInterface
     */
    protected function lex(ReadableInterface $source): \Generator
    {
        yield from $this->lexer->lex($source->getContents());
    }

    /**
     * @param BufferInterface $buffer
     * @return void
     */
    private function reset(BufferInterface $buffer): void
    {
        $this->token = $buffer->current();
        $this->node  = null;
    }

    /**
     * @param ReadableInterface $source
     * @param BufferInterface $buffer
     * @return iterable
     * @throws \Throwable
     */
    private function parseOrFail(ReadableInterface $source, BufferInterface $buffer): iterable
    {
        $result = $this->reduce($source, $buffer, $this->initial);

        if (\is_iterable($result) && $this->isEoi($buffer)) {
            return $result;
        }

        $message = \vsprintf(ParserRuntimeException::ERROR_UNEXPECTED_TOKEN, [
            $this->render($this->token ?? $buffer->current()),
        ]);

        throw new ParserRuntimeException($message, $this->token ?? $buffer->current());
    }

    /**
     * @param ReadableInterface $source
     * @param BufferInterface $buffer
     * @param int|string $state
     * @return iterable|TokenInterface|null
     */
    protected function reduce(ReadableInterface $source, BufferInterface $buffer, $state)
    {
        /** @var TokenInterface $token */
        [$rule, $result] = [$this->rules[$state], null];

        switch (true) {
            case $rule instanceof ProductionInterface:
                $result = $rule->reduce($buffer, $this->next($source, $buffer));

                break;

            case $rule instanceof TerminalInterface:
                if (($result = $rule->reduce($buffer)) !== null) {
                    $buffer->next();

                    $this->spotTerminal($buffer);

                    if (! $rule->isKeep()) {
                        return [];
                    }
                }

                break;
        }

        if ($result === null) {
            return null;
        }

        return $this->build($buffer, $state, $result);
    }

    /**
     * @param ReadableInterface $source
     * @param BufferInterface $buffer
     * @return \Closure
     */
    protected function next(ReadableInterface $source, BufferInterface $buffer): \Closure
    {
        return function ($state) use ($source, $buffer) {
            return $this->reduce($source, $buffer, $state);
        };
    }

    /**
     * Capture the most recently processed token.
     * In case of a syntax error, it will be displayed as incorrect.
     *
     * @param BufferInterface $buffer
     * @return void
     */
    private function spotTerminal(BufferInterface $buffer): void
    {
        if ($buffer->current()->getOffset() > $this->token->getOffset()) {
            $this->token = $buffer->current();
        }
    }

    /**
     * @param mixed $result
     * @param BufferInterface $buffer
     * @param int|string $state
     * @return mixed|null
     */
    private function build(BufferInterface $buffer, $state, $result)
    {
        $result = $this->builder->build($this->rules[$state], $buffer->current(), $state, $result) ?? $result;

        $this->spotProduction($result);

        return $result;
    }

    /**
     * @param mixed $result
     * @return void
     */
    private function spotProduction($result): void
    {
        if ($result instanceof NodeInterface) {
            $this->node = $result;
        }
    }

    /**
     * Matches a token identifier that marks the end of the source.
     *
     * @param BufferInterface $buffer
     * @return bool
     */
    protected function isEoi(BufferInterface $buffer): bool
    {
        $current = $buffer->current();

        return $current->getName() === TokenInterface::END_OF_INPUT;
    }

    /**
     * @param TokenInterface $token
     * @return string
     */
    private function render(TokenInterface $token): string
    {
        if (\class_exists(Renderer::class)) {
            return (new Renderer())->render($token);
        }

        return '"' . $token->getValue() . '" (' . $token->getName() . ')';
    }

    /**
     * @param string|resource|mixed $source
     * @return ReadableInterface
     * @throws NotAccessibleException
     * @throws \RuntimeException
     */
    private function open($source): ReadableInterface
    {
        return File::new($source);
    }
}
