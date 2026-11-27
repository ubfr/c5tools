<?php

/**
 * FailedCounterApiRequestException is thrown for failed COUNTER API requests
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\exceptions;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FailedCounterApiRequestException extends CounterApiRequestException
{
    protected ?ResponseInterface $response;

    public function __construct(
        string $message,
        string $url,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $url, $request, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
