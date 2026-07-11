<?php

/**
 * TabularReport is the main class for parsing and validating tabular COUNTER Reports and Standard Views
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ubfr\c5tools\data\TabularReportHeader;
use ubfr\c5tools\data\TabularReportItemList;
use ubfr\c5tools\data\TabularItemList51;

class TabularReport extends Report
{
    public function __construct(Document $document, ?CounterApiRequest $request = null)
    {
        if ($document->isJson()) {
            throw new \InvalidArgumentException('document is not valid for tabular Report');
        }

        $this->document = $document;
        $this->request = $request;
        $this->checkResult = new CheckResult();
        $this->format = self::FORMAT_TABULAR;

        $this->checkByteOrderMark();

        parent::__construct();
    }

    // TODO: interface TabularDocument?
    public function getSpreadsheet()
    {
        return $this->document->getDocument();
    }

    protected function getReleaseFromReport(): ?string
    {
        $spreadsheet = $this->getSpreadsheet();
        $releaseLabelCell = $spreadsheet->getActiveSheet()->getCell('A3', false);
        if (
            $releaseLabelCell !== null && $releaseLabelCell->getValue() !== null &&
            $this->fuzzy($releaseLabelCell->getValue()) === 'release'
        ) {
            $releaseValueCell = $spreadsheet->getActiveSheet()->getCell('B3', false);
            if ($releaseValueCell !== null) {
                $release = trim((string) $releaseValueCell->getValue());
                if (in_array($release, Config::supportedReleases())) {
                    return $release;
                } else {
                    $message = "Release '{$release}' not supported";
                    $data = $this->formatData('Release', $release);
                    $this->addCriticalError($message, $message, 'B3', $data);
                    return null;
                }
            }
        }

        // Release missing or unusable, using default release
        $message = "Release is missing or unusable, assuming '" . Config::$defaultRelease . "'";
        $this->addWarning($message, $message, 'B3', null);

        return Config::$defaultRelease;
    }

    protected function parseHeader(): void
    {
        $this->setParsingHeader();

        $spreadsheet = $this->getSpreadsheet();
        $sheetCount = $spreadsheet->getSheetCount();
        if ($sheetCount > 1) {
            $summary = 'Spreadsheet must contain only one sheet';
            $message = $summary . ", found {$sheetCount} sheets";
            // since setReadDataOnly(true) is used getActiveSheet() returns the first sheet
            $hint = 'only the first sheet will be checked';
            $this->addError($summary, $message, null, null, $hint);
        }
        $sheet = $spreadsheet->getActiveSheet();

        $reportHeaderRows = $this->getHeaderRowsAsArray($sheet);
        $reportHeader = new TabularReportHeader($this, '1', $reportHeaderRows);
        // keep Report_Header even if it is invalid
        $this->setData('Report_Header', $reportHeader);

        if (! in_array($reportHeader->getReportId(), $this->config->getReportIds())) {
            // checkedReportId already has added a fatal error
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        // unusable check is delayed (see below), so that the Report_Items can be checked
        if (! $reportHeader->canComputeReportElementsAndValues()) {
            $message = 'Due to errors in the report header the report body was not checked';
            $position = count($reportHeaderRows) + 2;
            $data = 'Report Body';
            $this->addNotice($message, $message, $position, $data);

            $this->setParsed();
            $this->setUnusable();
            return;
        }
        if ($reportHeader->isFixed()) {
            $this->setFixed('Report_Header', $reportHeaderRows);
        }

        $this->setParsedHeader();
    }

    protected function getHeaderRowsAsArray(Worksheet $sheet): array
    {
        $numberOfRows = $sheet->getHighestRow();
        $headerRows = [];
        foreach ($sheet->getRowIterator(1, min($numberOfRows, $this->config->getNumberOfHeaderRows() + 1)) as $row) {
            $headerRows[$row->getRowIndex()] = $this->getRowValues($row);
        }
        for ($rowNumber = $numberOfRows + 1; $rowNumber <= $this->config->getNumberOfHeaderRows() + 1; $rowNumber++) {
            $headerRows[$rowNumber] = [];
        }

        return $headerRows;
    }

    protected function parseDocument(): void
    {
        $this->setParsing();

        $sheet = $this->getSpreadsheet()->getActiveSheet();
        $numberOfRows = $sheet->getHighestRow();
        $columnHeadingsRowNumber = $this->config->getNumberOfHeaderRows() + 2;

        if ($numberOfRows < $columnHeadingsRowNumber) {
            $message = "Column headings are missing";
            $this->addCriticalError($message, $message, $columnHeadingsRowNumber, null);
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        if ($this->config->getRelease() === '5') {
            $reportItems = new TabularReportItemList($this, $columnHeadingsRowNumber, $this->document);
        } else {
            $reportItems = new TabularItemList51($this, $columnHeadingsRowNumber, $this->document);
        }

        if ($reportItems->getBodyRowsParsed() > 0) {
            $this->checkMetricTypesNotPresent($reportItems->getMetricTypesPresent(), $numberOfRows + 1);
        }

        if (! empty($reportItems->getReportColumns())) {
            $this->get('Report_Header')->checkException303x($reportItems->getBodyRowsParsed());
        }

        // delayed check for unusable Report_header (see above)
        if (! $this->get('Report_Header')->isUsable()) {
            $this->setUnusable();
        }

        if (! $reportItems->isUsable()) {
            $this->setParsed();
            $this->setUnusable();
            return;
        }

        $this->setData('Report_Items', $reportItems);
        // no setFixed here, since it would require converting the whole report body

        $this->setParsed();
    }
}
