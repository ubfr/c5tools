<?php

/**
 * CounterApiRequestException is the abstract base for Exceptions thrown for invalid or failed COUNTER API requests
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\exceptions;

use Psr\Http\Message\RequestInterface;

abstract class CounterApiRequestException extends \Exception
{
    protected string $url;

    protected ?RequestInterface $request;

    public function __construct(
        string $message,
        string $url,
        ?RequestInterface $request = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->url = $url;
        $this->request = $request;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getRequest(): ?RequestInterface
    {
        return $this->request;
    }
}
