<?php

/**
 * ReportItem is the abstract base class for handling COUNTER R5 Report Item list entries
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\data;

use ubfr\c5tools\traits\CheckedDocument;
use ubfr\c5tools\traits\Checks;
use ubfr\c5tools\traits\Helpers;
use ubfr\c5tools\traits\Parsers;
use ubfr\c5tools\traits\Performance;
use ubfr\c5tools\exceptions\ConfigException;

abstract class ReportItem implements \ubfr\c5tools\interfaces\CheckedDocument
{
    use CheckedDocument, Parsers, Checks, Helpers, Performance {
        Performance::asJson insteadof CheckedDocument;
    }

    protected ReportItemList $reportItems;

    /**
     * Positions of merged ReportItems with the same metadata and attributes.
     *
     * @var array
     */
    protected array $reportItemPositions;

    protected array $hashes;

    abstract protected function parseDocument(): void;

    abstract protected function mergeMetricTypeDateError(
        ReportItem $reportItem,
        string $metricType,
        string $date,
        array $count
    ): void;

    public function __construct(
        \ubfr\c5tools\interfaces\CheckedDocument $parent,
        string $position,
        int $index,
        $document
    ) {
        $this->document = $document;
        $this->checkResult = $parent->getCheckResult();
        $this->config = $parent->getConfig();
        $this->format = $parent->getFormat();
        $this->position = $position;

        $this->reportHeader = $parent->getReportHeader();
        $this->reportItems = $parent;
        $this->reportItemPositions = [
            $this->isJson() ? $index : $position
        ];
        $this->hashes = [];
    }

    public function getReportItemPositions(): array
    {
        return $this->reportItemPositions;
    }

    public function getFirstReportItemPosition(): int
    {
        return $this->reportItemPositions[0];
    }

    protected function getDateForMonthlyColumnHeading(string $monthlyColumnHeading): string
    {
        static $dateForMonthlyColumnHeading = [];

        if (! isset($dateForMonthlyColumnHeading[$monthlyColumnHeading])) {
            $month = \DateTime::createFromFormat('!M-Y', $monthlyColumnHeading);
            if ($month === false) {
                throw new \InvalidArgumentException("monthly column heading {$monthlyColumnHeading} invalid");
            }
            $dateForMonthlyColumnHeading[$monthlyColumnHeading] = $month->format('Y-m');
        }

        return $dateForMonthlyColumnHeading[$monthlyColumnHeading];
    }

    protected function getMonthlyColumnHeadingForDate(string $date): string
    {
        static $monthlyColumnHeadingForDate = [];

        if (! isset($monthlyColumnHeadingForDate[$date])) {
            $month = \DateTime::createFromFormat('!Y-m', $date);
            if ($month === false) {
                throw new \InvalidArgumentException("date {$date} invalid");
            }
            $monthlyColumnHeadingForDate[$date] = $month->format('M-Y');
        }

        return $monthlyColumnHeadingForDate[$date];
    }

    protected function checkItemNameAndIdentifiers(): void
    {
        $itemNameElement = $this->getItemNameElement('Item');
        if ($itemNameElement === 'Platform') {
            return;
        }

        $itemName = ($this->get($itemNameElement) ?? '');
        $data = "{$itemNameElement} '{$itemName}'";

        if ($itemNameElement === 'Database' || $itemName !== '') {
            if ($this->get('Item_ID') === null) {
                $message = 'No (valid) identifier present';
                $hint = "at least one identifier should be provided for each {$itemNameElement}";
                $this->addNotice($message, $message, $this->position, $data, $hint);
            }
            return;
        }

        if ($this->get('Item_ID') === null && $this->getInvalid('Item_ID') === null) {
            $message = "Both {$itemNameElement} and {$itemNameElement} identifiers are missing";
            $this->addCriticalError($message, $message, $this->position, $data);
            $this->setUnusable();
        } else {
            if ($this->get($itemNameElement) !== null) {
                $message = "{$itemNameElement} is missing which may affect the audit result";
                $section = ($this->config->getRelease() === '5' ? '3.3.10' : '3.3.9');
                $hint = "please see Section {$section} of the Code of Practice for details";
                $this->addWarning($message, $message, $this->position, $data, $hint);
            }
            // set an empty item name, so that the item is not rendered unusable by the missing required element
            $this->setData($itemNameElement, '');
        }
    }

    protected function checkPublisher(): void
    {
        if (! in_array('Publisher', $this->reportHeader->getRequiredElements())) {
            return;
        }

        if ($this->get('Publisher') === null) {
            // set an empty publisher, so that the item is not rendered unusable by the missing required element
            $this->setData('Publisher', '');
            return;
        }

        if ($this->get('Publisher') === '') {
            $message = "Publisher is missing which may affect the audit result";
            if ($this->isJson()) {
                $position = $this->position;
            } else {
                $position = ($this->reportHeader->getColumnForColumnHeading('Publisher') . $this->position);
            }
            $data = $this->formatData('Publisher', '');
            $section = ($this->config->getRelease() === '5' ? '3.3.10' : '3.3.9');
            $hint = "please see Section {$section} of the Code of Practice for details";
            $this->addWarning($message, $message, $position, $data, $hint);
        }
    }

    protected function checkMetricTypes(): void
    {
        if (! $this->reportHeader->includesComponentDetails()) {
            return;
        }

        foreach ($this->performance as $metricType => $dateCounts) {
            if (preg_match('/^Unique_Item_/', $metricType)) {
                continue;
            }
            $message = 'When Components are included the ';
            if (preg_match('/^Total_Item_/', $metricType)) {
                $message .= 'Totel_Item';
                $hint = 'if the Item itself was used';
            } else {
                $message .= 'Access Denied';
                $hint = 'if access to the Item itself was denied';
            }
            $message .= ' metrics must be reported at the Component level';
            $hint .= ' for a Component with the same metadata as the Item';
            $data = $this->formatData('Metric_Type', $metricType);
            foreach ($dateCounts as $count) {
                if ($this->isJson()) {
                    $position = "{$this->position}.Performance[{$count['p']}].Instance[{$count['i']}].Metric_Type";
                } else {
                    $position = $count['p'];
                }
                $this->addCriticalError($message, $message, $position, $data, $hint);
            }
            $this->setUnusable();

            // TODO: fix this issue by moving the invalid metrics to an Item_Component?
        }
    }

    // TODO: same function in AttributePerformance51
    protected function checkDataTypeSearchesMetrics(): void
    {
        $dataType = $this->get('Data_Type');
        if ($dataType === null || $this->getInvalid('Metric_Type') !== null) {
            return;
        }

        if ($this->isJson()) {
            $position = $this->position;
        } else {
            $position = ($this->reportHeader->getColumnForColumnHeading('Data_Type') . $this->position);
        }
        $data = $this->formatData('Data_Type', $dataType);
        if ($dataType !== 'Platform') {
            if ($this->metricTypeMatch('/^Searches_Platform$/')) {
                $message = "Data_Type must be 'Platform' for Metric_Type 'Searches_Platform'";
                $this->addCriticalError($message, $message, $position, $data);
                $this->setUnusable();
            }
        } else {
            if (
                ! empty($this->performance) &&
                (! $this->metricTypeMatch('/^Searches_Platform$/') || count($this->performance) > 1)
            ) {
                $message = "Data_Type 'Platform' is only applicable for Metric_Type 'Searches_Platform'";
                $this->addCriticalError($message, $message, $position, $data);
                // Some content providers report all usage in PR with Platform. Accept this error for now
                // since the Data_Type is not checked during the audits. TODO: Activate with R5.1.
                // $this->setUnusable();
            }
        }

        $databaseDataTypes = $this->config->getDatabaseDataTypes();
        if (! in_array($dataType, $databaseDataTypes)) {
            foreach (
                [
                    'Searches_Automated',
                    'Searches_Federated',
                    'Searches_Regular'
                ] as $metricType
            ) {
                if ($this->metricTypeMatch("/^{$metricType}$/")) {
                    $message = 'Data_Type must be ' . (count($databaseDataTypes) > 1 ? 'one of ' : '') . "'" .
                        implode("', '", $databaseDataTypes) . "' for Metric_Type '{$metricType}'";
                    $this->addCriticalError($message, $message, $position, $data);
                    $this->setUnusable();
                }
            }
        }
    }

    // TODO: same function in AttributePerformance51
    protected function checkDataTypeUniqueTitleMetrics(): void
    {
        $dataType = $this->get('Data_Type');
        if ($dataType === null) {
            return;
        }

        foreach (array_keys($this->performance) as $metricType) {
            $uniqueTitleDataTypes = $this->config->getUniqueTitleDataTypes();
            if (preg_match('/^Unique_Title_/', $metricType) && ! in_array($dataType, $uniqueTitleDataTypes)) {
                $message = "Metric_Type '{$metricType}' is only applicable for Data_Type" .
                    (count($uniqueTitleDataTypes) > 1 ? 's' : '') . " '" . implode("', '", $uniqueTitleDataTypes) . "'";
                $data = $this->formatData('Data_Type/Metric_Type', "{$dataType}/{$metricType}");
                $this->addCriticalError($message, $message, $this->position, $data);
                $this->setUnusable();
            }
        }
    }

    protected function checkSectionTypeDataType(): void
    {
        // there is no explicit rule which Data_Type/Section_Type combinations are allowed,
        // but this can be concluded from the permitted Host_Types
        static $serial = [
            'Journal',
            'Newspaper_or_Newsletter'
        ];
        static $book = [
            'Book'
        ];

        $sectionType = $this->get('Section_Type');
        $dataType = $this->get('Data_Type');
        if ($sectionType === null || $dataType === null) {
            return;
        }

        switch ($sectionType) {
            case 'Article':
                // Aggregated_Full_Content, eJournal
                $isInvalid = ! in_array($dataType, $serial);
                break;
            case 'Book':
            case 'Chapter':
                // Aggregated_Full_Content, eBook, eBook_Collection
                $isInvalid = ! in_array($dataType, $book);
                break;
            case 'Other':
                // Aggregated_Full_Content
                $isInvalid = (in_array($dataType, $serial) || in_array($dataType, $book));
                break;
            case 'Section':
                // Aggregated_Full_Content, eBook, eBook_Collection, eJournal
                $isInvalid = ! (in_array($dataType, $serial) || in_array($dataType, $book));
                break;
            default:
                throw new ConfigException("Section_Type '{$sectionType}' unkown");
                break;
        }

        if ($isInvalid) {
            $summary = 'Combination of Data_Type and Section_Type is invalid';
            $message = "Combination of Data_Type '{$dataType}' and Section_Type '{$sectionType}' is invalid";
            $data = $this->formatData('Data_Type/Section_Type', "{$dataType}/{$sectionType}");
            $this->addWarning($summary, $message, $this->position, $data);
        }
    }

    // TODO: Postpone check until ReportItems have been morged to avoid wrong error messages about missing
    // Metric_Types when they are split across multiple ReportItems?
    protected function checkSectionTypeMetricType(): void
    {
        if (! in_array('Section_Type', $this->reportHeader->getOptionalElements())) {
            return;
        }

        $sectionType = $this->get('Section_Type') ?? ($this->getInvalid('Section_Type') ?? null);
        foreach (array_keys($this->performance) as $metricType) {
            if (preg_match('/^Unique_Title_/', $metricType)) {
                if ($sectionType !== null) {
                    $message = 'Section_Type ' . ($this->isJson() ? 'is not permitted' : 'must be empty') .
                        ' for Unique_Title metric';
                    $data = $this->formatData('Section_Type/Metric_Type', "{$sectionType}/{$metricType}");
                    $this->addCriticalError($message, $message, $this->position, $data);
                    $this->setUnusable();
                }
            } else {
                if ($sectionType === null) {
                    $message = 'Section_Type is missing for ' .
                        (preg_match('/_Item_/', $metricType) ? 'Total/Unique_Item' : 'Access Denied') . ' metric';
                    $data = $this->formatData('Metric_Type', $metricType);
                    $this->addCriticalError($message, $message, $this->position, $data);
                    $this->setUnusable();
                }
            }
        }
    }

    protected function checkFormatMetricType(): void
    {
        if (! in_array('Format', $this->reportHeader->getOptionalElements())) {
            return;
        }

        $format = $this->get('Format') ?? ($this->getInvalid('Format') ?? null);
        foreach (array_keys($this->performance) as $metricType) {
            if ($metricType === 'Total_Item_Requests') {
                if ($format === null) {
                    $message = "Format is missing for Metric_Type 'Total_Item_Requests'";
                    $data = $this->formatData('Metric_Type', $metricType);
                    $this->addCriticalError($message, $message, $this->position, $data);
                    $this->setUnusable();
                }
            } else {
                if ($format !== null) {
                    $message = 'Format ' .
                        ($this->isJson() ? 'is only permitted for Metric_Type' : 'must be empty for other Metric_Types than') .
                        "'Total_Item_Requests'";
                    $data = $this->formatData('Format/Metric_Type', "{$format}/{$metricType}");
                    $this->addCriticalError($message, $message, $this->position, $data);
                    $this->setUnusable();
                }
            }
        }
    }

    protected function checkRequiredElements(): void
    {
        foreach ($this->reportHeader->getJsonItemRequiredElements() as $elementName) {
            if ($this->get($elementName) === null) {
                $this->setUnusable();
                return;
            }
        }
    }

    public function getBaseHash(): string
    {
        if (! isset($this->hashes['base'])) {
            $this->hashes['base'] = $this->computeHash(true, true);
        }

        return $this->hashes['base'];
    }

    public function getNoFormatHash(): string
    {
        if (! isset($this->hashes['noformat'])) {
            $this->hashes['noformat'] = $this->computeHash(true);
        }

        return $this->hashes['noformat'];
    }

    public function getFullHash(): string
    {
        if (! isset($this->hashes['full'])) {
            $this->hashes['full'] = $this->computeHash();
        }

        return $this->hashes['full'];
    }

    protected function computeHash(bool $ignoreFormat = false, bool $ignoreSectionType = false): string
    {
        $hashContext = hash_init('sha256');
        $elements = array_merge($this->getJsonMetadata(), $this->getJsonAttributes());
        $this->updateHash($hashContext, $elements, $ignoreFormat, $ignoreSectionType);
        return hash_final($hashContext);
    }

    protected function updateHash(
        object $hashContext,
        array $elements,
        bool $ignoreFormat,
        bool $ignoreSectionType,
        ?string $position = null
    ) {
        ksort($elements);
        foreach ($elements as $element => $value) {
            $element = (string) $element;
            if (($ignoreFormat && $element === 'Format') || ($ignoreSectionType && $element === 'Section_Type')) {
                continue;
            }
            if (is_object($value)) {
                $value = $value->getData();
            }
            if (is_array($value)) {
                $this->updateHash(
                    $hashContext,
                    $value,
                    $ignoreFormat,
                    $ignoreSectionType,
                    ($position === null ? $element : $position . '.' . $element)
                );
            } else {
                $string = ($position === null ? $element : $position . '.' . $element) . ' => ' . $value;
                hash_update($hashContext, mb_strtolower($string));
            }
        }
    }

    public function merge(ReportItem $reportItem): void
    {
        $itemNameElement = $this->getItemNameElement('Item');
        $itemName = $this->data[$itemNameElement]; // direct access to data instead of get() to avoid parsing loop
        $data = "{$itemNameElement} '{$itemName}' (first occurrence " . ($this->isJson() ? 'at' : 'in row') .
            " {$this->position})";

        if ($this->isJson()) {
            $message = 'Multiple Report_Items for the same Item and Report Attributes';
            $hint = 'it is recommended to include all Periods and Metric_Types in a single Report_Item ' .
                'to reduce the size of the report and to make it easier to use the report';
            $this->addNotice($message, $message, $reportItem->position, $data, $hint);
        }

        // check if parents are consistent, direct access to data instead of get() to avoid parsing loop
        if (isset($this->data['Item_Parent'])) {
            if (! isset($reportItem->data['Item_Parent'])) {
                $message = 'Inconsistent Item_Parents for Report_Item, Report_Item previously occured with an Item_Parent, ignoring all but the first occurrence';
                $this->addCriticalError($message, $message, $reportItem->position, $data);
            } elseif ($this->data['Item_Parent'] !== $reportItem->data['Item_Parent']) {
                $message = 'Inconsistent Item_Parents for Report_Item, Report_Item previously occured with a different Item_Parent, ignoring all but the first occurrence';
                $this->addCriticalError($message, $message, $reportItem->position, $data);
            }
        } elseif (isset($reportItem->data['Item_Parent'])) {
            $message = 'Inconsistent Item_Parents for Report_Item, Report_Item previously occured without an Item_Parent, ignoring all but the first occurrence';
            $this->addCriticalError($message, $message, $reportItem->position, $data);
        }

        // merge components
        if (isset($this->data['Item_Component']) && isset($reportItem->data['Item_Component'])) {
            $this->data['Item_Component']->merge($reportItem->data['Item_Component']);
        } elseif (isset($reportItem->data['Item_Component'])) {
            $this->data['Item_Component'] = $reportItem->data['Item_Component'];
        }

        // merge performance
        foreach ($reportItem->performance as $metricType => $dateCounts) {
            if (! isset($this->performance[$metricType])) {
                $this->performance[$metricType] = $dateCounts;
            } else {
                foreach ($dateCounts as $date => $count) {
                    if (! isset($this->performance[$metricType][$date])) {
                        $this->performance[$metricType][$date] = $count;
                    } else {
                        $this->mergeMetricTypeDateError($reportItem, $metricType, $date, $count);
                        $reportItem->setUnusable();
                    }
                }
            }
        }

        $this->addMetricTypesPresent($reportItem->getMetricTypesPresent());

        $this->reportItemPositions = array_merge($this->reportItemPositions, $reportItem->reportItemPositions);
    }

    public function getJsonMetadata(): array
    {
        $metadata = [];
        foreach ($this->reportHeader->getJsonItemMetadataElements() as $elementName) {
            $elementValue = $this->get($elementName);
            if ($elementValue !== null) {
                $metadata[$elementName] = $elementValue;
            }
        }

        return $metadata;
    }

    public function printMetadata(): void
    {
        foreach ($this->getJsonMetadata() as $elementName => $elementValue) {
            print("{$elementName}: ");
            if (is_object($elementValue)) {
                print("\n");
                foreach ($elementValue as $idName => $idValue) {
                    print("  {$idName}: [" . implode(', ', $idValue) . "]\n");
                }
            } else {
                print("{$elementValue}\n");
            }
        }
    }

    public function getJsonAttributes(): array
    {
        $attributes = [];
        foreach ($this->reportHeader->getJsonItemAttributeElements() as $elementName) {
            $elementValue = $this->get($elementName);
            if ($elementValue !== null) {
                $attributes[$elementName] = $elementValue;
            }
        }

        return $attributes;
    }
}
