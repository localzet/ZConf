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

use RuntimeException;
use Throwable;

/**
 * Класс исключения, выбрасываемый при возникновении ошибки во время выгрузки.
 *
 * @package ZCONF\Exception
 */
class DumpException extends RuntimeException
{
    /**
     * Конструктор класса DumpException.
     *
     * @param string $message Сообщение об ошибке.
     * @param int $code Код ошибки.
     * @param Throwable|null $previous Предыдущее исключение.
     */
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
