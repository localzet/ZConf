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

/**
 * Внутренний класс для управления токенами
 * @internal
 */
class ZToken
{
    protected $value;
    protected $name;
    protected $line;

    /**
     * Конструктор.
     *
     * @param string $value Значение токена
     * @param string $name Имя токена. Например: T_BRAKET_BEGIN
     * @param int $line Строка кода, где был найден токен
     * @internal
     */
    public function __construct(string $value, string $name, int $line)
    {
        $this->value = $value;
        $this->name = $name;
        $this->line = $line;
    }

    /**
     * Возвращает значение (совпадающий термин)
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Возвращает имя токена
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Возвращает строку кода, где был найден токен
     *
     * @return int
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * Преобразует объект в строку
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            "[\n name: %s\n value:%s\n line: %s\n]",
            $this->name,
            $this->value,
            $this->line
        );
    }
}
