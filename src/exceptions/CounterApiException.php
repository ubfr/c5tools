<?php

/**
 * CounterApiException is the main class for parsing and validating COUNTER API Exceptions
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\exceptions;

use Psr\Http\Message\ResponseInterface;
use ubfr\c5tools\CheckResult;
use ubfr\c5tools\Config;
use ubfr\c5tools\Document;
use ubfr\c5tools\CounterApiRequest;
use ubfr\c5tools\data\Exception;
use ubfr\c5tools\traits\CheckedDocument;
use ubfr\c5tools\traits\Checks;
use ubfr\c5tools\traits\Helpers;
use ubfr\c5tools\traits\Parsers;

class CounterApiException extends \Exception implements
    \ubfr\c5tools\interfaces\CheckedDocument,
    \ubfr\c5tools\interfaces\JsonDocument
{
    use CheckedDocument;
    use Parsers;
    use Checks;
    use Helpers;

    protected static array $fixProperties = [
        'Number' => 'Code'
    ];

    protected static array $requiredProperties = [
        'Code',
        'Message'
    ];

    protected static array $optionalProperties = [
        'Severity',
        'Help_URL',
        'Data'
    ];

    protected static array $permittedSeverities = [
        'Fatal',
        'Error',
        'Warning',
        'Debug',
        'Info'
    ];

    public function __construct(Document $document, ?CounterApiRequest $request = null)
    {
        if (! $document->isException()) {
            throw new \InvalidArgumentException("document is not valid for CounterApiException");
        }

        $this->document = $document;
        $this->request = $request;
        $this->checkResult = new CheckResult();
        $this->config = Config::forRelease($request !== null ? $request->getRelease() : '5.1');
        $this->format = self::FORMAT_JSON;
        $this->position = '.';

        $this->checkNoByteOrderMark();

        // the document needs to be parsed for initializing the Exception
        $this->parseDocument();

        parent::__construct($this->get('Message') ?? '', $this->get('Code'));
    }

    public function getJsonString(): string
    {
        return $this->document->getBuffer();
    }

    public function getJson()
    {
        return $this->document->getDocument();
    }

    public function getUrL(): ?string
    {
        if ($this->request === null) {
            return null;
        }
        return $this->request->getRequestUrl();
    }

    public function getHttpResponse(): ResponseInterface
    {
        return $this->document->getHttpResponse();
    }

    protected function parseDocument(): void
    {
        $this->setParsing();

        // response is either an object or an array (checked in Document::is[ReportWith]Exception)
        $json = $this->getJson();
        $message = 'JSON document must be a single Exception object, ';
        if (is_array($json)) {
            // array with at least one Exception
            $message .= 'found an array';
            $hint = 'if there are multiple Exceptions the one with the lowest Code should be returned';
            $this->addError($message, $message, '.', null, $hint);
            $this->parseExceptionList('.', $json);
        } elseif ($this->hasProperty($json, 'Exception')) {
            // top level Exception element
            $message .= 'found an object with a top-level Exception element';
            $this->addError($message, $message, '.', null);
            $this->parseSingleException(".Exception", $json->Exception);
        } elseif ($this->hasProperty($json, 'Report_Header')) {
            // Report with Exceptions
            $message .= 'found a Report_Header with Exceptions';
            $hint = 'if there are multiple Exceptions only the one with the lowest Code should be returned';
            $this->addError($message, $message, '.', null, $hint);
            $this->parseExceptionList('.Report_Header.Exceptions', $json->Report_Header->Exceptions);
        } else {
            // regular Exception (stand-alone or within a report)
            $this->parseSingleException($this->position, $json);
        }

        $this->setParsed();

        if ($this->get('Code') === null) {
            $this->setUnusable();
        }
    }

    protected function parseSingleException(string $position, object $json): void
    {
        $exception = new Exception($this, $position, $json);
        if ($exception->isUsable()) {
            $this->setData('.', $exception);
            if ($exception->isFixed()) {
                $this->setFixed('.', $json);
            }
            foreach ($exception->getData() as $property => $value) {
                $this->setData($property, $value);
            }
        } else {
            // since invalid Codes are kept this shouldn't happen...
            throw new \LogicException('CounterApiException is unusable: ' . json_encode($json));
        }
    }

    protected function parseExceptionList(string $position, array $json): void
    {
        $preferredException = null;
        foreach ($json as $index => $element) {
            $this->setIndex($index);
            $positionIndex = "{$position}[{$index}]";
            if (! $this->isArrayValueObject($positionIndex, '.', $element)) {
                continue;
            }
            $exception = new Exception($this, $positionIndex, $element);
            if ($exception->isUsable()) {
                $this->setData('.', $exception);
                if ($exception->isFixed()) {
                    $this->setFixed('.', $element);
                }
                if (
                    $exception->get('Code') >= 1000 &&
                    ($preferredException === null || $exception->get('Code') < $preferredException->get('Code'))
                ) {
                    $preferredException = $exception;
                }
            } else {
                $this->setInvalid('.', $exception);
            }
        }
        $this->setIndex(null);

        if ($preferredException !== null) {
            foreach ($preferredException->getData() as $property => $value) {
                $this->setData($property, $value);
            }
        }
    }
}
