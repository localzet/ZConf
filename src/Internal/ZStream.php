<?php
/**
 * @package     Zorin Configuration Language
 * @link        https://github.com/localzet/zconf
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2018-2024 Zorin Projects S.P.
 * @license     http://opensource.org/licenses/MIT The MIT License
 *
 *              For the full copyright and license information, please view the LICENSE
 *              file that was distributed with this source code.
 *
 */

namespace ZCONF\Internal;

use ZCONF\Exception\SyntaxException;

/**
 * Внутренний класс для управления стримами токенов
 * @internal
 */
class ZStream
{
    protected $tokens;
    protected $index = -1;

    /**
     * Конструктор
     *
     * @param ZToken[] $tokens Список токенов
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * Перемещает указатель на один токен вперед
     *
     * @return ZToken|null Токен или null, если больше нет токенов
     */
    public function moveNext(): ?ZToken
    {
        return $this->tokens[++$this->index] ?? null;
    }

    /**
     * Сопоставляет следующий токен. Этот метод перемещает указатель на один токен вперед,
     * если не происходит ошибки
     *
     * @param string $tokenName Имя токена
     *
     * @return string Значение токена
     *
     * @throws SyntaxException Если следующий токен не совпадает
     */
    public function matchNext(string $tokenName): string
    {
        $token = $this->moveNext();
        --$this->index;

        if ($token->getName() == $tokenName) {
            return $this->moveNext()->getValue();
        }

        throw new SyntaxException(sprintf(
            'Ошибка синтаксиса: ожидался токен с именем "%s" вместо "%s" в строке %s.',
            $tokenName,
            $token->getName(),
            $token->getLine()
        ));
    }

    /**
     * Пропускает токены, пока они совпадают с именем токена, переданным в качестве аргумента.
     * Этот метод перемещает указатель на "n" токенов вперед до последнего,
     * который совпадает с именем токена
     *
     * @param string $tokenName Имя токена
     */
    public function skipWhile(string $tokenName): void
    {
        $this->skipWhileAny([$tokenName]);
    }

    /**
     * Пропускает токены, пока они совпадают с одним из имен токенов, переданных в качестве
     * аргумента. Этот метод перемещает указатель на "n" токенов вперед до последнего,
     * который совпадает с одним из имен токенов
     *
     * @param string[] $tokenNames Список имен токенов
     */
    public function skipWhileAny(array $tokenNames): void
    {
        while ($this->isNextAny($tokenNames)) {
            $this->moveNext();
        }
    }

    /**
     * Проверяет, совпадает ли следующий токен с именем токена, переданным в качестве аргумента
     *
     * @param string $tokenName Имя токена
     *
     * @return bool
     */
    public function isNext(string $tokenName): bool
    {
        $token = $this->moveNext();
        --$this->index;

        if ($token === null) {
            return false;
        }

        return $token->getName() == $tokenName;
    }

    /**
     * Проверяет, совпадают ли следующие токены в потоке с последовательностью токенов
     *
     * @param string[] $tokenNames Последовательность имен токенов
     *
     * @return bool
     */
    public function isNextSequence(array $tokenNames): bool
    {
        $result = true;
        $currentIndex = $this->index;

        foreach ($tokenNames as $tokenName) {
            $token = $this->moveNext();

            if ($token === null || $token->getName() != $tokenName) {
                $result = false;

                break;
            }
        }

        $this->index = $currentIndex;

        return $result;
    }

    /**
     * Проверяет, является ли один из токенов, переданных в качестве аргумента, следующим токеном
     *
     * @param string[] $tokenNames Список имен токенов. например: 'T_PLUS', 'T_SUB'
     *
     * @return bool
     */
    public function isNextAny(array $tokenNames): bool
    {
        $token = $this->moveNext();
        --$this->index;

        if ($token === null) {
            return false;
        }

        if (in_array($token->getName(), $tokenNames, true)) {
            return true;
        }

        return false;
    }

    /**
     * Возвращает все токены
     *
     * @return ZToken[] Список токенов
     */
    public function getAll(): array
    {
        return $this->tokens;
    }

    /**
     * Есть ли ожидающие токены?
     *
     * @return bool
     */
    public function hasPendingTokens(): bool
    {
        $tokenCount = count($this->tokens);

        if ($tokenCount == 0) {
            return false;
        }

        return $this->index < ($tokenCount - 1);
    }

    /**
     * Сбрасывает поток в начало
     */
    public function reset(): void
    {
        $this->index = -1;
    }
}
