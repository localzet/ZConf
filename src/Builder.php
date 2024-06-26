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

namespace ZCONF;

use Datetime;
use ZCONF\Exception\DumpException;
use ZCONF\Internal\ZStore;
use function array_keys;
use function array_values;
use function preg_match;
use function preg_replace;
use function str_replace;

/**
 * Генератор для строк ZConfig
 *
 * Использование:
 * <code>
 * $configString = new Builder()
 *  ->addTable('server.mail')
 *  ->addValue('ip', '192.168.0.1', 'Внутренний IP')
 *  ->addValue('port', 25)
 *  ->getZCONFString();
 * </code>
 */
class Builder
{
    protected $prefix = '    '; // 4 пробела по умолчанию
    protected $output = '';
    protected $currentKey;
    /** @var ZStore */
    protected $keyStore;
    private $currentLine = 0;
    /** @var array */
    private static $specialCharacters;
    /** @var array */
    private static $escapedSpecialCharacters;
    private static $specialCharactersMapping = [
        '\\' => '\\\\',
        "\b" => '\\b',
        "\t" => '\\t',
        "\n" => '\\n',
        "\f" => '\\f',
        "\r" => '\\r',
        '"' => '\\"',
    ];

    /**
     * Конструктор.
     *
     * @param int $indent Количество пробелов для отступа вложенных узлов
     */
    public function __construct(int $indent = 4)
    {
        $this->keyStore = new ZStore();
        $this->prefix = $indent ? str_repeat(' ', $indent) : '';
    }

    /**
     * Добавляет пару ключ-значение
     *
     * @param string $key Имя ключа
     * @param null|string|int|bool|float|array|Datetime $val Значение
     * @param string $comment Комментарий (необязательный аргумент).
     *
     * @return Builder Сам Builder
     */
    public function addValue(string $key, $val, string $comment = ''): Builder
    {
        $this->currentKey = $key;
        $this->exceptionIfKeyEmpty($key);
        $this->addKey($key);

        if (!$this->isUnquotedKey($key)) {
            $key = '"' . $key . '"';
        }

        $line = "$key = {$this->dumpValue($val)}";

        if (!empty($comment)) {
            $line .= ' ' . $this->dumpComment($comment);
        }

        $this->append($line, true);

        return $this;
    }

    /**
     * Добавляет таблицу.
     *
     * @param string $key Имя таблицы. Символ точки имеет специальное значение. например: "fruit.type"
     *
     * @return Builder Сам Builder
     */
    public function addTable(string $key): Builder
    {
        $this->exceptionIfKeyEmpty($key);
        $addPreNewline = $this->currentLine > 0;
        $keyParts = explode('.', $key);

        foreach ($keyParts as $keyPart) {
            $this->exceptionIfKeyEmpty($keyPart, "Table: \"$key\".");
            $this->exceptionIfKeyIsNotUnquotedKey($keyPart);
        }

        $line = "[{$key}]";
        $this->addTableKey($key);
        $this->append($line, true, false, $addPreNewline);

        return $this;
    }

    /**
     * Добавляет элемент массива таблиц
     *
     * @param string $key Имя массива таблиц
     *
     * @return Builder Сам Builder
     */
    public function addArrayOfTable(string $key): Builder
    {
        $this->exceptionIfKeyEmpty($key);
        $addPreNewline = $this->currentLine > 0;
        $keyParts = explode('.', $key);

        foreach ($keyParts as $keyPart) {
            $this->exceptionIfKeyEmpty($keyPart, "Array of table: \"{$key}\".");
            $this->exceptionIfKeyIsNotUnquotedKey($keyPart);
        }

        $line = "[[{$key}]]";
        $this->addArrayOfTableKey($key);
        $this->append($line, true, false, $addPreNewline);

        return $this;
    }

    /**
     * Добавляет строку комментария
     *
     * @param string $comment Комментарий
     *
     * @return Builder Сам Builder
     */
    public function addComment(string $comment): Builder
    {
        $this->append($this->dumpComment($comment), true);

        return $this;
    }

    /**
     * Получает строку ZCONF
     *
     * @return string
     */
    public function getZCONFString(): string
    {
        return $this->output;
    }

    /**
     * Возвращает экранированные символы для базовых строк
     */
    protected function getEscapedCharacters(): array
    {
        if (self::$escapedSpecialCharacters !== null) {
            return self::$escapedSpecialCharacters;
        }

        return self::$escapedSpecialCharacters = array_values(self::$specialCharactersMapping);
    }

    /**
     * Возвращает специальные символы для базовых строк
     */
    protected function getSpecialCharacters(): array
    {
        if (self::$specialCharacters !== null) {
            return self::$specialCharacters;
        }

        return self::$specialCharacters = array_keys(self::$specialCharactersMapping);
    }

    /**
     * Добавляет ключ в хранилище
     *
     * @param string $key Имя ключа
     *
     * @return void
     */
    protected function addKey(string $key): void
    {
        if (!$this->keyStore->isValidKey($key)) {
            throw new DumpException("Ключ \"{$key}\" уже был определен ранее.");
        }

        $this->keyStore->addKey($key);
    }

    /**
     * Добавляет ключ таблицы в хранилище
     *
     * @param string $key Имя ключа таблицы
     *
     * @return void
     */
    protected function addTableKey(string $key): void
    {
        if (!$this->keyStore->isValidTableKey($key)) {
            throw new DumpException("Ключ таблицы \"{$key}\" уже был определен ранее.");
        }

        if ($this->keyStore->isRegisteredAsArrayTableKey($key)) {
            throw new DumpException("Таблица \"{$key}\" уже была определена как предыдущий массив таблиц.");
        }

        $this->keyStore->addTableKey($key);
    }

    /**
     * Добавляет ключ массива таблиц в хранилище
     *
     * @param string $key Имя ключа
     *
     * @return void
     */
    protected function addArrayOfTableKey(string $key): void
    {
        if (!$this->keyStore->isValidArrayTableKey($key)) {
            throw new DumpException("Ключ массива таблиц \"{$key}\" уже был определен ранее.");
        }

        if ($this->keyStore->isTableImplicitFromArryTable($key)) {
            throw new DumpException("Ключ \"{$key}\" был определен как неявная таблица из предыдущего массива таблиц.");
        }

        $this->keyStore->addArrayTableKey($key);
    }

    /**
     * Выгружает значение
     *
     * @param null|string|int|bool|float|array|Datetime $val Значение
     *
     * @return string
     */
    protected function dumpValue($val): string
    {
        switch (true) {
            case is_string($val):
                return $this->dumpString($val);
            case is_array($val):
                return $this->dumpArray($val);
            case is_int($val):
                return $this->dumpInteger($val);
            case is_float($val):
                return $this->dumpFloat($val);
            case is_bool($val):
                return $this->dumpBool($val);
            case is_null($val):
                return 'null';
            case $val instanceof Datetime:
                return $this->dumpDatetime($val);
            default:
                throw new DumpException("Тип данных не поддерживается для ключа: \"{$this->currentKey}\".");
        }
    }

    /**
     * Добавляет содержимое в вывод
     *
     * @param string $val
     * @param bool $addPostNewline Указывает, добавить ли новую строку после значения
     * @param bool $addIndentation Указывает, добавить ли отступ в строке
     * @param bool $addPreNewline Указывает, добавить ли новую строку перед значением
     *
     * @return void
     */
    protected function append(string $val, bool $addPostNewline = false, bool $addIndentation = false, bool $addPreNewline = false): void
    {
        if ($addPreNewline) {
            $this->output .= "\n";
            ++$this->currentLine;
        }

        if ($addIndentation) {
            $val = $this->prefix . $val;
        }

        $this->output .= $val;

        if ($addPostNewline) {
            $this->output .= "\n";
            ++$this->currentLine;
        }
    }

    private function dumpString(string $val): string
    {
        if ($this->isLiteralString($val)) {
            return "'" . preg_replace('/@/', '', $val, 1) . "'";
        }

        $normalized = $this->normalizeString($val);

        if (!$this->isStringValid($normalized)) {
            throw new DumpException("Строка содержит недопустимые символы в ключе \"{$this->currentKey}\".");
        }

        return '"' . $normalized . '"';
    }

    private function isLiteralString(string $val): bool
    {
        return strpos($val, '@') === 0;
    }

    private function dumpBool(bool $val): string
    {
        return $val ? 'true' : 'false';
    }

    private function dumpArray(array $val): string
    {
        $result = '';
        $first = true;
        $dataType = null;
        $lastType = null;

        foreach ($val as $item) {
            $lastType = gettype($item);
            $dataType = $dataType == null ? $lastType : $dataType;

            if ($lastType != $dataType) {
                throw new DumpException("Типы данных не могут смешиваться в массиве. Ключ: \"{$this->currentKey}\".");
            }

            $result .= $first ? $this->dumpValue($item) : ', ' . $this->dumpValue($item);
            $first = false;
        }

        return '[' . $result . ']';
    }

    private function dumpComment(string $val): string
    {
        return '#' . $val;
    }

    private function dumpDatetime(Datetime $val): string
    {
        return $val->format('Y-m-d\TH:i:s\Z'); // ZULU форма
    }

    private function dumpInteger(int $val): string
    {
        return strval($val);
    }

    private function dumpFloat(float $val): string
    {
        $result = strval($val);

        if ($val == floor($val)) {
            $result .= '.0';
        }

        return $result;
    }

    private function isStringValid(string $val): bool
    {
        $noSpecialCharacter = str_replace($this->getEscapedCharacters(), '', $val);
        $noSpecialCharacter = preg_replace('/\\\\u([0-9a-fA-F]{4})/', '', $noSpecialCharacter);
        $noSpecialCharacter = preg_replace('/\\\\u([0-9a-fA-F]{8})/', '', $noSpecialCharacter);

        $pos = strpos($noSpecialCharacter, '\\');

        if ($pos !== false) {
            return false;
        }

        return true;
    }

    private function normalizeString(string $val): string
    {
        return str_replace($this->getSpecialCharacters(), $this->getEscapedCharacters(), $val);
    }

    private function exceptionIfKeyEmpty(string $key, string $additionalMessage = ''): void
    {
        $message = 'Ключ, имя таблицы или имя массива таблиц не может быть пустым или null.';

        if ($additionalMessage != '') {
            $message .= " {$additionalMessage}";
        }

        if (empty(trim($key))) {
            throw new DumpException($message);
        }
    }

    private function exceptionIfKeyIsNotUnquotedKey($key): void
    {
        if (!$this->isUnquotedKey($key)) {
            throw new DumpException("В этой реализации разрешены только некавычечные ключи. Ключ: \"{$key}\".");
        }
    }

    private function isUnquotedKey(string $key): bool
    {
        return preg_match('/^([-A-Z_a-z0-9]+)$/', $key) === 1;
    }
}
