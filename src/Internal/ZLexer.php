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

use InvalidArgumentException;
use ZCONF\Exception\SyntaxException;

/**
 * Внутренний класс для лексической обработки
 * @internal
 */
class ZLexer
{
    protected $newlineTokenName = 'T_NEWLINE';
    protected $eosTokenName = 'T_EOS';
    protected $activateNewlineToken = true;
    protected $activateEOSToken = true;
    protected $terminals = [
        '/^(=)/' => 'T_EQUAL',
        '/^(null)/' => 'T_NULL',
        '/^(true|false)/' => 'T_BOOLEAN',
        '/^(\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d{6})?(Z|-\d{2}:\d{2})?)?)/' => 'T_DATE_TIME',

        //'/^([+-]?((((\d_?)+[\.]?(\d_?)*)[eE][+-]?(\d_?)+)|((\d_?)+\.+)))/' => 'T_FLOAT',
        '/^([+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?)/' => 'T_FLOAT',

        '/^([+-]?(\d_?)+)/' => 'T_INTEGER',
        '/^(""")/' => 'T_3_QUOTATION_MARK',
        '/^(")/' => 'T_QUOTATION_MARK',
        "/^(''')/" => 'T_3_APOSTROPHE',
        "/^(')/" => 'T_APOSTROPHE',
        '/^(#)/' => 'T_HASH',
        '/^(\s+)/' => 'T_SPACE',
        '/^(\[)/' => 'T_LEFT_SQUARE_BRAKET',
        '/^(\])/' => 'T_RIGHT_SQUARE_BRAKET',
        '/^(\{)/' => 'T_LEFT_CURLY_BRACE',
        '/^(\})/' => 'T_RIGHT_CURLY_BRACE',
        '/^(,)/' => 'T_COMMA',
        '/^(\.)/' => 'T_DOT',
        '/^([-A-Z_a-z0-9]+)/' => 'T_UNQUOTED_KEY',
        '/^(\\\(b|t|n|f|r|"|\\\\|u[0-9AaBbCcDdEeFf]{4,4}|U[0-9AaBbCcDdEeFf]{8,8}))/' => 'T_ESCAPED_CHARACTER',
        '/^(\\\)/' => 'T_ESCAPE',
        '/^([\x{20}-\x{21}\x{23}-\x{26}\x{28}-\x{5A}\x{5E}-\x{10FFFF}]+)/u' => 'T_BASIC_UNESCAPED',
    ];

    /**
     * Генерирует специальный "T_NEWLINE" для каждой строки ввода
     *
     * @return ZLexer Сам Lexer
     */
    public function generateNewlineTokens(): ZLexer
    {
        $this->activateNewlineToken = true;

        return $this;
    }

    /**
     * Генерирует специальный "T_EOS" в конце входной строки
     *
     * @return ZLexer Сам Lexer
     */
    public function generateEosToken(): ZLexer
    {
        $this->activateEOSToken = true;

        return $this;
    }

    /**
     * Устанавливает имя токена новой строки
     *
     * @param string $name Имя токена
     *
     * @return ZLexer Сам Lexer
     *
     * @throws InvalidArgumentException Если имя пустое
     */
    public function setNewlineTokenName(string $name): ZLexer
    {
        if (strlen($name) == 0) {
            throw new InvalidArgumentException('Имя токена новой строки не должно быть пустым.');
        }

        $this->newlineTokenName = $name;

        return $this;
    }

    /**
     * Устанавливает имя токена конца строки
     *
     * @param string $name Имя токена
     *
     * @return ZLexer Сам Lexer
     *
     * @throws InvalidArgumentException Если имя пустое
     */
    public function setEosTokenName(string $name): ZLexer
    {
        if (strlen($name) == 0) {
            throw new InvalidArgumentException('Имя токена EOS не должно быть пустым.');
        }

        $this->eosTokenName = $name;

        return $this;
    }

    /**
     * {}
     */
    public function tokenize(string $input): ZStream
    {
        $counter = 0;
        $tokens = [];
        $lines = explode("\n", $input);
        $totalLines = count($lines);

        foreach ($lines as $number => $line) {
            $offset = 0;
            $lineNumber = $number + 1;

            while ($offset < strlen($line)) {
                list($name, $matches) = $this->match($line, $lineNumber, $offset);

                if (isset($matches[1])) {
                    $tokens[] = new ZToken($matches[1], $name, $lineNumber);
                }

                $offset += strlen($matches[0]);
            }

            if ($this->activateNewlineToken && ++$counter < $totalLines) {
                $tokens[] = new ZToken("\n", $this->newlineTokenName, $lineNumber);
            }
        }

        if ($this->activateEOSToken) {
            $tokens[] = new ZToken('', $this->eosTokenName, $lineNumber);
        }

        return new ZStream($tokens);
    }

    /**
     * Возвращает первое совпадение со списком терминалов
     *
     * @return array Массив со следующими ключами:
     *   [0] (string): имя токена
     *   [1] (array): совпадения регулярного выражения
     *
     * @throws SyntaxException Если строка не содержит ни одного токена
     */
    protected function match(string $line, int $lineNumber, int $offset): array
    {
        $restLine = substr($line, $offset);

        foreach ($this->terminals as $pattern => $name) {
            if (preg_match($pattern, $restLine, $matches)) {
                return [
                    $name,
                    $matches,
                ];
            }
        }

        throw new SyntaxException(sprintf('Ошибка лексера: не удалось разобрать "%s" в строке %s.', $line, $lineNumber));
    }
}
