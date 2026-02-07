<?php

/**
 * ReportHeader is the abstract base class for parsing and validating COUNTER Report Headers
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\data;

use ubfr\c5tools\CheckResult;
use ubfr\c5tools\interfaces\CheckedDocument;
use ubfr\c5tools\traits\Checks;
use ubfr\c5tools\traits\Helpers;
use ubfr\c5tools\traits\Parsers;
use ubfr\c5tools\exceptions\UnusableCheckedDocumentException;

abstract class ReportHeader implements CheckedDocument
{
    use \ubfr\c5tools\traits\CheckedDocument;
    use Parsers;
    use Checks;
    use Helpers;

    protected ?string $reportId;

    protected array $reportAttributes;

    protected array $reportFilters;

    protected ?array $reportElements;

    protected ?array $requiredElements;

    protected ?array $optionalElements;

    protected ?array $jsonItemMetadataElements;

    protected ?array $jsonItemAttributeElements;

    protected ?array $jsonItemRequiredElements;

    protected ?array $jsonItemOptionalElements;

    protected ?array $jsonParentElements;

    protected ?array $jsonComponentElements;

    protected ?array $reportValues;

    protected ?array $performanceDates;

    protected ?bool $reportHasTitleMetrics;

    protected array $includesException;

    abstract protected function parseDocument(): void;

    abstract public function asCells(): array;

    public function __construct(CheckedDocument $parent, string $position, $document)
    {
        $this->document = $document;
        $this->request = $parent->getRequest();
        $this->checkResult = $parent->getCheckResult();
        $this->config = $parent->getConfig();
        $this->format = $parent->getFormat();
        $this->position = $position;

        $this->reportId = null;
        $this->reportAttributes = [];
        $this->reportFilters = [];
        $this->reportElements = null;
        $this->requiredElements = null;
        $this->optionalElements = null;
        $this->jsonItemMetadataElements = null;
        $this->jsonItemAttributeElements = null;
        $this->jsonItemRequiredElements = null;
        $this->jsonItemOptionalElements = null;
        $this->jsonParentElements = null;
        $this->jsonComponentElements = null;
        $this->reportValues = null;
        $this->performanceDates = null;
        $this->reportHasTitleMetrics = null;
        $this->includesException = [];

        $this->parseDocument();
        $this->checkMatchesRequest();
    }

    public function getReportId(): ?string
    {
        return $this->reportId;
    }

    public function getBeginDate(): ?string
    {
        return ($this->reportFilters['Begin_Date'] ?? null);
    }

    public function getEndDate(): ?string
    {
        return ($this->reportFilters['End_Date'] ?? null);
    }

    public function getAttributesToShow(): array
    {
        return ($this->reportAttributes['Attributes_To_Show'] ?? []);
    }

    public function getGranularity(): string
    {
        $reportAttributes = $this->get('Report_Attributes');
        if (
            $reportAttributes === null ||
            $reportAttributes->get($this->isJson() ? 'Granularity' : 'Exclude_Monthly_Details') === null
        ) {
            return 'Month';
        } else {
            return 'Totals';
        }
    }

    public function excludesMonthlyDetails(): bool
    {
        return (isset($this->reportAttributes['Exclude_Monthly_Details']) &&
            $this->reportAttributes['Exclude_Monthly_Details'] === 'True');
    }

    public function includesException(int $code): bool
    {
        if (! array_key_exists($code, $this->includesException)) {
            $this->includesException[$code] = false;
            if (($exceptionList = $this->get('Exceptions')) !== null) {
                foreach ($exceptionList as $exception) {
                    if ($exception->get('Code') === $code) {
                        $this->includesException[$code] = true;
                        break;
                    }
                }
            }
        }

        return $this->includesException[$code];
    }

    public function includesComponentDetails(): bool
    {
        return (isset($this->reportAttributes['Include_Component_Details']) &&
            $this->reportAttributes['Include_Component_Details'] === 'True' && ! $this->includesException(3063));
    }

    public function includesParentDetails(): bool
    {
        return (isset($this->reportAttributes['Include_Parent_Details']) &&
            $this->reportAttributes['Include_Parent_Details'] === 'True');
    }

    public function getReportElements(): array
    {
        if ($this->reportElements === null) {
            $this->computeReportElements();
        }
        return $this->reportElements;
    }

    public function getRequiredElements(): array
    {
        if ($this->requiredElements === null) {
            $this->computeReportElements();
        }
        return $this->requiredElements;
    }

    public function getOptionalElements(): array
    {
        if ($this->optionalElements === null) {
            $this->computeReportElements();
        }
        return $this->optionalElements;
    }

    public function getJsonItemMetadataElements(): array
    {
        if ($this->jsonItemMetadataElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonItemMetadataElements;
    }

    public function getJsonItemAttributeElements(): array
    {
        if ($this->jsonItemAttributeElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonItemAttributeElements;
    }

    public function getJsonItemRequiredElements(): array
    {
        if ($this->jsonItemRequiredElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonItemRequiredElements;
    }

    public function getJsonItemOptionalElements(): array
    {
        if ($this->jsonItemOptionalElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonItemOptionalElements;
    }

    public function getJsonParentElements(): array
    {
        if ($this->jsonParentElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonParentElements;
    }

    public function getJsonComponentElements(): array
    {
        if ($this->jsonComponentElements === null) {
            $this->computeReportElements();
        }
        return $this->jsonComponentElements;
    }

    public function canComputeReportElementsAndValues(): bool
    {
        if ($this->getCheckResult()->getResult() === CheckResult::CR_FATAL) {
            return false;
        }
        if ($this->get('Report_Attributes') !== null && ! $this->get('Report_Attributes')->isUsable()) {
            return false;
        }
        if ($this->get('Report_Filters') === null || ! $this->get('Report_Filters')->isUsable()) {
            return false;
        }
        return true;
    }

    protected function computeItemElementsForFormat(string $format): array
    {
        $itemElements = $this->config->getItemElements($this->getReportId(), $format, $this->getAttributesToShow());

        if ($this->getReportId() === 'IR') {
            if (! $this->includesParentDetails()) {
                foreach (array_keys($itemElements) as $elementName) {
                    if (strstr($elementName, 'Parent') !== false) {
                        unset($itemElements[$elementName]);
                    }
                }
            }
            if (! $this->includesComponentDetails()) {
                foreach (array_keys($itemElements) as $elementName) {
                    if (strstr($elementName, 'Component') !== false) {
                        unset($itemElements[$elementName]);
                    }
                }
            }
        }

        if ($format === CheckedDocument::FORMAT_TABULAR && ! $this->excludesMonthlyDetails()) {
            $month = \DateTime::createFromFormat('!Y-m-d', $this->getBeginDate());
            while ($month->format('Y-m-t') <= $this->getEndDate()) {
                // TODO: required? flag for monthly for excluding from metadata?
                $itemElements[$month->format('M-Y')] = [
                    'parse' => 'parseMonthlyData',
                    'attribute' => false,
                    'metadata' => false,
                    'required' => true
                ];
                $month->modify('+1 month');
            }
        }

        return $itemElements;
    }

    protected function computeReportElements(): void
    {
        if (! $this->canComputeReportElementsAndValues()) {
            return;
        }

        $reportElements = $this->computeItemElementsForFormat($this->getFormat());
        $this->reportElements = $reportElements;

        $this->requiredElements = [];
        $this->optionalElements = [];
        foreach ($reportElements as $elementName => $elementConfig) {
            if ($elementConfig['required']) {
                // required means that the element must be present (JSON) or not empty (tabular)
                $this->requiredElements[] = $elementName;
            } else {
                // optional means that the element can be omitted (JSON) or empty (tabular)
                $this->optionalElements[] = $elementName;
            }
        }

        // internal format is JSON, therefore the following elements lists have to be for this this format
        if ($this->getFormat() !== CheckedDocument::FORMAT_JSON) {
            $reportElements = $this->computeItemElementsForFormat(CheckedDocument::FORMAT_JSON);
        }
        $this->jsonItemMetadataElements = [];
        $this->jsonItemAttributeElements = [];
        $this->jsonItemRequiredElements = [];
        $this->jsonItemOptionalElements = [];
        foreach ($reportElements as $elementName => $elementConfig) {
            if ($elementConfig['attribute']) {
                $this->jsonItemAttributeElements[] = $elementName;
                if ($this->config->getRelease() === '5') {
                    // for R5 all attributes but Format and Section_Type are required when included in the report
                    if ($elementName !== 'Format' && $elementName !== 'Section_Type') {
                        $this->jsonItemRequiredElements[] = $elementName;
                    } else {
                        $this->jsonItemOptionalElements[] = $elementName;
                    }
                }
            }
            if ($elementConfig['metadata']) {
                $this->jsonItemMetadataElements[] = $elementName;
                if ($elementConfig['required']) {
                    $this->jsonItemRequiredElements[] = $elementName;
                } else {
                    $this->jsonItemOptionalElements[] = $elementName;
                }
            }
        }
        if ($this->config->getRelease() !== '5') {
            $this->jsonItemRequiredElements[] = 'Attribute_Performance';
        }

        $this->jsonParentElements = [];
        $this->jsonComponentElements = [];
        if ($this->config->isItemReport($this->getReportId())) {
            $jsonParentElements = $this->config->getJsonParentElements($this->getReportId());
            if ($this->getReportId() === 'IR_A1' || $this->includesParentDetails()) {
                $this->jsonParentElements = $jsonParentElements;
            } elseif ($this->config->getRelease() !== '5') {
                $this->jsonParentElements = [
                    'Items' => $jsonParentElements['Items']
                ];
            }

            if ($this->includesComponentDetails()) {
                $this->jsonComponentElements = $this->config->getJsonComponentElements($this->getReportId());
            }
        }
    }

    public function getReportValues(): array
    {
        if ($this->reportValues === null) {
            $this->computeReportValues();
        }
        return $this->reportValues;
    }

    protected function computeReportValues(): void
    {
        if (! $this->canComputeReportElementsAndValues()) {
            return;
        }

        $reportValues = $this->reportFilters;
        foreach ($this->config->getReportFilters($this->getReportId(), false) as $filterName => $filterConfig) {
            if (! isset($reportValues[$filterName]) && isset($filterConfig['values'])) {
                $reportValues[$filterName] = $filterConfig['values'];
            }
        }

        // Format is an enumerated element, but not a filter, so isn't included in the getReportFilters result
        if (isset($this->reportElements['Format'])) {
            $reportValues['Format'] = [
                'HTML',
                'PDF',
                'Other'
            ];
        }

        $this->reportValues = $reportValues;
    }

    public function getPerformanceDates(): array
    {
        if ($this->performanceDates === null) {
            $this->computePerformanceDates();
        }
        return $this->performanceDates;
    }

    protected function computePerformanceDates(): void
    {
        if (! $this->canComputeReportElementsAndValues()) {
            return;
        }

        $this->performanceDates = [];
        $month = \DateTime::createFromFormat('!Y-m-d', $this->getBeginDate());
        $granularity = $this->getGranularity();
        while ($month->format('Y-m-t') <= $this->getEndDate()) {
            $this->performanceDates[] = $month->format('Y-m');
            if ($granularity === 'Totals') {
                break;
            }
            $month->modify('+1 month');
        }
    }

    public function reportHasTitleMetrics(): bool
    {
        if ($this->reportHasTitleMetrics === null) {
            $this->computeReportHasTitleMetrics();
        }

        return $this->reportHasTitleMetrics;
    }

    protected function computeReportHasTitleMetrics(): void
    {
        if (! $this->config->isFullReport($this->reportId)) {
            // standard views
            $this->reportHasTitleMetrics = in_array($this->reportId, [
                'PR_P1',
                'TR_B1',
                'TR_B3'
            ]);
        } else {
            // counter reports
            $reportValues = $this->getReportValues();
            $hasUniqueTitleDataTypes = ! empty(
                array_intersect($this->config->getUniqueTitleDataTypes(), $reportValues['Data_Type'])
            );
            $hasUniqueTitleMetrics = ! empty(
                array_intersect(
                    [
                        'Unique_Title_Investigations',
                        'Unique_Title_Requests'
                    ],
                    $reportValues['Metric_Type']
                )
            );
            $this->reportHasTitleMetrics = $hasUniqueTitleDataTypes && $hasUniqueTitleMetrics;
        }
    }

    /**
     * Return the Exception with the lowest code greater or equal to 1000 (if any).
     *
     * @return \ubfr\c5tools\data\Exception|NULL
     */
    public function getMostRelevantException(): ?\ubfr\c5tools\data\Exception
    {
        $exceptionList = $this->get('Exceptions');
        if ($exceptionList === null) {
            return null;
        }
        $mostRelevantException = null;
        foreach ($exceptionList as $exception) {
            if ($exception->get('Code') < 1000) {
                continue;
            }
            if ($mostRelevantException === null || $exception->get('Code') < $mostRelevantException->get('Code')) {
                $mostRelevantException = $exception;
            }
        }
        return $mostRelevantException;
    }

    public function asJson(bool $reportIsFixed = false): \stdClass
    {
        if (! $this->isUsable()) {
            throw new UnusableCheckedDocumentException(get_class($this) . ' is unusable');
        }

        $json = new \stdClass();
        foreach ($this->getData() as $key => $value) {
            if ($key === 'Created_By' && ($reportIsFixed || $this->isFixed())) {
                $json->$key = 'ubfr/c5tools ' . \Composer\InstalledVersions::getVersion('ubfr/c5tools') .
                    ' based on reports by ' . $value;
            } else {
                $json->$key = (is_object($value) ? $value->asJson() : $value);
            }
        }

        return $json;
    }

    protected function parseExceptionList(string $position, string $property, $value): void
    {
        if (! $this->isNonEmptyArray($position, $property, $value)) {
            return;
        }

        $exceptionList = new ExceptionList($this, $position, $value);
        if ($exceptionList->isUsable()) {
            $this->setData($property, $exceptionList);
            if ($exceptionList->isFixed()) {
                $this->setFixed($property, $value);
            }
        } else {
            $this->setInvalid($property, $value);
        }
    }

    protected function parseReportAttributes(string $position, string $property, $value): void
    {
        if ($this->reportId === null || ! in_array($this->reportId, $this->config->getReportIds())) {
            return;
        }

        if (! $this->config->isFullReport($this->reportId)) {
            $message = 'Report_Attributes is not permitted for Standard Views';
            $this->addError($message, $message, $position, $property);
            $this->setFixed($property, $value);
            return;
        }

        if ($this->config->getRelease() === '5') {
            if (! $this->isNonEmptyArray($position, $property, $value)) {
                return;
            }
            $reportAttributeList = new ReportAttributeList($this, $position, $value);
        } else {
            if (! $this->isObject($position, $property, $value)) {
                return;
            }
            if ($this->isEmptyObject($value)) {
                $message = "Empty object for '{$property}' is invalid";
                $data = $this->formatData($property, $value);
                $hint = 'optional elements without a value must be omitted';
                $this->addError($message, $message, $position, $data, $hint);
                $this->setInvalid($property, $value);
                return;
            }
            $reportAttributeList = new ReportAttributeList51($this, $position, $value);
        }
        $this->setData($property, $reportAttributeList);
        if ($reportAttributeList->isUsable()) {
            if ($reportAttributeList->isFixed()) {
                $this->setFixed($property, $value);
            }
            $this->reportAttributes = $reportAttributeList->asArray();
        }
    }

    protected function parseReportFilters(string $position, string $property, $value): void
    {
        if ($this->reportId === null || ! in_array($this->reportId, $this->config->getReportIds())) {
            return;
        }

        if ($this->config->getRelease() === '5') {
            if (! $this->isNonEmptyArray($position, $property, $value, false)) {
                return;
            }
            $reportFilterList = new ReportFilterList($this, $position, $value);
        } else {
            if (! $this->isObject($position, $property, $value)) {
                return;
            }
            $reportFilterList = new ReportFilterList51($this, $position, $value);
        }
        $this->setData($property, $reportFilterList);
        if ($reportFilterList->isUsable()) {
            if ($reportFilterList->isFixed()) {
                $this->setFixed($property, $value);
            }
            $this->reportFilters = $reportFilterList->asArray();
        }
    }

    protected function checkMatchesRequest(): void
    {
        if ($this->request === null || $this->get('Release') === null || $this->reportId === null) {
            return;
        }

        // Release
        if ($this->get('Release') !== $this->request->getRelease()) {
            $release = ($this->get('Release') ?? '(missing)');
            $summary = 'Release does not match request';
            $message = "Release '{$release}' does not match request '" . $this->request->getRelease() . "'";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
            return;
        }

        // Report_ID
        if ($this->reportId !== $this->request->getReportId()) {
            $reportId = ($this->reportId ?? '(missing)');
            $summary = 'Report_ID does not match request';
            $message = "Report_ID '{$reportId}' does not match request '" . $this->request->getReportId() . "'";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
            return;
        }

        $this->checkMatchesCustomerId();
        $this->checkMatchesDates();
        $this->checkPlatformFilter();
        $this->checkMatchesReportFiltersAttributes('Filter');
        $this->checkMatchesReportFiltersAttributes('Attribute');
    }

    protected function checkMatchesCustomerId(): void
    {
        $requestCredentials = $this->request->getCredentials();
        if (! isset($requestCredentials['customer_id'])) {
            return;
        }

        if ($this->isJson() && $this->get('Customer_ID') === $requestCredentials['customer_id']) {
            return;
        }

        $institutionIds = [];
        if ($this->get('Institution_ID') !== null) {
            foreach (($this->get('Institution_ID')->get('Proprietary') ?? []) as $proprietaryInstitutionId) {
                if ($proprietaryInstitutionId === $requestCredentials['customer_id']) {
                    return;
                }
                $institutionId = explode(':', $proprietaryInstitutionId, 2)[1];
                if ($institutionId === $requestCredentials['customer_id']) {
                    return;
                }
                $institutionIds[] = $institutionId;
            }
        }

        $institutionIds = (empty($institutionIds) ? '(none)' : "'" . implode("', '", $institutionIds) . "'");
        if ($this->config->getRelease() === '5') {
            $customerId = ($this->get('Customer_ID') ?? '(missing)');
            $summary = 'Neither Customer_ID nor one of the Proprietary Institution_IDs matches the requested customer_id';
            $message = "Neither Customer_ID '{$customerId}' nor one of the Proprietary Institutions_IDs {$institutionIds} matches '" .
                $requestCredentials['customer_id'] . "'";
        } else {
            $summary = 'None of the Proprietary Institution_IDs matches the requested customer_id';
            $message = "None of the Proprietary Institutions_IDs {$institutionIds} matches '" .
                $requestCredentials['customer_id'] . "'";
        }
        $this->addFatalError($summary, $message);
        $this->setUnusable();
    }

    protected function checkMatchesDates(): void
    {
        $requestFilters = $this->request->getFilters();

        if (
            isset($requestFilters['begin_date']) && $this->getBeginDate() !== null &&
            ! $this->startsWith($requestFilters['begin_date'], $this->getBeginDate())
        ) {
            $summary = 'Begin_Date does not match request';
            $message = "Begin_Date '" . $this->getBeginDate() . "' does not match '" . $requestFilters['begin_date'] .
                "'";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
        }

        if (
            isset($requestFilters['end_date']) && $this->getEndDate() !== null &&
            ! $this->startsWith($requestFilters['end_date'], $this->getEndDate())
        ) {
            $summary = 'End_Date does not match request';
            $message = "End_Date '" . $this->getEndDate() . "' does not match '" . $requestFilters['end_date'] . "'";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
        }
    }

    protected function checkPlatformFilter(): void
    {
        // Elsevier, hebis and ReDI uses the platform parameter as originally intended, which means that the platform
        // parameter must be present in the request, but is not applied as a Platform filter in the report, therefore
        // the platform parameter is ignored in this case
        static $excludePlatforms = [
            'https://api.elsevier.com/sushi/',
            'https://statistik.hebis.de/',
            'https://statistik2.hebis.de/',
            'https://stats.redi-bw.de/',
            'https://sushi.redi-bw.de/'
        ];

        $requestUrl = $this->request->getRequestUrl();
        foreach ($excludePlatforms as $excludePlatform) {
            if ($this->startsWith($excludePlatform, $requestUrl)) {
                return;
            }
        }

        $request = [];
        foreach ($this->request->getFilters() as $name => $values) {
            $name = $this->counterCaps($name);
            if ($name === 'Platform') {
                $request[$name] = explode('|', $values);
                break;
            }
        }

        $report = [];
        $reportFilterList = $this->get("Report_Filters");
        if ($reportFilterList !== null) {
            foreach ($reportFilterList as $name => $values) {
                if ($name === 'Platform') {
                    $report[$name] = $values;
                    break;
                }
            }
        }

        $this->compareRequestReportFiltersAttributes('Filter', $request, $report);
    }

    protected function checkMatchesReportFiltersAttributes($type): void
    {
        // TODO: proper handling of invalid requests (just check valid filters/attributes)
        // TODO: proper handling of unsupported filters (e.g. Data_Type=Database for ACS)
        if (! $this->config->isFullReport($this->reportId)) {
            return;
        }

        $request = [];
        $getMethod = "get{$type}s";
        if ($type === 'Filter') {
            $filtersAttributesConfig = $this->config->getReportFilters($this->getReportId());
        } else {
            $filtersAttributesConfig = $this->config->getReportAttributes($this->getReportId(), $this->format);
        }
        foreach ($this->request->$getMethod() as $name => $values) {
            if ($name !== 'begin_date' && $name !== 'end_date' && $name !== 'platform') {
                $name = $this->counterCaps($name);
                $values = explode('|', $values);
                if (! $this->isDefaultFilterAttribute($name, $values, $filtersAttributesConfig)) {
                    $request[$name] = $values;
                }
            }
        }

        $report = [];
        $reportTypeList = $this->get("Report_{$type}s");
        if ($reportTypeList !== null) {
            foreach ($reportTypeList as $name => $values) {
                if ($name !== 'Begin_Date' && $name !== 'End_Date' && $name !== 'Platform') {
                    $report[$name] = $values;
                }
            }
        }

        $this->compareRequestReportFiltersAttributes($type, $request, $report);
    }

    protected function compareRequestReportFiltersAttributes(string $type, array $request, array $report): void
    {
        $type = strtolower($type);
        foreach (array_diff_key($request, $report) as $name => $values) {
            $values = implode('|', $values);
            $summary = "Requested {$type} '{$name}' missing from report";
            $message = "Requested {$type} '{$name}={$values}' missing from report";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
        }
        foreach (array_diff_key($report, $request) as $name => $values) {
            $values = implode('|', $values);
            $summary = "Unrequested {$type} '{$name}' included in report";
            $message = "Unrequested {$type} '{$name}={$values}' included in report";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
        }
        foreach (array_intersect(array_keys($request), array_keys($report)) as $name) {
            $values = implode('|', array_diff($request[$name], $report[$name]));
            if ($values !== '') {
                $summary = "Requested values for {$type} '{$name}' missing from report";
                $message = "Requested values '{$values}' for {$type} '{$name}' missing from report";
                $this->addFatalError($summary, $message);
                $this->setUnusable();
            }
            $values = implode('|', array_diff($report[$name], $request[$name]));
            if ($values !== '') {
                $summary = "Unrequested values for {$type} '{$name}' included in report";
                $message = "Unrequested values '{$values}' for {$type} '{$name}' included in report";
                $this->addFatalError($summary, $message);
                $this->setUnusable();
            }
        }
    }

    protected function isDefaultFilterAttribute(string $name, array $values, array $filtersAttributesConfig): bool
    {
        if ($name === 'YOP') {
            return (count($values) !== 1 || $values[0] === '0001-9999');
        } else {
            if (! isset($filtersAttributesConfig[$name]['default'])) {
                return false;
            }
            if ($filtersAttributesConfig[$name]['default'] === 'All') {
                $default = $filtersAttributesConfig[$name]['values'];
            } else {
                $default = [
                    $filtersAttributesConfig[$name]['default']
                ];
            }
            return (array_diff($values, $default) === array_diff($default, $values));
        }
    }

    protected function checkGlobalReport(): void
    {
        $globalInstitutionName = 'The World';
        $globalInstitutionId = '0000000000000000';

        $hasGlobalInstitutionName = false;
        $institutionName = $this->get('Institution_Name');
        if ($institutionName === $globalInstitutionName) {
            $hasGlobalInstitutionName = true;
        }

        $hasGlobalInstitutionId = false;
        if (
            ($institutionIdList = $this->get('Institution_ID')) !== null &&
            ($proprietaryIdList = $institutionIdList->get('Proprietary')) !== null
        ) {
            foreach ($proprietaryIdList as $proprietaryId) {
                $institutionId = substr($proprietaryId, strpos($proprietaryId, ':') + 1);
                if ($institutionId === $globalInstitutionId) {
                    $hasGlobalInstitutionId = true;
                    break;
                }
            }
        }

        if ($hasGlobalInstitutionName !== $hasGlobalInstitutionId) {
            if ($hasGlobalInstitutionName) {
                $message = "For global reports to '{$globalInstitutionName}' the Institution_ID must include " .
                    "the proprietary identifier '{$globalInstitutionId}' (with the platform ID as namespace)";
                if ($this->isJson()) {
                    $position = "{$this->position}.Institution_ID";
                } else {
                    $position = $this->config->getTabularHeaderCell('Institution_ID');
                }
                $data = 'Institution_ID';
                $this->addError($message, $message, $position, $data);
                $this->setUnusable();
            } else {
                $message = "For global reports with Institution_ID '{$proprietaryId}' " .
                    "the Institution_Name must be '{$globalInstitutionName}'";
                if ($this->isJson()) {
                    $position = "{$this->position}.Institution_Name";
                } else {
                    $position = $this->config->getTabularHeaderCell('Institution_Name');
                }
                $data = 'Institution_Name';
                $this->addError($message, $message, $position, $data);
                $this->setUnusable();
            }
        }
    }

    public function checkException303x(int $numberOfItems): void
    {
        $exceptionList = $this->get('Exceptions');
        if ($exceptionList === null && $numberOfItems > 0) {
            return;
        }

        $position = ($this->isJson() ? '.Report_Header' : 'B9');
        $data = 'Exceptions';
        if ($exceptionList === null && $numberOfItems === 0) {
            $message = 'Exceptions is missing for report without usage';
            $this->addCriticalError($message, $message, $position, $data);
            $this->setUnusable();
            return;
        }

        $exceptions = [];
        foreach ($exceptionList as $exception) {
            $code = $exception->get('Code');
            if (3030 <= $code && $code <= 3040) {
                $exceptions[$code] = $exception;
            }
        }
        ksort($exceptions);

        if (empty($exceptions) && $numberOfItems === 0) {
            $message = 'A report without usage must include an Exception 3030, 3031, 3032 or 3040';
            $this->addCriticalError($message, $message, $position, $data);
            $this->setUnusable();
            return;
        }

        if (isset($exceptions[3030]) && $numberOfItems > 0) {
            $message = 'Exception 3030 is invalid for a report with usage';
            $position = $exceptions[$code]->getPosition();
            $data = 'Exception 3030';
            $this->addCriticalError($message, $message, $position, $data);
            $this->setUnusable();
            // further checks are skipped to avoid confusing follow up errors
            return;
        }

        $beginDate = $this->getBeginDate();
        $endDate = $this->getEndDate();
        if ($beginDate === null || $endDate === null || $beginDate > $endDate) {
            return;
        }

        $beginYear = (int) substr($beginDate, 0, 4);
        $beginMonth = (int) substr($beginDate, 5, 2);
        $endYear = (int) substr($endDate, 0, 4);
        $endMonth = (int) substr($endDate, 5, 2);
        $numberOfMonths = ($endYear - $beginYear) * 12 + $endMonth - $beginMonth + 1;

        $message = 'Invalid combination of Exceptions';
        $position = $exceptionList->getPosition();
        $data = 'Exceptions ' . implode(', ', array_keys($exceptions));
        if (count($exceptions) > $numberOfMonths) {
            $hint = 'there can only be one of these Exceptions per month included in the report';
            $this->addCriticalError($message, $message, $position, $data, $hint);
            $this->setUnusable();
        } elseif (count($exceptions) === $numberOfMonths && ! isset($exceptions[3040]) && $numberOfItems > 0) {
            $hint = 'there must be at least one month without usage for each Exception 3031/3032';
            $this->addCriticalError($message, $message, $position, $data, $hint);
            $this->setUnusable();
        }

        // TODO: check that there is at least one month without usage at the beginning/end
        // of the reporting period for Excpetion 3031/3032 (after parsing the Report_Items)
    }

    protected function checkException3040(): void
    {
        if (! $this->includesException(3040)) {
            return;
        }

        foreach ($this->get('Exceptions') as $exception) {
            if ($exception->get('Code') === 3040) {
                $message = 'Exception 3040 is only intended for cases not covered by Exceptions 3030, 3031, 3032 and 3020';
                $position = $exception->getPosition();
                $data = 'Exception 3040';
                $appendix = ($this->config->getRelease() === '5' ? 'E' : 'D');
                $hint = "please see Appendix {$appendix} of the Code of Practice for details";
                $this->addWarning($message, $message, $position, $data, $hint);
                break;
            }
        }
    }

    protected function checkException3063(): void
    {
        if (
            isset($this->reportAttributes['Include_Component_Details']) &&
            $this->reportAttributes['Include_Component_Details'] === 'True'
        ) {
            // Exception could be present or not, so there is nothing to check in this case
            return;
        }

        if (! $this->includesException(3063)) {
            return;
        }

        foreach ($this->get('Exceptions') as $exception) {
            if ($exception->get('Code') === 3063) {
                $message = 'Exception 3063 is only applicable when Include_Component_Details is True';
                $position = $exception->getPosition();
                $data = 'Exception 3063';
                $this->addError($message, $message, $position, $data);
                break;
            }
        }
    }

    protected function checkRequiredReportElements(array $requiredElements): void
    {
        if ($this->reportId === null) {
            $message = "Report_ID is " . ($this->getInvalid('Report_ID') !== null ? 'invalid' : 'missing');
            $this->addFatalError($message, $message);
            $this->setUnusable();
        } elseif (preg_match('/^[a-zA-Z0-9]+:/', $this->reportId)) {
            $summary = 'Custom report is not support';
            $message = "Custom report with Report_ID '{$this->reportId}' is not supported";
            $this->addFatalError($summary, $message);
            $this->setUnusable();
        }

        foreach ($requiredElements as $elementName) {
            if ($this->get($elementName) === null) {
                $this->setUnusable();
                break;
            }
        }

        if ($this->get('Report_Filters') !== null && ! $this->get('Report_Filters')->isUsable()) {
            $this->setUnusable();
        }
    }

    protected function checkOptionalReportElements(): void
    {
        if ($this->get('Report_Attributes') !== null && ! $this->get('Report_Attributes')->isUsable()) {
            $this->setUnusable();
        }
        if ($this->get('Exceptions') !== null && ! $this->get('Exceptions')->isUsable()) {
            $this->setUnusable();
        }
    }
}
