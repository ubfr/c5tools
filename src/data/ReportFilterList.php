<?php

/**
 * ReportFilterList handles JSON COUNTER R5 Report Filter lists
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\data;

class ReportFilterList extends NameValueList
{
    protected string $reportId;

    protected array $filtersConfig;

    public function __construct(
        \ubfr\c5tools\interfaces\CheckedDocument $parent,
        string $position,
        $document,
        ?array $filterNames = null
    ) {
        $this->reportId = $parent->getReportId();
        $this->filtersConfig = $parent->getConfig()->getReportFilters($this->reportId);
        if ($filterNames !== null) {
            $this->filtersConfig = array_intersect_key($this->filtersConfig, array_flip($filterNames));
        }

        parent::__construct($parent, $position, $document, 'Report_Filters', $this->filtersConfig);
    }

    public function asArray(): array
    {
        $reportFilters = [];
        foreach ($this->getData() as $key => $values) {
            if (isset($this->filtersConfig[$key]['multi'])) {
                $reportFilters[$key] = $values;
            } else {
                $reportFilters[$key] = $values[0];
            }
        }

        return $reportFilters;
    }

    protected function parseDocument(): void
    {
        $this->setParsing();

        parent::parseDocument();

        $this->checkYopFilters();
        $this->checkRequiredReportFilters();
        $this->checkDateFilters();
        $this->checkOtherFilters();

        foreach ($this->values as $name => $values) {
            if (! empty($values)) {
                $this->setData($name, $values);
            }
        }

        $this->setParsed();

        if ($this->get('Begin_Date') === null || $this->get('End_Date') === null) {
            $message = ($this->isJson() ? "{$this->position}." : 'Reporting_Period ') . "Begin_Date/End_Date is " .
                (($this->getInvalid('Begin_Date') !== null || $this->getInvalid('End_Date') !== null) ? 'invalid' : 'missing');
            $this->addFatalError($message, $message);
            $this->setUnusable();
        }
    }

    protected function checkYopFilters(): void
    {
        if (! isset($this->values['YOP'])) {
            return;
        }

        $checkedValues = [];
        $checkedIndices = [];
        foreach ($this->values['YOP'] as $i => $value) {
            $index = $this->indices['YOP'][$i];
            $this->setIndex($index);
            $position = $this->getHeaderPosition('YOP', $index);
            $data = $this->formatData('YOP', $value);

            $matches = [];
            if (! preg_match('/^([0-9]{4})(?:-([0-9]{4}))?$/', $value, $matches)) {
                $summary = 'YOP filter is invalid';
                $message = "YOP filter '{$value}' is invalid";
                $hint = 'value must be a year (yyyy) or a range of years (yyyy-yyyy)';
                $this->addCriticalError($summary, $message, $position, $data, $hint);
                if ($this->config->getRelease() === '5') {
                    $this->setInvalid('YOP', $this->entries[$index]);
                } else {
                    $this->setInvalid('YOP', $this->values['YOP'][$i]);
                }
                $this->setUnusable();
                unset($this->values['YOP'][$i]);
                unset($this->indices['YOP'][$i]);
                continue;
            }
            $yearFrom = $matches[1];
            $yearTo = (count($matches) === 3 ? $matches[2] : $matches[1]);
            if ($yearFrom === '0000') {
                $message = "YOP 0000 is not a valid year, using 0001 instead";
                $this->addError($message, $message, $position, $data);
                if ($this->config->getRelease() === '5') {
                    $this->setFixed('.', $this->entries[$index]);
                } else {
                    $this->setFixed('YOP', $this->values['YOP'][$i]);
                }
                $yearFrom = '0001';
                if ($yearTo === '0000') {
                    $yearTo = '0001';
                }
            }
            if ($yearFrom > $yearTo) {
                $summary = 'YOP filter is not a valid range of years';
                $message = "YOP filter '{$value}' is not a valid range of years";
                $this->addCriticalError($summary, $message, $position, $data);
                if ($this->config->getRelease() === '5') {
                    $this->setInvalid('YOP', $this->entries[$index]);
                } else {
                    $this->setInvalid('YOP', $this->values['YOP'][$i]);
                }
                $this->setUnusable();
                unset($this->values['YOP'][$i]);
                unset($this->indices['YOP'][$i]);
                continue;
            }
            if (isset($checkedValues[$yearFrom])) {
                // merge with existing range starting with the same year
                if ($yearTo >= $checkedValues[$yearFrom]) {
                    $checkedValues[$yearFrom] = $yearTo;
                    $checkedIndices[$yearFrom] = $index;
                }
            } else {
                $checkedValues[$yearFrom] = $yearTo;
                $checkedIndices[$yearFrom] = $index;
            }
        }
        $this->setIndex(null);

        // merge overlapping ranges
        ksort($checkedValues);
        $lastFrom = null;
        $lastTo = null;
        foreach ($checkedValues as $from => $to) {
            if ($lastFrom === null) {
                $lastFrom = $from;
                $lastTo = $to;
                continue;
            }
            if ($from <= $lastTo + 1) {
                if ($to > $lastTo) {
                    $checkedValues[$lastFrom] = $to;
                    $lastTo = $to;
                }
                unset($checkedValues[$from]);
                unset($checkedIndices[$from]);
                continue;
            }
            $lastFrom = $from;
            $lastTo = $to;
        }

        if (count($checkedValues) === 1 && isset($checkedValues['0001']) && $checkedValues['0001'] === '9999') {
            $message = "YOP filter value '0001-9999' is the default and must be omitted";
            $data = $this->formatData('YOP', '0001-9999');
            $this->addError($message, $message, $position, $data);
            unset($this->values['YOP']);
            unset($this->indices['YOP']);
        } else {
            $this->values['YOP'] = $checkedValues;
            $this->indices['YOP'] = $checkedIndices;
        }
    }

    protected function checkRequiredReportFilters()
    {
        if ($this->config->isFullReport($this->reportId)) {
            return;
        }

        foreach ($this->values as $name => $values) {
            $filterConfig = $this->filtersConfig[$name];
            if (! isset($filterConfig['multi']) || ! $filterConfig['multi']) {
                continue;
            }

            $indices = implode(',', array_unique($this->indices[$name]));
            $position = $this->getHeaderPosition($name, $indices);
            $headerName = $this->getHeaderName($name);
            $separator = ($headerName === 'Metric_Types' ? '; ' : '|');
            $separatedValues = ($headerName === 'Report_Filters' ? "{$name}=" : '') . implode($separator, $values);
            $missingValues = array_diff($filterConfig['values'], $values);
            foreach ($missingValues as $missingValue) {
                $message = "{$name} value '{$missingValue}' required for this report is missing";
                $data = $this->formatData($headerName, $separatedValues);
                $this->addCriticalError($message, $message, $position, $data);
                $this->setFixed($name, $separatedValues);
                $this->values[$name][] = $missingValue;
            }
        }

        foreach ($this->filtersConfig as $name => $filterConfig) {
            if (! isset($filterConfig['multi']) || ! $filterConfig['multi']) {
                continue;
            }

            if (! isset($this->values[$name]) && ! isset($this->invalid[$name])) {
                $position = $this->getHeaderPosition($name, null);
                $headerName = $this->getHeaderName($name);
                $separator = ($headerName === 'Metric_Types' ? '; ' : '|');
                $values = implode($separator, $filterConfig['values']);
                if ($headerName === 'Report_Filters') {
                    $message = "{$headerName} '{$name}={$values}' required for this report is missing";
                } else {
                    $message = "{$headerName} '{$values}' required for this report is missing";
                }
                $this->addCriticalError($message, $message, $position, $headerName);
                $this->setFixed($name, $values);
                $this->values[$name] = $filterConfig['values'];
            }
        }
    }

    protected function checkDateFilters(): void
    {
        foreach (
            [
            'Begin_Date',
            'End_Date'
            ] as $name
        ) {
            $headerName = $this->getHeaderName($name);
            if (isset($this->values[$name])) {
                if (empty($this->values[$name])) {
                    continue;
                }
                $index = $this->indices[$name][0];
                $this->setIndex($index);
                $position = $this->getHeaderPosition($name, $index);
                $checkedDate = $this->checkedDate($position, $name, $this->values[$name][0]);
                if ($checkedDate === null) {
                    $this->setInvalid($name, $this->values[$name][0]);
                    $this->setUnusable();
                    unset($this->values[$name]);
                    unset($this->indices[$name]);
                } elseif ($checkedDate !== $this->values[$name][0]) {
                    $this->values[$name][0] = $checkedDate;
                }
            } else {
                $message = "{$headerName} '{$name}' required for this report is missing";
                $position = $this->getHeaderPosition($name, null);
                $this->addCriticalError($message, $message, $position, $headerName);
                $this->setUnusable();
            }
        }

        if (
            isset($this->values['Begin_Date']) && ! empty($this->values['Begin_Date']) &&
            isset($this->values['End_Date']) && ! empty($this->values['End_Date'])
        ) {
            if ($this->values['End_Date'][0] < $this->values['Begin_Date'][0]) {
                $summary = 'End_Date is before Begin_Date';
                $message = "End_Date '" . $this->values['End_Date'][0] . "' is before Begin_Date '" .
                    $this->values['Begin_Date'][0] . "'";
                $index = $this->indices['End_Date'][0];
                $this->setIndex($index);
                $headerName = $this->getHeaderName('End_Date');
                $position = $this->getHeaderPosition('End_Date', $index);
                $data = $this->formatData('End_Date', $this->values['End_Date'][0]);
                $this->addCriticalError($summary, $message, $position, $data);
                $this->setInvalid('Begin_Date', $this->values['Begin_Date'][0]);
                $this->setInvalid('End_Date', $this->values['End_Date'][0]);
                $this->setUnusable();
                unset($this->values['Begin_Date']);
                unset($this->values['End_Date']);
            }
        }

        $this->setIndex(null);
    }

    protected function checkOtherFilters(): void
    {
        // empty values are handled by the parent class, so in contrast to R5.1 only the codes have to be checked
        static $otherFilters = [
            'Country_Code' => 'checkedCountryCode',
            'Subdivision_Code' => 'checkedSubdivisionCode'
        ];

        $this->setIndex(0);
        foreach ($otherFilters as $name => $checkMethod) {
            if (isset($this->values[$name]) && ! empty($this->values[$name])) {
                $position = ($this->isJson() ? "{$this->position}.{$name}" : $this->position);
                $value = $this->$checkMethod($position, $name, $this->values[$name][0]);
                if ($value === null) {
                    unset($this->values[$name]);
                }
            }
        }
        $this->setIndex(null);
    }
}
