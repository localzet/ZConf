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
use ZCONF\Internal\ZToken;

/**
 * Исключение, выбрасываемое при возникновении ошибки во время разбора или токенизации.
 */
class SyntaxException extends RuntimeException
{
    protected $token;

    /**
     * Конструктор.
     *
     * @param string $message Сообщение об ошибке.
     * @param ZToken|null $token Токен.
     * @param Exception|null $previous Предыдущее исключение.
     */
    public function __construct(string $message, ZToken $token = null, Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Устанавливает токен, связанный с исключением.
     *
     * @param ZToken $token Токен.
     */
    public function setToken(ZToken $token): void
    {
        $this->token = $token;
    }

    /**
     * Возвращает токен, связанный с исключением.
     *
     * @return ZToken|null
     */
    public function getToken(): ?ZToken
    {
        return $this->token;
    }
}
