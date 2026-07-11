<?php

/**
 * Report is the abstract base class for parsing and validating COUNTER Reports and Standard Views
 *
 * A Report can be created from a COUNTER Report file in JSON or tabular format using {@see Report::fromFile()} or
 * by instantiating a {@see JsonReport} or {@see TabularReport} from a {@see FileDocument} or {@see BufferDocument}.
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools;

use ubfr\c5tools\data\ReportHeader;
use ubfr\c5tools\exceptions\UnusableCheckedDocumentException;

abstract class Report implements interfaces\CheckedDocument // , \Countable, \IteratorAggregate
{
    use traits\CheckedDocument;
    use traits\Parsers;
    use traits\Checks;
    use traits\Helpers;

    protected bool $isParsingHeader;

    abstract protected function getReleaseFromReport(): ?string;

    abstract protected function parseHeader(): void;

    abstract protected function parseDocument(): void;

    protected function __construct()
    {
        $release = $this->getReleaseFromReport();
        if ($release === null) {
            $this->setParsingHeader();
            $this->setParsedHeader();
            $this->setParsing();
            $this->setParsed();
            $this->setUnusable();
            return;
        }
        $this->config = Config::forRelease($release);

        $this->parseHeader();
    }

    public static function fromFile(string $filename, ?string $extension = null): Report
    {
        $document = new FileDocument($filename, $extension);
        if ($document->isJson()) {
            return new JsonReport($document);
        } else {
            return new TabularReport($document);
        }
    }

    public function getCheckResult(): CheckResult
    {
        if (! $this->isParsed && ! ($this->isParsingHeader || $this->isParsing)) {
            $this->parseDocument();
        }

        return $this->checkResult;
    }

    public function get(string $property, bool $keepInvalid = false)
    {
        if (! $this->isParsed && $property !== 'Report_Header') {
            $this->parseDocument();
        }

        if (isset($this->data[$property])) {
            return $this->data[$property];
        }
        // TODO: fully implement keepInvalid option
        if ($keepInvalid && isset($this->invalid[$property])) {
            return $this->invalid[$property];
        }
        return null;
    }

    public function getReportHeader(): ReportHeader
    {
        return $this->get('Report_Header');
    }

    protected function setParsingHeader(): void
    {
        if (isset($this->isParsingHeader) && $this->isParsingHeader) {
            throw new \LogicException('parsing header loop detected');
        }
        if ($this->isParsing || $this->isParsed) {
            throw new \LogicException('header already parsed');
        }
        $this->isParsingHeader = true;
    }

    protected function setParsedHeader(): void
    {
        if (! $this->isParsingHeader) {
            throw new \LogicException('parsing header flag not set');
        }
        $this->isParsingHeader = false;
    }

    protected function setParsed(): void
    {
        if (! ($this->isParsingHeader || $this->isParsing)) {
            throw new \LogicException('parsing flag not set');
        }
        $this->isParsingHeader = false;
        $this->isParsing = false;
        $this->isParsed = true;
    }

    // TODO: move to TabularReport, add abtract method
    public function asJson(): \stdClass
    {
        if (! $this->isUsable()) {
            throw new UnusableCheckedDocumentException(get_class($this) . ' is unusable');
        }

        $json = new \stdClass();
        $json->Report_Header = $this->get('Report_Header')->asJson($this->isFixed());
        $json->Report_Items = $this->get('Report_Items')->asJson();

        return $json;
    }

    protected function checkMetricTypesNotPresent(array $metricTypesPresent, string $position): void
    {
        $metricTypesPermitted = $this->get('Report_Header')->getReportValues()['Metric_Type'];
        $metricTypesNotPresent = array_diff($metricTypesPermitted, $metricTypesPresent);
        if (! empty($metricTypesNotPresent)) {
            $message = "Metric_Type(s) valid in this report but not present: '" . implode("', '", $metricTypesNotPresent) .
                "'";
            $this->addNotice($message, $message, $position, 'Metric_Type');
        }
    }
}
