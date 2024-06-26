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
use Exception;
use stdClass;
use ZCONF\Exception\ParseException;
use ZCONF\Exception\SyntaxException;
use ZCONF\Internal\ZArray;
use ZCONF\Internal\ZLexer;
use ZCONF\Internal\ZStore;
use ZCONF\Internal\ZStream;
use ZCONF\Internal\ZToken;

/**
 * Парсер для строк ZConfig
 *
 * @method static array|null|stdClass parse(string $input, bool $resultAsObject = false) Преобразует ZCONF-строку в PHP массив
 * @method static array|null|stdClass parseString(string $input, bool $resultAsObject = false) Преобразует ZCONF-строку в PHP массив
 * @method static array|null|stdClass parseFile(string $input, bool $resultAsObject = false) Преобразует ZCONF-файл в PHP массив
 */
class Parser
{
    /** @var ZLexer Лексер */
    protected $lexer;
    /** @var ZStore Хранилище ключей */
    private $keyStore;
    /** @var ZArray Массив TOML */
    private $tomlArray;

    private static $tokensNotAllowedInBasicStrings = [
        'T_ESCAPE',
        'T_NEWLINE',
        'T_EOS',
    ];

    private static $tokensNotAllowedInLiteralStrings = [
        'T_NEWLINE',
        'T_EOS',
    ];

    /**
     * Конструктор
     *
     * @param ZLexer|null $lexer Лексер
     */
    public function __construct(ZLexer $lexer = null)
    {
        $this->lexer = $lexer ?? new ZLexer();
    }

    public static function __callStatic($name, $arguments)
    {
        if ($name == 'parse' || $name == 'parseString') {
            try {
                $parser = new self();
                $data = $parser->parse(...$arguments);
            } catch (SyntaxException $e) {
                $exception = new ParseException($e->getMessage(), -1, null, null, $e);

                if ($token = $e->getToken()) {
                    $exception->setParsedLine($token->getLine());
                }

                throw $exception;
            }

            return $data;
        } elseif ($name == 'parseFile') {
            if (!is_file($arguments[0])) {
                throw new ParseException(sprintf('Файл "%s" не существует.', $arguments[0]));
            }

            if (!is_readable($arguments[0])) {
                throw new ParseException(sprintf('Файл "%s" не может быть прочитан.', $arguments[0]));
            }

            try {
                $parser = new self();
                $data = $parser->parse(file_get_contents($arguments[0]), $arguments[1] ?? false);
            } catch (SyntaxException $e) {
                $exception = new ParseException($e->getMessage());
                $exception->setParsedFile($arguments[0]);

                if ($token = $e->getToken()) {
                    $exception->setParsedLine($token->getLine());
                }

                throw $exception;
            }

            return $data;
        }

        return null;
    }

    /**
     * Разбор ZCONF-строки
     *
     * @param string $input Входные данные (Строка ZCONF)
     * @param bool $resultAsObject (необязательно) Возвращает результат в виде объекта
     *
     * @return array|object Результат разбора
     *
     * @throws ParseException|Exception Если ZCONF недействителен
     */
    public function parse(string $input, bool $resultAsObject = false)
    {
        if (preg_match('//u', $input) === false) {
            throw new SyntaxException('Входные данные TOML, похоже, не являются действительными UTF-8.');
        }

        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $input = str_replace("\t", ' ', $input);

        $stream = $this->lexer->tokenize($input);
        $values = $this->parseImplementation($stream);

        if ($stream->hasPendingTokens()) {
            throw new SyntaxException('Есть токены, которые не были обработаны.');
        }

        if ($resultAsObject) {
            $object = new stdClass();

            foreach ($values as $key => $value) {
                $object->$key = $value;
            }

            return $object;
        }

        return empty($values) ? null : $values;
    }

    /**
     * Выполнение реального разбора
     *
     * @param ZStream $stream Поток токенов, возвращаемый лексером
     *
     * @return array Результат разбора
     * @throws Exception
     */
    protected function parseImplementation(ZStream $stream): array
    {
        $this->keyStore = new ZStore();
        $this->tomlArray = new ZArray();

        while ($stream->hasPendingTokens()) {
            $this->processExpression($stream);
        }

        return $this->tomlArray->getArray();
    }

    /**
     * Обработка выражения
     *
     * @param ZStream $stream Поток токенов
     * @throws Exception
     */
    private function processExpression(ZStream $stream): void
    {
        if ($stream->isNext('T_HASH')) {
            $this->parseComment($stream);
        } elseif ($stream->isNextAny(['T_QUOTATION_MARK', 'T_UNQUOTED_KEY', 'T_INTEGER'])) {
            $this->parseKeyValue($stream);
        } elseif ($stream->isNextSequence(['T_LEFT_SQUARE_BRAKET', 'T_LEFT_SQUARE_BRAKET'])) {
            $this->parseArrayOfTables($stream);
        } elseif ($stream->isNext('T_LEFT_SQUARE_BRAKET')) {
            $this->parseTable($stream);
        } elseif ($stream->isNextAny(['T_SPACE', 'T_NEWLINE', 'T_EOS'])) {
            $stream->moveNext();
        } else {
            $msg = 'Ожидался T_HASH или T_UNQUOTED_KEY.';
            $this->unexpectedTokenError($stream->moveNext(), $msg);
        }
    }

    /**
     * Разбор комментария
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseComment(ZStream $stream): void
    {
        $this->matchNext('T_HASH', $stream);

        while (!$stream->isNextAny(['T_NEWLINE', 'T_EOS'])) {
            $stream->moveNext();
        }
    }

    /**
     * Разбор пары ключ-значение
     *
     * @param ZStream $stream Поток токенов
     * @param bool $isFromInlineTable Флаг, указывающий, что пара ключ-значение из встроенной таблицы
     * @throws Exception
     */
    private function parseKeyValue(ZStream $stream, bool $isFromInlineTable = false): void
    {
        $keyName = $this->parseKeyName($stream);
        $this->parseSpaceIfExists($stream);
        $this->matchNext('T_EQUAL', $stream);
        $this->parseSpaceIfExists($stream);

        $isInlineTable = $stream->isNext('T_LEFT_CURLY_BRACE');

        if ($isInlineTable) {
            if (!$this->keyStore->isValidInlineTable($keyName)) {
                $this->syntaxError("Ключ встроенной таблицы \"{$keyName}\" уже был определен ранее.");
            }

            $this->keyStore->addInlineTableKey($keyName);
        } else {
            if (!$this->keyStore->isValidKey($keyName)) {
                $this->syntaxError("Ключ \"{$keyName}\" уже был определен ранее.");
            }

            $this->keyStore->addKey($keyName);
        }

        if ($stream->isNext('T_LEFT_SQUARE_BRAKET')) {
            $this->tomlArray->addKeyValue($keyName, $this->parseArray($stream));
        } elseif ($isInlineTable) {
            $this->parseInlineTable($stream, $keyName);
        } else {
            $this->tomlArray->addKeyValue($keyName, $this->parseSimpleValue($stream)->value);
        }

        if (!$isFromInlineTable) {
            $this->parseSpaceIfExists($stream);
            $this->parseCommentIfExists($stream);
            $this->errorIfNextIsNotNewlineOrEOS($stream);
        }
    }

    /**
     * Разбор имени ключа
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Имя ключа
     */
    private function parseKeyName(ZStream $stream): string
    {
        if ($stream->isNext('T_UNQUOTED_KEY')) {
            return $this->matchNext('T_UNQUOTED_KEY', $stream);
        }

        if ($stream->isNext('T_INTEGER')) {
            return $this->parseInteger($stream);
        }

        return $this->parseBasicString($stream);
    }

    /**
     * Разбор простого значения
     *
     * @param ZStream $stream Поток токенов
     *
     * @return object Объект с двумя публичными свойствами: value и type.
     * @throws Exception
     */
    private function parseSimpleValue(ZStream $stream)
    {
        if ($stream->isNext('T_NULL')) {
            $type = 'null';
            $value = $this->parseNull($stream);
        } elseif ($stream->isNext('T_BOOLEAN')) {
            $type = 'boolean';
            $value = $this->parseBoolean($stream);
        } elseif ($stream->isNext('T_INTEGER')) {
            $type = 'integer';
            $value = $this->parseInteger($stream);
        } elseif ($stream->isNext('T_FLOAT')) {
            $type = 'float';
            $value = $this->parseFloat($stream);
        } elseif ($stream->isNext('T_QUOTATION_MARK')) {
            $type = 'string';
            $value = $this->parseBasicString($stream);
        } elseif ($stream->isNext('T_3_QUOTATION_MARK')) {
            $type = 'string';
            $value = $this->parseMultilineBasicString($stream);
        } elseif ($stream->isNext('T_APOSTROPHE')) {
            $type = 'string';
            $value = $this->parseLiteralString($stream);
        } elseif ($stream->isNext('T_3_APOSTROPHE')) {
            $type = 'string';
            $value = $this->parseMultilineLiteralString($stream);
        } elseif ($stream->isNext('T_DATE_TIME')) {
            $type = 'datetime';
            $value = $this->parseDatetime($stream);
        } else {
            $this->unexpectedTokenError(
                $stream->moveNext(),
                'Ожидался null, boolean, integer, long, string или datetime.'
            );
        }

        $valueStruct = new class() {
            public $value;
            public $type;
        };

        $valueStruct->value = $value;
        $valueStruct->type = $type;

        return $valueStruct;
    }

    /**
     * Разбор null
     *
     * @param ZStream $stream Поток токенов
     *
     * @return null
     */
    private function parseNull(ZStream $stream)
    {
        $stream->moveNext();
        return null;
    }

    /**
     * Разбор логического значения
     *
     * @param ZStream $stream Поток токенов
     *
     * @return bool Логическое значение
     */
    private function parseBoolean(ZStream $stream): bool
    {
        return $this->matchNext('T_BOOLEAN', $stream) == 'true';
    }

    /**
     * Разбор целого числа
     *
     * @param ZStream $stream Поток токенов
     *
     * @return int Целое число
     */
    private function parseInteger(ZStream $stream): int
    {
        $token = $stream->moveNext();
        $value = $token->getValue();

        if (preg_match('/([^\d]_[^\d])|(_$)/', $value)) {
            $this->syntaxError(
                'Недопустимое целое число: подчеркивание должно быть окружено хотя бы одной цифрой.',
                $token
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                'Недопустимое целое число: ведущие нули не допускаются.',
                $token
            );
        }

        return (int)$value;
    }

    /**
     * Разбор числа с плавающей точкой
     *
     * @param ZStream $stream Поток токенов
     *
     * @return float Число с плавающей точкой
     */
    private function parseFloat(ZStream $stream): float
    {
        $token = $stream->moveNext();
        $value = $token->getValue();

        if (preg_match('/([^\d]_[^\d])|_[eE]|[eE]_|(_$)/', $value)) {
            $this->syntaxError(
                'Недопустимое число с плавающей точкой: подчеркивание должно быть окружено хотя бы одной цифрой.',
                $token
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                'Недопустимое число с плавающей точкой: ведущие нули не допускаются.',
                $token
            );
        }

        return (float)$value;
    }

    /**
     * Разбор базовой строки
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Базовая строка
     */
    private function parseBasicString(ZStream $stream): string
    {
        $this->matchNext('T_QUOTATION_MARK', $stream);

        $result = '';

        while (!$stream->isNext('T_QUOTATION_MARK')) {
            if ($stream->isNextAny(self::$tokensNotAllowedInBasicStrings)) {
                $this->unexpectedTokenError($stream->moveNext(), 'Этот символ недопустим.');
            }

            $value = $stream->isNext('T_ESCAPED_CHARACTER') ? $this->parseEscapedCharacter($stream) : $stream->moveNext()->getValue();
            $result .= $value;
        }

        $this->matchNext('T_QUOTATION_MARK', $stream);

        return $result;
    }

    /**
     * Разбор многострочной базовой строки
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Многострочная базовая строка
     */
    private function parseMultilineBasicString(ZStream $stream): string
    {
        $this->matchNext('T_3_QUOTATION_MARK', $stream);

        $result = '';

        if ($stream->isNext('T_NEWLINE')) {
            $stream->moveNext();
        }

        while (!$stream->isNext('T_3_QUOTATION_MARK')) {
            if ($stream->isNext('T_EOS')) {
                $this->unexpectedTokenError($stream->moveNext(), 'Ожидаемый токен "T_3_QUOTATION_MARK".');
            }

            if ($stream->isNext('T_ESCAPE')) {
                $stream->skipWhileAny(['T_ESCAPE', 'T_SPACE', 'T_NEWLINE']);
            }

            if ($stream->isNext('T_EOS')) {
                $this->unexpectedTokenError($stream->moveNext(), 'Ожидаемый токен "T_3_QUOTATION_MARK".');
            }

            if (!$stream->isNext('T_3_QUOTATION_MARK')) {
                $value = $stream->isNext('T_ESCAPED_CHARACTER') ? $this->parseEscapedCharacter($stream) : $stream->moveNext()->getValue();
                $result .= $value;
            }
        }

        $this->matchNext('T_3_QUOTATION_MARK', $stream);

        return $result;
    }

    /**
     * Разбор литеральной строки
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Литеральная строка
     */
    private function parseLiteralString(ZStream $stream): string
    {
        $this->matchNext('T_APOSTROPHE', $stream);

        $result = '';

        while (!$stream->isNext('T_APOSTROPHE')) {
            if ($stream->isNextAny(self::$tokensNotAllowedInLiteralStrings)) {
                $this->unexpectedTokenError($stream->moveNext(), 'Этот символ недопустим.');
            }

            $result .= $stream->moveNext()->getValue();
        }

        $this->matchNext('T_APOSTROPHE', $stream);

        return $result;
    }

    /**
     * Разбор многострочной литеральной строки
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Многострочная литеральная строка
     */
    private function parseMultilineLiteralString(ZStream $stream): string
    {
        $this->matchNext('T_3_APOSTROPHE', $stream);

        $result = '';

        if ($stream->isNext('T_NEWLINE')) {
            $stream->moveNext();
        }

        while (!$stream->isNext('T_3_APOSTROPHE')) {
            if ($stream->isNext('T_EOS')) {
                $this->unexpectedTokenError($stream->moveNext(), 'Ожидаемый токен "T_3_APOSTROPHE".');
            }

            $result .= $stream->moveNext()->getValue();
        }

        $this->matchNext('T_3_APOSTROPHE', $stream);

        return $result;
    }

    /**
     * Разбор экранированного символа
     *
     * @param ZStream $stream Поток токенов
     *
     * @return string Экранированный символ
     */
    private function parseEscapedCharacter(ZStream $stream): string
    {
        $token = $stream->moveNext();
        $value = $token->getValue();

        switch ($value) {
            case '\b':
                return "\b";
            case '\t':
                return "\t";
            case '\n':
                return "\n";
            case '\f':
                return "\f";
            case '\r':
                return "\r";
            case '\"':
                return '"';
            case '\\\\':
                return '\\';
        }

        if (strlen($value) === 6) {
            return json_decode('"' . $value . '"');
        }

        preg_match('/\\\U([0-9a-fA-F]{4})([0-9a-fA-F]{4})/', $value, $matches);

        return json_decode('"\u' . $matches[1] . '\u' . $matches[2] . '"');
    }

    /**
     * Разбор даты и времени
     *
     * @param ZStream $stream Поток токенов
     *
     * @return Datetime Дата и время
     * @throws Exception
     */
    private function parseDatetime(ZStream $stream): Datetime
    {
        $date = $this->matchNext('T_DATE_TIME', $stream);

        return new Datetime($date);
    }

    /**
     * Разбор массива
     *
     * @param ZStream $stream Поток токенов
     *
     * @return array Массив
     * @throws Exception
     */
    private function parseArray(ZStream $stream): array
    {
        $result = [];
        $leaderType = '';

        $this->matchNext('T_LEFT_SQUARE_BRAKET', $stream);

        while (!$stream->isNext('T_RIGHT_SQUARE_BRAKET')) {
            $stream->skipWhileAny(['T_NEWLINE', 'T_SPACE']);
            $this->parseCommentsInsideBlockIfExists($stream);

            if ($stream->isNext('T_LEFT_SQUARE_BRAKET')) {
                if ($leaderType === '') {
                    $leaderType = 'array';
                }

                if ($leaderType !== 'array') {
                    $this->syntaxError(sprintf(
                        'Типы данных не могут смешиваться в массиве. Значение: "%s".',
                        $valueStruct->value ?? ''
                    ));
                }

                $result[] = $this->parseArray($stream);
            } else {
                $valueStruct = $this->parseSimpleValue($stream);

                if ($leaderType === '') {
                    $leaderType = $valueStruct->type;
                }

                if ($valueStruct->type !== $leaderType) {
                    $this->syntaxError(sprintf(
                        'Типы данных не могут смешиваться в массиве. Значение: "%s".',
                        $valueStruct->value
                    ));
                }

                $result[] = $valueStruct->value;
            }

            $stream->skipWhileAny(['T_NEWLINE', 'T_SPACE']);
            $this->parseCommentsInsideBlockIfExists($stream);

            if (!$stream->isNext('T_RIGHT_SQUARE_BRAKET')) {
                $this->matchNext('T_COMMA', $stream);
            }

            $stream->skipWhileAny(['T_NEWLINE', 'T_SPACE']);
            $this->parseCommentsInsideBlockIfExists($stream);
        }

        $this->matchNext('T_RIGHT_SQUARE_BRAKET', $stream);

        return $result;
    }

    /**
     * Разбор встроенной таблицы
     *
     * @param ZStream $stream Поток токенов
     * @param string $keyName Имя ключа
     * @throws Exception
     */
    private function parseInlineTable(ZStream $stream, string $keyName): void
    {
        $this->matchNext('T_LEFT_CURLY_BRACE', $stream);

        $this->tomlArray->beginInlineTableKey($keyName);

        $this->parseSpaceIfExists($stream);

        if (!$stream->isNext('T_RIGHT_CURLY_BRACE')) {
            $this->parseKeyValue($stream, true);
            $this->parseSpaceIfExists($stream);
        }

        while ($stream->isNext('T_COMMA')) {
            $stream->moveNext();

            $this->parseSpaceIfExists($stream);
            $this->parseKeyValue($stream, true);
            $this->parseSpaceIfExists($stream);
        }

        $this->matchNext('T_RIGHT_CURLY_BRACE', $stream);

        $this->tomlArray->endCurrentInlineTableKey();
    }

    /**
     * Разбор таблицы
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseTable(ZStream $stream): void
    {
        $this->matchNext('T_LEFT_SQUARE_BRAKET', $stream);

        $fullTableName = $this->tomlArray->escapeKey($key = $this->parseKeyName($stream));

        while ($stream->isNext('T_DOT')) {
            $stream->moveNext();

            $key = $this->tomlArray->escapeKey($this->parseKeyName($stream));
            $fullTableName .= ".$key";
        }

        if (!$this->keyStore->isValidTableKey($fullTableName)) {
            $this->syntaxError("Ключ \"{$fullTableName}\" уже был определен ранее.");
        }

        $this->keyStore->addTableKey($fullTableName);
        $this->tomlArray->addTableKey($fullTableName);
        $this->matchNext('T_RIGHT_SQUARE_BRAKET', $stream);

        $this->parseSpaceIfExists($stream);
        $this->parseCommentIfExists($stream);
        $this->errorIfNextIsNotNewlineOrEOS($stream);
    }

    /**
     * Разбор массива таблиц
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseArrayOfTables(ZStream $stream): void
    {
        $this->matchNext('T_LEFT_SQUARE_BRAKET', $stream);
        $this->matchNext('T_LEFT_SQUARE_BRAKET', $stream);

        $fullTableName = $key = $this->tomlArray->escapeKey($this->parseKeyName($stream));

        while ($stream->isNext('T_DOT')) {
            $stream->moveNext();

            $key = $this->tomlArray->escapeKey($this->parseKeyName($stream));
            $fullTableName .= ".$key";
        }

        if (!$this->keyStore->isValidArrayTableKey($fullTableName)) {
            $this->syntaxError("Ключ \"{$fullTableName}\" уже был определен ранее.");
        }

        if ($this->keyStore->isTableImplicitFromArryTable($fullTableName)) {
            $this->syntaxError("Массив таблиц \"{$fullTableName}\" уже был определен как предыдущая таблица");
        }

        $this->keyStore->addArrayTableKey($fullTableName);
        $this->tomlArray->addArrayTableKey($fullTableName);

        $this->matchNext('T_RIGHT_SQUARE_BRAKET', $stream);
        $this->matchNext('T_RIGHT_SQUARE_BRAKET', $stream);

        $this->parseSpaceIfExists($stream);
        $this->parseCommentIfExists($stream);
        $this->errorIfNextIsNotNewlineOrEOS($stream);
    }

    /**
     * Сопоставление следующего токена
     *
     * @param string $tokenName Имя токена
     * @param ZStream $stream Поток токенов
     *
     * @return string Значение токена
     */
    private function matchNext(string $tokenName, ZStream $stream): string
    {
        if (!$stream->isNext($tokenName)) {
            $this->unexpectedTokenError($stream->moveNext(), "Ожидался \"$tokenName\".");
        }

        return $stream->moveNext()->getValue();
    }

    /**
     * Разбор пробела, если он существует
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseSpaceIfExists(ZStream $stream): void
    {
        if ($stream->isNext('T_SPACE')) {
            $stream->moveNext();
        }
    }

    /**
     * Разбор комментария, если он существует
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseCommentIfExists(ZStream $stream): void
    {
        if ($stream->isNext('T_HASH')) {
            $this->parseComment($stream);
        }
    }

    /**
     * Разбор комментариев внутри блока, если они существуют
     *
     * @param ZStream $stream Поток токенов
     */
    private function parseCommentsInsideBlockIfExists(ZStream $stream): void
    {
        $this->parseCommentIfExists($stream);

        while ($stream->isNext('T_NEWLINE')) {
            $stream->moveNext();
            $stream->skipWhile('T_SPACE');
            $this->parseCommentIfExists($stream);
        }
    }

    /**
     * Ошибка, если следующий не является новой строкой или EOS
     *
     * @param ZStream $stream Поток токенов
     */
    private function errorIfNextIsNotNewlineOrEOS(ZStream $stream): void
    {
        if (!$stream->isNextAny(['T_NEWLINE', 'T_EOS'])) {
            $this->unexpectedTokenError($stream->moveNext(), 'Ожидался T_NEWLINE или T_EOS.');
        }
    }

    /**
     * Ошибка неожиданного токена
     *
     * @param ZToken $token Токен
     * @param string $expectedMsg Ожидаемое сообщение
     *
     * @throws SyntaxException Исключение синтаксической ошибки
     */
    private function unexpectedTokenError(ZToken $token, string $expectedMsg): void
    {
        $name = $token->getName();
        $line = $token->getLine();
        $value = $token->getValue();
        $msg = sprintf('Ошибка синтаксиса: неожиданный токен "%s" в строке %s со значением "%s".', $name, $line, $value);

        if (!empty($expectedMsg)) {
            $msg = $msg . ' ' . $expectedMsg;
        }

        throw new SyntaxException($msg);
    }

    /**
     * Синтаксическая ошибка
     *
     * @param string $msg Сообщение об ошибке
     * @param ZToken|null $token Токен
     *
     * @throws SyntaxException Исключение синтаксической ошибки
     */
    private function syntaxError(string $msg, ZToken $token = null): void
    {
        if ($token !== null) {
            $name = $token->getName();
            $line = $token->getLine();
            $value = $token->getValue();
            $tokenMsg = sprintf('Токен: "%s" строка: %s значение "%s".', $name, $line, $value);
            $msg .= ' ' . $tokenMsg;
        }

        throw new SyntaxException($msg);
    }
}
