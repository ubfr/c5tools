<?php

/**
 * NameValueList is the abstract base class for handling JSON COUNTER R5 Report Attribute and Filter lists
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\data;

abstract class NameValueList extends KeyValueList implements \Countable, \IteratorAggregate
{
    protected static array $specialTabularFilters = [
        'Begin_Date' => 'Reporting_Period',
        'End_Date' => 'Reporting_Period',
        'Metric_Type' => 'Metric_Types'
    ];

    protected array $namesConfig;

    protected array $singleValueNames;

    public function __construct(
        \ubfr\c5tools\interfaces\CheckedDocument $parent,
        string $position,
        $document,
        string $property,
        array $namesConfig
    ) {
        // format: Name => [ multi => bool, values => [ permitted values ], default => value ]
        $this->namesConfig = $namesConfig;

        $this->singleValueNames = [];
        foreach ($this->namesConfig as $name => $nameConfig) {
            if (! isset($nameConfig['multi']) || ! $nameConfig['multi']) {
                $this->singleValueNames[] = $name;
            }
        }

        parent::__construct(
            $parent,
            $position,
            $document,
            $property,
            'Name',
            'Value',
            array_keys($namesConfig),
            $this->singleValueNames
        );
    }

    public function count(): int
    {
        if (! $this->isParsed()) {
            $this->parseDocument();
        }
        return ($this->isUsable() ? count($this->getData()) : 0);
    }

    public function getIterator(): \ArrayIterator
    {
        if (! $this->isParsed()) {
            $this->parseDocument();
        }
        return new \ArrayIterator($this->isUsable() ? $this->getData() : []);
    }

    protected function parseDocument(): void
    {
        parent::parseDocument();

        foreach ($this->values as $name => $values) {
            $nameConfig = $this->namesConfig[$name];
            $isSingleValued = in_array($name, $this->singleValueNames);
            foreach ($values as $i => $value) {
                $index = $this->indices[$name][$i];
                $this->setIndex($index);
                $position = $this->getHeaderPosition($name, $index);
                if (isset($nameConfig['values']) && ! in_array($value, $nameConfig['values'])) {
                    $data = $this->formatData($name, $value);
                    $correctedValue = $this->inArrayFuzzy($value, $nameConfig['values']);
                    if ($correctedValue !== null) {
                        $summary = "Spelling of {$name} value is wrong";
                        $message = "Spelling of {$name} value '{$value}' is wrong";
                        $hint = "must be spelled '{$correctedValue}'";
                        $this->addError($summary, $message, $position, $data, $hint);
                        $this->setFixed('.', $this->entries[$index]);
                        if (in_array($correctedValue, $this->values[$name])) {
                            $summary = "Value for Name '{$name}' specified multiple times";
                            $message = "Value '{$correctedValue}' for Name '{$name}' specified multiple times";
                            $this->addError($summary, $message, $position, $data);
                            unset($this->values[$name][$i]);
                            unset($this->indices[$name][$i]);
                            continue;
                        } else {
                            $this->values[$name][$i] = $correctedValue;
                            $value = $correctedValue;
                        }
                    } else {
                        $summary = "{$name} value is invalid";
                        $message = "{$name} value '{$value}' is invalid";
                        sort($nameConfig['values']);
                        $hint = 'permitted values are ' . $this->getValuesString($name, $nameConfig['values']);
                        $this->addError($summary, $message, $position, $data, $hint);
                        $this->setInvalid($name, $this->entries[$index]);
                        unset($this->values[$name][$i]);
                        unset($this->indices[$name][$i]);
                        continue;
                    }
                }
                if (count($this->values[$name]) > 1 && $isSingleValued) {
                    $message = "Multiple values specified for Type '{$name}'";
                    $data = $this->formatData('Value', $value);
                    $hint = 'only one value is permitted, ignoring all but the first value';
                    $this->addError($message, $message, $position, $data, $hint);
                    $this->setFixed('.', $this->entries[$index]);
                    unset($this->values[$name][$i]);
                    unset($this->indices[$name][$i]);
                    continue;
                }
                if ($isSingleValued && $this->fuzzy($value) === 'all') {
                    $summary = "Value 'All' must no be used to indicate that all {$name}s are included in the report";
                    $message = "Value '{$value}' must no be used to indicate that all {$name}s are included in the report";
                    $data = $this->formatData($name, $value);
                    $hint = "instead {$name} must be omitted";
                    $this->addError($summary, $message, $position, $data, $hint);
                    $this->setFixed('.', $this->entries[$index]);
                    unset($this->values[$name][$i]);
                    unset($this->indices[$name][$i]);
                    continue;
                }
            }
        }
        $this->setIndex(null);

        $this->checkDefaultValues();
    }

    protected function getHeaderName(string $name): string
    {
        if ($this->isJson() || $this->property !== 'Report_Filters') {
            return $this->property;
        }
        return (self::$specialTabularFilters[$name] ?? 'Report_Filters');
    }

    protected function getHeaderPosition(string $name, ?string $index): string
    {
        if ($this->isJson()) {
            if ($index === null) {
                return $this->position;
            } elseif ($this->config->getRelease() === '5') {
                return "{$this->position}[{$index}].Value";
            } else {
                return "{$this->position}.{$name}[{$index}]";
            }
        } else {
            return $this->config->getTabularHeaderCell($this->getHeaderName($name));
        }
    }

    protected function getValuesString(string $name, array $values): string
    {
        return ("'" . implode("', '", $values) . "'");
    }

    protected function checkDefaultValues(): void
    {
        foreach ($this->values as $name => $values) {
            $nameConfig = $this->namesConfig[$name];
            if (! isset($nameConfig['default'])) {
                continue;
            }
            if ($nameConfig['default'] === 'All') {
                $default = $nameConfig['values'];
            } else {
                $default = [
                    $nameConfig['default']
                ];
            }
            if (array_diff($values, $default) !== array_diff($default, $values)) {
                continue;
            }
            $indices = implode(',', array_unique($this->indices[$name]));
            $position = $this->getHeaderPosition($name, $indices);
            $pipeSeparatedValues = implode('|', $values);
            $summary = "{$name} value is the default and must be omitted";
            $message = "{$name} value '{$pipeSeparatedValues}' is the default and must be omitted";
            $data = $this->formatData($name, $pipeSeparatedValues);
            $this->addError($summary, $message, $position, $data);
            foreach (array_keys($values) as $i) {
                if ($this->config->getRelease() === '5') {
                    $index = $this->indices[$name][$i];
                    $this->setIndex($index);
                    $this->setFixed('.', $this->entries[$index]);
                } else {
                    if (! in_array($name, $this->singleValueNames)) {
                        $this->setIndex($i);
                    }
                    $this->setFixed($name, $this->values[$name][$i]);
                }
                $this->setIndex(null);
                unset($this->values[$name][$i]);
                unset($this->indices[$name][$i]);
            }
        }
    }
}
