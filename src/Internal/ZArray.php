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

use function array_key_exists;
use function in_array;
use function str_replace;

/**
 * Внутренний класс для управления массивами
 * @internal
 */
class ZArray
{
    private const DOT_ESCAPED = '%*%';

    private $result = [];
    private $currentPointer;
    private $ArrayTableKeys = [];
    private $inlineTablePointers = [];

    /**
     * Конструктор.
     */
    public function __construct()
    {
        $this->resetCurrentPointer();
    }

    /**
     * Добавляет пару ключ-значение.
     *
     * @param string $name Имя ключа.
     * @param mixed $value Значение.
     */
    public function addKeyValue(string $name, $value): void
    {
        $this->currentPointer[$name] = $value;
    }

    /**
     * Добавляет ключ таблицы.
     *
     * @param string $name Имя ключа таблицы.
     */
    public function addTableKey(string $name): void
    {
        $this->resetCurrentPointer();
        $this->goToKey($name);
    }

    /**
     * Начинает ключ встроенной таблицы.
     *
     * @param string $name Имя ключа встроенной таблицы.
     */
    public function beginInlineTableKey(string $name): void
    {
        $this->inlineTablePointers[] = &$this->currentPointer;
        $this->goToKey($name);
    }

    /**
     * Завершает текущий ключ встроенной таблицы.
     */
    public function endCurrentInlineTableKey(): void
    {
        $indexLastElement = $this->getKeyLastElementOfArray($this->inlineTablePointers);
        $this->currentPointer = &$this->inlineTablePointers[$indexLastElement];
        unset($this->inlineTablePointers[$indexLastElement]);
    }

    /**
     * Добавляет ключ массива таблиц.
     *
     * @param string $name Имя ключа массива таблиц.
     */
    public function addArrayTableKey(string $name): void
    {
        $this->resetCurrentPointer();
        $this->goToKey($name);
        $this->currentPointer[] = [];
        $this->setCurrentPointerToLastElement();

        if (!$this->existsInArrayTableKey($name)) {
            $this->ArrayTableKeys[] = $name;
        }
    }

    /**
     * Экранирует ключ.
     *
     * @param string $name Имя ключа.
     * @return string Экранированный ключ.
     */
    public function escapeKey(string $name): string
    {
        return str_replace('.', self::DOT_ESCAPED, $name);
    }

    /**
     * Возвращает массив.
     *
     * @return array Массив.
     */
    public function getArray(): array
    {
        return $this->result;
    }

    /**
     * Убирает экранирование ключа.
     *
     * @param string $name Имя ключа.
     * @return string Ключ без экранирования.
     */
    private function unescapeKey(string $name): string
    {
        return str_replace(self::DOT_ESCAPED, '.', $name);
    }

    /**
     * Переходит к ключу.
     *
     * @param string $name Имя ключа.
     */
    private function goToKey(string $name): void
    {
        $keyParts = explode('.', $name);
        $accumulatedKey = '';
        $countParts = count($keyParts);

        foreach ($keyParts as $index => $keyPart) {
            $keyPart = $this->unescapeKey($keyPart);
            $isLastKeyPart = $index == $countParts - 1;
            $accumulatedKey .= $accumulatedKey == '' ? $keyPart : '.' . $keyPart;

            if (array_key_exists($keyPart, $this->currentPointer) === false) {
                $this->currentPointer[$keyPart] = [];
            }

            $this->currentPointer = &$this->currentPointer[$keyPart];

            if ($this->existsInArrayTableKey($accumulatedKey) && !$isLastKeyPart) {
                $this->setCurrentPointerToLastElement();
            }
        }
    }

    /**
     * Устанавливает текущий указатель на последний элемент.
     */
    private function setCurrentPointerToLastElement(): void
    {
        $indexLastElement = $this->getKeyLastElementOfArray($this->currentPointer);
        $this->currentPointer = &$this->currentPointer[$indexLastElement];
    }

    /**
     * Сбрасывает текущий указатель.
     */
    private function resetCurrentPointer(): void
    {
        $this->currentPointer = &$this->result;
    }

    /**
     * Проверяет, существует ли ключ в ключе массива таблиц.
     *
     * @param string $name Имя ключа.
     * @return bool Возвращает true, если ключ существует в ключе массива таблиц, иначе false.
     */
    private function existsInArrayTableKey(string $name): bool
    {
        return in_array($this->unescapeKey($name), $this->ArrayTableKeys);
    }

    /**
     * Возвращает ключ последнего элемента массива.
     *
     * @param array $arr Массив.
     * @return int|string|null Ключ последнего элемента массива.
     */
    private function getKeyLastElementOfArray(array &$arr)
    {
        end($arr);

        return key($arr);
    }
}
