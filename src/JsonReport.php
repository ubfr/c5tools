<?php

/**
 * JsonReport is the main class for parsing and validating JSON COUNTER Reports and Standard Views
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools;

use ubfr\c5tools\data\JsonReportHeader;
use ubfr\c5tools\data\JsonParentItemList51;
use ubfr\c5tools\data\JsonReportItemList;
use ubfr\c5tools\data\JsonReportItemList51;

class JsonReport extends Report implements interfaces\JsonDocument
{
    protected static array $requiredProperties = [
        'Report_Header',
        'Report_Items'
    ];

    protected $jsonItems;

    public function __construct(Document $document, ?CounterApiRequest $request = null)
    {
        if (! $document->isReport()) {
            throw new \InvalidArgumentException('document is not valid for Report');
        }

        $this->document = $document;
        $this->request = $request;
        $this->checkResult = new CheckResult();
        $this->format = self::FORMAT_JSON;
        $this->position = '.';

        $this->jsonItems = null;

        $this->checkHttpCode200('report');
        $this->checkNoByteOrderMark();

        parent::__construct();
    }

    public function getJsonString(): string
    {
        return $this->document->getBuffer();
    }

    public function getJson()
    {
        return $this->document->getDocument();
    }

    public function asJson(): \stdClass
    {
        if (! $this->isFixed()) {
            return $this->getJson();
        } else {
            $json = new \stdClass();
            $json->Report_Header = $this->get('Report_Header')->asJson($this->isFixed());
            $json->Report_Items = $this->get('Report_Items')->asJson();

            return $json;
        }
    }

    protected function getReleaseFromReport(): ?string
    {
        $reportHeader = $this->getProperty($this->getJson(), 'Report_Header');
        if ($reportHeader !== null && is_object($reportHeader)) {
            $release = $this->getProperty($reportHeader, 'Release');
            if ($release !== null && is_scalar($release)) {
                $release = trim((string) $release);
                if (in_array($release, Config::supportedReleases())) {
                    return $release;
                } else {
                    $message = "Release '{$release}' not supported";
                    $position = '.Report_Header.Release';
                    $data = $this->formatData('Release', $release);
                    $this->addCriticalError($message, $message, $position, $data);
                    return null;
                }
            }
        }
        // Release missing or unusable, using default release
        return Config::$defaultRelease;
    }

    protected function parseHeader(): void
    {
        $this->setParsingHeader();

        $json = $this->getJson();
        $properties = $this->getObjectProperties('.', 'Report', $json, self::$requiredProperties, []);
        if (! isset($properties['Report_Header'])) {
            $message = '.Report_Header is missing';
            $this->addFatalError($message, $message);
            $this->setParsed();
            $this->setUnusable();
            return;
        }
        $this->jsonItems = ($properties['Report_Items'] ?? null);

        $property = 'Report_Header';
        $position = ".{$property}";

        // Report_Header is present (already checked in Document::isReport)
        if (! $this->isObject($position, $property, $properties[$property])) {
            $message = "{$property} is unusable";
            $this->addFatalError($message, $message);
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        $reportHeader = new JsonReportHeader($this, $position, $properties[$property]);
        // keep Report_Header even if it is invalid
        $this->setData($property, $reportHeader);

        if (! in_array($reportHeader->getReportId(), $this->config->getReportIds())) {
            // checkedReportId already has added a fatal error
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        if ($this->jsonItems === null || is_array($this->jsonItems)) {
            $reportHeader->checkException303x($this->jsonItems === null ? 0 : count($this->jsonItems));
        }

        // unusable check is delayed (see below), so that the Report_Items can be checked
        if (! $reportHeader->canComputeReportElementsAndValues()) {
            $message = 'Due to errors in the Report_Header the Report_Items were not checked';
            $position = '.Report_Items';
            $data = 'Report_Items';
            $this->addNotice($message, $message, $position, $data);

            $this->setParsed();
            $this->setUnusable();
            return;
        }
        if ($reportHeader->isFixed()) {
            $this->setFixed($property, $properties[$property]);
        }

        $this->setParsedHeader();
    }

    protected function parseDocument(): void
    {
        $this->setParsing();

        // delayed check for unusable Report_header (see above)
        if (! $this->get('Report_Header')->isUsable()) {
            $this->setUnusable();
        }

        if ($this->jsonItems === null) {
            // error already handled by parseHeader
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        $property = 'Report_Items';
        $position = ".{$property}";

        if (! is_array($this->jsonItems)) {
            $type = (is_object($this->jsonItems) ? 'an object' : 'a scalar');
            $message = "{$property} must be an array, found {$type}";
            $this->addFatalError($message, $message);
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        if ($this->config->getRelease() === '5') {
            $reportItems = new JsonReportItemList($this, $position, $this->jsonItems);
        } elseif (
            $this->config->isItemReport($this->get('Report_Header')
            ->getReportId())
        ) {
            $reportItems = new JsonParentItemList51($this, $position, $this->jsonItems);
        } else {
            $reportItems = new JsonReportItemList51($this, $position, $this->jsonItems);
        }

        if (count($this->jsonItems) > 0) {
            $this->checkMetricTypesNotPresent($reportItems->getMetricTypesPresent(), '.Report_Items');
        }

        if (! $reportItems->isUsable()) {
            $this->setParsed();
            $this->setUnusable();
            $this->jsonItems = null;
            return;
        }

        $this->setData($property, $reportItems);
        if ($reportItems->isFixed()) {
            $this->setFixed($property, $this->jsonItems);
        }

        $this->setParsed();

        $this->jsonItems = null;
    }
}
