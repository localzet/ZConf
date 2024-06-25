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

use LogicException;
use function trim;

/**
 * Внутренний класс для управления ключами (ключ-значение, таблицы и массивы таблиц) в ZConfig
 * @internal
 */
class ZStore
{
    private $keys = [];
    private $tables = [];
    private $arrayOfTables = [];
    private $implicitArrayOfTables = [];
    private $currentTable = '';
    private $currentArrayOfTable = '';

    /**
     * Добавляет ключ.
     *
     * @param string $name Имя ключа.
     * @throws LogicException Если ключ недействителен.
     */
    public function addKey(string $name): void
    {
        if (!$this->isValidKey($name)) {
            throw new LogicException("Ключ \"{$name}\" недействителен.");
        }

        $this->keys[] = $this->composeKeyWithCurrentPrefix($name);
    }

    /**
     * Проверяет, является ли ключ действительным.
     *
     * @param string $name Имя ключа.
     * @return bool Возвращает true, если ключ действителен, иначе false.
     */
    public function isValidKey(string $name): bool
    {
        $composedKey = $this->composeKeyWithCurrentPrefix($name);

        if (in_array($composedKey, $this->keys, true) === true) {
            return false;
        }

        return true;
    }

    /**
     * Добавляет ключ таблицы.
     *
     * @param string $name Имя ключа таблицы.
     * @throws LogicException Если ключ таблицы недействителен.
     */
    public function addTableKey(string $name): void
    {
        if (!$this->isValidTableKey($name)) {
            throw new LogicException("Ключ таблицы \"{$name}\" недействителен.");
        }

        $this->currentTable = '';
        $this->currentArrayOfTable = $this->getArrayOfTableKeyFromTableKey($name);
        $this->addkey($name);
        $this->currentTable = $name;
        $this->tables[] = $name;
    }

    /**
     * Проверяет, является ли ключ таблицы действительным.
     *
     * @param string $name Имя ключа таблицы.
     * @return bool Возвращает true, если ключ таблицы действителен, иначе false.
     */
    public function isValidTableKey(string $name): bool
    {
        $currentTable = $this->currentTable;
        $currentArrayOfTable = $this->currentArrayOfTable;

        $this->currentTable = '';
        $this->currentArrayOfTable = $this->getArrayOfTableKeyFromTableKey($name);

        if ($this->currentArrayOfTable == $name) {
            return false;
        }

        $isValid = $this->isValidKey($name);
        $this->currentTable = $currentTable;
        $this->currentArrayOfTable = $currentArrayOfTable;

        return $isValid;
    }

    /**
     * Проверяет, является ли встроенная таблица действительной.
     *
     * @param string $name Имя встроенной таблицы.
     * @return bool Возвращает true, если встроенная таблица действительна, иначе false.
     */
    public function isValidInlineTable(string $name): bool
    {
        return $this->isValidTableKey($name);
    }

    /**
     * Добавляет ключ встроенной таблицы.
     *
     * @param string $name Имя ключа встроенной таблицы.
     */
    public function addInlineTableKey(string $name): void
    {
        $this->addTableKey($name);
    }

    /**
     * Добавляет ключ массива таблиц.
     *
     * @param string $name Имя ключа массива таблиц.
     * @throws LogicException Если ключ массива таблиц недействителен.
     */
    public function addArrayTableKey(string $name): void
    {
        if (!$this->isValidArrayTableKey($name)) {
            throw new LogicException("Ключ массива таблиц \"{$name}\" недействителен.");
        }

        $this->currentTable = '';
        $this->currentArrayOfTable = '';

        if (isset($this->arrayOfTables[$name]) === false) {
            $this->addkey($name);
            $this->arrayOfTables[$name] = 0;
        } else {
            $this->arrayOfTables[$name]++;
        }

        $this->currentArrayOfTable = $name;
        $this->processImplicitArrayTableNameIfNeeded($name);
    }

    /**
     * Проверяет, является ли ключ массива таблиц действительным.
     *
     * @param string $name Имя ключа массива таблиц.
     * @return bool Возвращает true, если ключ массива таблиц действителен, иначе false.
     */
    public function isValidArrayTableKey(string $name): bool
    {
        $isInArrayOfTables = isset($this->arrayOfTables[$name]);
        $isInKeys = in_array($name, $this->keys, true);

        if ((!$isInArrayOfTables && !$isInKeys) || ($isInArrayOfTables && $isInKeys)) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет, зарегистрирован ли ключ как ключ таблицы.
     *
     * @param string $name Имя ключа.
     * @return bool Возвращает true, если ключ зарегистрирован как ключ таблицы, иначе false.
     */
    public function isRegisteredAsTableKey(string $name): bool
    {
        return in_array($name, $this->tables);
    }

    /**
     * Проверяет, зарегистрирован ли ключ как ключ массива таблиц.
     *
     * @param string $name Имя ключа.
     * @return bool Возвращает true, если ключ зарегистрирован как ключ массива таблиц, иначе false.
     */
    public function isRegisteredAsArrayTableKey(string $name): bool
    {
        return isset($this->arrayOfTables[$name]);
    }

    /**
     * Проверяет, является ли таблица неявной из массива таблиц.
     *
     * @param string $name Имя таблицы.
     * @return bool Возвращает true, если таблица является неявной из массива таблиц, иначе false.
     */
    public function isTableImplicitFromArryTable(string $name): bool
    {
        $isInImplicitArrayOfTables = in_array($name, $this->implicitArrayOfTables);
        $isInArrayOfTables = isset($this->arrayOfTables[$name]);

        if ($isInImplicitArrayOfTables && !$isInArrayOfTables) {
            return true;
        }

        return false;
    }

    /**
     * Составляет ключ с текущим префиксом.
     *
     * @param string $name Имя ключа.
     * @return string Возвращает ключ с текущим префиксом.
     */
    private function composeKeyWithCurrentPrefix(string $name): string
    {
        $currentArrayOfTableIndex = '';

        if ($this->currentArrayOfTable != '') {
            $currentArrayOfTableIndex = (string)$this->arrayOfTables[$this->currentArrayOfTable];
        }

        return trim("{$this->currentArrayOfTable}{$currentArrayOfTableIndex}.{$this->currentTable}.{$name}", '.');
    }

    /**
     * Получает ключ массива таблиц из ключа таблицы.
     *
     * @param string $name Имя ключа таблицы.
     * @return string Возвращает ключ массива таблиц.
     */
    private function getArrayOfTableKeyFromTableKey(string $name): string
    {
        if (isset($this->arrayOfTables[$name])) {
            return $name;
        }

        $keyParts = explode('.', $name);

        if (count($keyParts) === 1) {
            return '';
        }

        array_pop($keyParts);

        while (count($keyParts) > 0) {
            $candidateKey = implode('.', $keyParts);

            if (isset($this->arrayOfTables[$candidateKey])) {
                return $candidateKey;
            }

            array_pop($keyParts);
        }

        return '';
    }

    /**
     * Обрабатывает неявное имя массива таблиц, если это необходимо.
     *
     * @param string $name Имя массива таблиц.
     */
    private function processImplicitArrayTableNameIfNeeded(string $name): void
    {
        $nameParts = explode('.', $name);

        if (count($nameParts) < 2) {
            return;
        }

        array_pop($nameParts);

        while (count($nameParts) != 0) {
            $this->implicitArrayOfTables[] = implode('.', $nameParts);
            array_pop($nameParts);
        }
    }
}
