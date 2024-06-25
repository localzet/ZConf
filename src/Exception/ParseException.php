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

namespace ZCONF\Exception;

use Exception;
use RuntimeException;

/**
 * Класс исключения, выбрасываемый при возникновении ошибки во время разбора.
 * Основан на ParseException компонента YAML от Symfony.
 */
class ParseException extends RuntimeException
{
    private $parsedFile;
    private $parsedLine;
    private $snippet;
    private $rawMessage;

    /**
     * Конструктор.
     *
     * @param string $message Сообщение об ошибке
     * @param int $parsedLine Строка, где произошла ошибка
     * @param string|null $snippet Фрагмент кода рядом с проблемой
     * @param string|null $parsedFile Имя файла, где произошла ошибка
     * @param Exception|null $previous Предыдущее исключение
     */
    public function __construct(string $message, int $parsedLine = -1, string $snippet = null, string $parsedFile = null, Exception $previous = null)
    {
        $this->parsedFile = $parsedFile;
        $this->parsedLine = $parsedLine;
        $this->snippet = $snippet;
        $this->rawMessage = $message;

        $this->updateRepr();

        parent::__construct($this->message, 0, $previous);
    }

    /**
     * Получает фрагмент кода рядом с ошибкой.
     *
     * @return string Фрагмент кода
     */
    public function getSnippet(): string
    {
        return $this->snippet;
    }

    /**
     * Устанавливает фрагмент кода рядом с ошибкой.
     *
     * @param string $snippet Фрагмент кода
     */
    public function setSnippet(string $snippet): void
    {
        $this->snippet = $snippet;

        $this->updateRepr();
    }

    /**
     * Получает имя файла, где произошла ошибка.
     *
     * Этот метод возвращает null, если анализируется строка.
     *
     * @return string Имя файла
     */
    public function getParsedFile(): string
    {
        return $this->parsedFile;
    }

    /**
     * Устанавливает имя файла, где произошла ошибка.
     *
     * @param string $parsedFile Имя файла
     */
    public function setParsedFile(string $parsedFile): void
    {
        $this->parsedFile = $parsedFile;

        $this->updateRepr();
    }

    /**
     * Получает строку, где произошла ошибка.
     *
     * @return int Номер строки файла
     */
    public function getParsedLine(): int
    {
        return $this->parsedLine;
    }

    /**
     * Устанавливает строку, где произошла ошибка.
     *
     * @param int $parsedLine Номер строки файла
     */
    public function setParsedLine(int $parsedLine): void
    {
        $this->parsedLine = $parsedLine;

        $this->updateRepr();
    }

    /**
     * Обновляет представление сообщения об ошибке.
     */
    private function updateRepr(): void
    {
        $this->message = $this->rawMessage;

        $dot = false;
        if ('.' === substr($this->message, -1)) {
            $this->message = substr($this->message, 0, -1);
            $dot = true;
        }

        if (null !== $this->parsedFile) {
            $this->message .= sprintf(' в %s', json_encode($this->parsedFile));
        }

        if ($this->parsedLine >= 0) {
            $this->message .= sprintf(' на строке %d', $this->parsedLine);
        }

        if ($this->snippet) {
            $this->message .= sprintf(' (рядом с "%s")', $this->snippet);
        }

        if ($dot) {
            $this->message .= '.';
        }
    }
}
