<?php

/**
 * CounterApiResponseException is the abstract base for Exceptions thrown for invalid COUNTER API responses
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\exceptions;

use Psr\Http\Message\ResponseInterface;

abstract class CounterApiResponseException extends \Exception
{
    protected string $url;

    protected ResponseInterface $response;

    public function __construct(
        string $message,
        string $url,
        ResponseInterface $response,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->url = $url;
        $this->response = $response;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
