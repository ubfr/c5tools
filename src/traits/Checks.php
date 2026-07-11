<?php

/**
 * Checks is a large collection of methods for parsing and/or validating specific JSON and tabular elements
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools\traits;

use ubfr\c5tools\Config;
use ubfr\c5tools\CounterApiResponse;
use ubfr\c5tools\data\ReportHeader;
use ubfr\c5tools\data\StatusInfo;
use ubfr\c5tools\exceptions\ConfigException;
use Nicebooks\Isbn\Internal\RangeService;

trait Checks
{
    protected function checkHttpCode200($method)
    {
        if ($this->document instanceof CounterApiResponse) {
            $httpResponse = $this->document->getHttpResponse();
            $httpCode = $httpResponse->getstatusCode();
            if ($httpCode !== 200) {
                $httpReason = $httpResponse->getReasonPhrase();
                $message = "HTTP status code for {$method} response must be 200";
                $data = $this->formatData('HTTP status code', "{$httpCode} ({$httpReason})");
                $this->addError($message, $message, '.', $data);
            }
        }
    }

    protected function checkByteOrderMark()
    {
        if ($this->document->isCsvTsv() && ! $this->document->hasBOM()) {
            $message = 'File contains no byte order mark (BOM)';
            $hint = 'using a BOM (0xEF 0xBB 0xBF) is recommended to improve automatic encoding detection ' .
                'in spreadsheet applications (even though byte order has no meaning in UTF-8)';
            $this->addWarning($message, $message, 1, null, $hint);
        }
    }

    protected function checkNoByteOrderMark()
    {
        if ($this->document->hasBOM()) {
            $message = 'JSON document contains a byte order mark (BOM)';
            $data = '0xEF 0xBB 0xBF';
            $hint = 'using a BOM is not permitted for JSON since UTF-8 is the default encoding';
            $this->addError($message, $message, '.', $data, $hint);
        }
    }

    protected function isObject(string $position, string $property, $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        $message = "{$property} must be an object, found ";
        if (is_array($value)) {
            $message .= 'an array';
            $value = json_encode($value);
            if (strlen($value) < 500) {
                $data = $this->formatData($property, $value);
            } else {
                $data = '';
            }
        } else {
            $message .= (is_scalar($value) ? 'a scalar' : 'null');
            $data = $this->formatData($property, $value);
        }
        $this->addCriticalError($message, $message, $position, $data);

        return false;
    }

    protected function isNonEmptyArray(string $position, string $property, $value, bool $optional = true): bool
    {
        if (! is_array($value)) {
            $message = "{$property} must be an array";
            $addLevel = ($optional ? 'addError' : 'addCriticalError');
            $this->$addLevel($message, $message, $position, $property);
            $this->setInvalid($property, $value);
            return false;
        }
        if (empty($value)) {
            $message = "{$property} must not be empty";
            $hint = ($optional ? 'optional elements without a value must be omitted' : null);
            $this->addError($message, $message, $position, $property, $hint);
            if ($optional) {
                $this->setFixed($property, $value);
            } else {
                $this->setInvalid($property, $value);
            }
            return false;
        }
        return true;
    }

    protected function isArrayValueObject(string $position, string $property, $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        $type = (is_array($value) ? 'an array' : (is_scalar($value) ? 'a scalar' : 'null'));
        $message = "Array element must be an object, found {$type}";
        $this->addError($message, $message, $position, json_encode($value));
        $this->setInvalid($property, $value);
        return false;
    }

    protected function checkedBoolean(string $position, string $property, $value): ?bool
    {
        if (! is_bool($value)) {
            $message = "{$property} must be a boolean";
            $data = $this->formatData($property, $value);
            $addLevel = 'addCriticalError';
            if (is_string($value)) {
                if (trim(strtolower($value)) === 'true') {
                    $this->setFixed($property, $value);
                    $value = true;
                    $addLevel = 'addError';
                } elseif (trim(strtolower($value)) === 'false') {
                    $this->setFixed($property, $value);
                    $value = false;
                    $addLevel = 'addError';
                } else {
                    $this->setInvalid($property, $value);
                    $value = null;
                }
            } else {
                $this->setInvalid($property, $value);
                $value = null;
            }
            $this->$addLevel($message, $message, $position, $data);
        }
        return $value;
    }

    protected function checkedInteger(string $position, string $property, $value, bool $isRequired = false): ?int
    {
        if (! is_int($value)) {
            $message = "{$property} must be an integer";
            $data = $this->formatData($property, $value);
            if (! is_scalar($value) || ! preg_match('/^[0-9]+$/', $value)) {
                $addLevel = ($isRequired ? 'addCriticalError' : 'addError');
                $this->$addLevel($message, $message, $position, $data);
                $this->setInvalid($property, $value);
                return null;
            }
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $value);
            $value = (int) $value;
        }
        return $value;
    }

    protected function checkedString(string $position, string $property, $value, bool $errorIsCritical = false): ?string
    {
        if (! is_string($value)) {
            $message = "{$property} must be a string";
            $data = $this->formatData($property, $value);
            if (! is_scalar($value)) {
                $addLevel = ($errorIsCritical ? 'addCriticalError' : 'addError');
                $this->$addLevel($message, $message, $position, $data);
                $this->setInvalid($property, $value);
                return null;
            }
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $value);
            $value = (string) $value;
        }
        return $value;
    }

    protected function checkedRequiredString(
        string $position,
        string $property,
        $value,
        bool $errorIsCritical = false,
        bool $whiteSpaceIsError = false
    ) {
        if ($value === null) {
            return null;
        }

        $originalValue = $value;
        $value = $this->checkedString($position, $property, $value, $errorIsCritical);
        if ($value === null) {
            return null;
        }

        if (trim($value) !== $value) {
            $addLevel = ($whiteSpaceIsError ? 'addError' : 'addWarning');
            $summary = "{$property} value includes whitespace";
            $message = "{$property} value '{$value}' includes whitespace";
            $data = $this->formatData($property, $value);
            $this->$addLevel($summary, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = trim($value);
        }
        return $value;
    }

    protected function checkedNonEmptyString(
        string $position,
        string $property,
        $value,
        bool $isRequired,
        bool $errorIsCritical,
        bool $whiteSpaceIsError
    ): ?string {
        $originalValue = $value;
        $value = $this->checkedString($position, $property, $value, $errorIsCritical);
        if ($value === null) {
            return null;
        }

        if (trim($value) !== $value) {
            $addLevel = ($whiteSpaceIsError ? 'addError' : 'addWarning');
            $summary = "{$property} value includes whitespace";
            $message = "{$property} value '{$value}' includes whitespace";
            $data = $this->formatData($property, $value);
            $this->$addLevel($summary, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = trim($value);
        }
        if ($value === '') {
            $addLevel = ($errorIsCritical ? 'addCriticalError' : 'addError');
            $message = "{$property} value must not be empty";
            $data = $this->formatData($property, $value);
            $hint = ($isRequired ? null : "optional elements without a value must be omitted");
            $this->$addLevel($message, $message, $position, $data, $hint);
            if ($isRequired) {
                $this->setInvalid($property, $originalValue);
            } else {
                $this->setFixed($property, $originalValue);
            }
            return null;
        }
        return $value;
    }

    protected function checkedOptionalNonEmptyString(
        string $position,
        string $property,
        $value,
        bool $errorIsCritical = false,
        bool $whiteSpaceIsError = false
    ) {
        return $this->checkedNonEmptyString($position, $property, $value, false, $errorIsCritical, $whiteSpaceIsError);
    }

    protected function checkedRequiredNonEmptyString(
        string $position,
        string $property,
        $value,
        bool $errorIsCritical = false,
        bool $whiteSpaceIsError = false
    ) {
        if ($value === null) {
            return null;
        }

        return $this->checkedNonEmptyString($position, $property, $value, true, $errorIsCritical, $whiteSpaceIsError);
    }

    protected function checkedPipeSeparatedValues(string $position, string $property, $value): ?array
    {
        $originalValue = $value;
        $value = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($value === null) {
            return null;
        }
        $data = $this->formatData($property, $value);

        if (trim($value) !== $value || preg_match('/\s\|/', $value) || preg_match('/\|\s/', $value)) {
            $message = "{$property} value includes invalid whitespace";
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = trim($value);
            $value = preg_replace('/\s*\|\s*/', '|', $value);
        }
        if (preg_match('/^\|/', $value)) {
            $message = "{$property} value starts with pipe character";
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = preg_replace('/^\|+/', '', $value);
        }
        if (preg_match('/\|$/', $value)) {
            $message = "{$property} value ends with pipe character";
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = preg_replace('/\|+$/', '', $value);
        }
        if (preg_match('/\|\|/', $value)) {
            $message = "{$property} value contains consecutive pipe characters";
            $this->addError($message, $message, $position, $data);
            $this->setFixed($property, $originalValue);
            $value = preg_replace('/\|\|+/', '|', $value);
        }

        if ($value === '') {
            $message = "{$property} value contains only pipe characters and whitespace";
            $this->addError($message, $message, $position, $data);
            $this->setInvalid($property, $originalValue);
            return null;
        }

        return explode('|', $value);
    }

    protected function checkedNameValuePairs(string $position, string $property, $value, array $permittedNames): array
    {
        $originalValue = $value;
        $nameValuePairs = [];
        $fixed = false;
        foreach ($this->checkedSemicolonSpaceSeparatedValues($position, $property, $value) as $nameValue) {
            $data = $this->formatData($property . ' name/value pair', $nameValue);
            $parts = explode('=', $nameValue, 2);
            if (count($parts) === 1 || trim($parts[0]) === '') {
                $message = 'Name is missing for name/value pair';
                $hint = "format must be '{name}={value}'";
                $this->addCriticalError($message, $message, $position, $data, $hint);
                continue;
            }
            if (trim($parts[1]) === '') {
                $message = 'Value is missing for name/value pair';
                $hint = "format must be '{name}={value}'";
                $this->addCriticalError($message, $message, $position, $data, $hint);
                continue;
            }
            $name = $parts[0];
            if ($name !== trim($name)) {
                $summary = 'Name includes whitespace';
                $message = "Name '{$name}' includes whitespace";
                $this->addError($summary, $message, $position, $data);
                $name = trim($name);
                $fixed = true;
            }
            if (! in_array($name, $permittedNames)) {
                if (($correctedName = $this->inArrayFuzzy($name, $permittedNames)) !== null) {
                    $summary = 'Spelling of name is wrong';
                    $message = "Spelling of name '{$name}' is wrong";
                    $hint = "must be spelled '{$correctedName}'";
                    $this->addError($summary, $message, $position, $data, $hint);
                    $name = $correctedName;
                    $fixed = true;
                } else {
                    sort($permittedNames);
                    $summary = 'Name is invalid';
                    $message = "Name '{$name}' is invalid";
                    $hint = 'permitted names are ' . $this->getPermittedNamesString($property, $permittedNames);
                    $this->addCriticalError($summary, $message, $position, $data, $hint);
                    continue;
                }
            }
            $value = $parts[1];
            if ($value !== trim($value)) {
                $message = 'Value includes whitespace';
                $this->addError($message, $message, $position, $data);
                $value = trim($value);
                $fixed = true;
            }
            if (isset($nameValuePairs[$name])) {
                $summary = 'Name is specified multiple times';
                $message = "Name '{$name}' is specified multiple times";
                $hint = 'multiple values (if permitted) must be separated by a pipe character';
                $this->addError($summary, $message, $position, $data, $hint);
                $nameValuePairs[$name] .= "|{$value}";
                $fixed = true;
            } else {
                $nameValuePairs[$name] = $value;
            }
        }
        if ($fixed) {
            $this->setFixed($property, $originalValue);
        }

        return $nameValuePairs;
    }

    // TODO: similar method in ReportAttributeList
    protected function getPermittedNamesString(string $property, array $permittedNames): string
    {
        $extensions = [];
        if ($property === 'Report_Filters') {
            foreach ($permittedNames as $index => $permittedName) {
                if (
                    $permittedName === 'Component_Data_Type' ||
                    ($this->config->getRelease() !== '5' && $permittedName === 'Parent_Data_Type')
                ) {
                    unset($permittedNames[$index]);
                    continue;
                }
                if ($this->config->isCommonExtension($this->reportId, $this->getFormat(), $permittedName)) {
                    $extensions[] = $permittedName;
                    unset($permittedNames[$index]);
                }
            }
        }

        $result = "'" . implode("', '", $permittedNames) . "'";
        if (! empty($extensions)) {
            $result .= ' and common extension';
            if (count($extensions) > 1) {
                $result .= 's';
            }
            $result .= " '" . implode("', '", $extensions) . "'";
        }

        return $result;
    }

    protected function checkedSemicolonSpaceSeparatedValues(string $position, string $property, $value): array
    {
        $value = $this->checkedString($position, $property, $value);
        if ($value === null) {
            return [];
        }
        $data = $this->formatData($property, $value);

        if (preg_match('/(;\s*)(;\s*)+/', $value)) {
            $message = "{$property} value contains consecutive semicolon(-space) characters";
            $this->addError($message, $message, $position, $data);
            $value = preg_replace('/(;\s*)(;\s*)+/', '\1', $value);
        }
        if (preg_match('/^\s*;\s*/', $value)) {
            $message = "{$property} value starts with semicolon(-space) characters";
            $this->addError($message, $message, $position, $data);
            $value = preg_replace('/^\s*;\s*/', '', $value);
        }
        if (preg_match('/\s*;\s*$/', $value)) {
            $message = "{$property} value ends with semicolon(-space) characters";
            $this->addError($message, $message, $position, $data);
            $value = preg_replace('/\s*;\s*$/', '', $value);
        }
        if ($value === '') {
            return [];
        }

        $values = [];
        if ($property === 'Exceptions') {
            $parts = $this->explodeSemicolonSpaceExceptions($value);
        } else {
            $parts = explode(';', $value);
        }
        foreach ($parts as $index => $part) {
            if ($index !== 0) {
                if (substr($part, 0, 1) !== ' ') {
                    $summary = "Space after ';' is missing";
                    $message = "Space between ';' and '{$part}' is missing";
                    $this->addError($summary, $message, $position, $data);
                } else {
                    $part = substr($part, 1);
                }
            }
            if (trim($part) !== $part) {
                if (trim($part) !== '') {
                    $summary = "{$property} value includes whitespace";
                    $message = "{$property} value '{$part}' includes whitespace";
                    $this->addError($summary, $message, $position, $data);
                }
                $part = trim($part);
            }
            $values[] = $part;
        }
        return $values;
    }

    protected function explodeSemicolonSpaceExceptions(string $value): array
    {
        $parts = [];
        $exception = null;
        foreach (explode(';', $value) as $part) {
            if ($exception === null) {
                $exception = $part;
            } elseif (preg_match('/^\s*[0-9]+\s*:/', $part)) {
                $parts[] = $exception;
                $exception = $part;
            } else {
                $exception .= ';' . $part;
            }
        }
        if ($exception !== null) {
            $parts[] = $exception;
        }

        return $parts;
    }

    protected function checkedExceptionValues(string $position, string $property, $values): array
    {
        $hint = "format must be '{Code}: {Message}' or '{Code}: {Message} ({Data})'";

        $exceptions = [];
        foreach ($values as $value) {
            $data = $this->formatData($property, $value);

            $matches = [];
            if (preg_match('/^([0-9]+\s*):([^(]+)(?:\((.+)\))?$/', $value, $matches)) {
                if (substr($matches[2], 0, 1) !== ' ') {
                    $summary = "Space after ':' is missing in {$property} value";
                    $message = "Space between ':' and '{$matches[2]}' is missing in {$property} value '{$value}'";
                    $this->addError($summary, $message, $position, $data, $hint);
                } else {
                    $matches[2] = substr($matches[2], 1);
                }
                if (isset($matches[3])) {
                    if (substr($matches[2], - 1) !== ' ') {
                        $summary = "Space before '(' is missing in {$property} value";
                        $message = "Space between '{$matches[2]}' and '(' is missing in {$property} value '{$value}'";
                        $this->addError($summary, $message, $position, $data, $hint);
                    } else {
                        $matches[2] = substr($matches[2], 0, - 1);
                    }
                }
                foreach ($matches as $match) {
                    if (trim($match) !== $match) {
                        $summary = "{$property} value includes additional whitespace";
                        $message = "{$property} value '{$value}' includes additional whitespace";
                        $this->addError($summary, $message, $position, $data, $hint);
                    }
                }

                $exception = [];
                $exception['Code'] = (int) $matches[1];
                $exception['Message'] = trim($matches[2]);
                if (isset($matches[3])) {
                    $exception['Data'] = trim($matches[3]);
                }
                if ($this->config->getRelease() === '5') {
                    if ($exception['Code'] === 0) {
                        $exception['Severity'] = 'Info';
                    } elseif (($exceptionConfig = $this->config->getExceptionForCode($exception['Code'])) !== null) {
                        $exception['Severity'] = (is_array($exceptionConfig['Severity']) ? $exceptionConfig['Severity'][0] : $exceptionConfig['Severity']);
                    } else {
                        $exception['Severity'] = 'Warning';
                    }
                }
                $exceptions[] = (object) $exception;
            } else {
                $summary = "{$property} value is invalid";
                $message = "{$property} value '{$value}' is invalid";
                $this->addError($summary, $message, $position, $data, $hint);
                continue;
            }
        }
        return $exceptions;
    }

    protected function checkedNamespacedValues(
        string $position,
        string $property,
        $values,
        $permittedNamespaces,
        bool $permitProprietary = true
    ): array {
        $typeValueList = [];
        foreach ($values as $value) {
            $typeValue = $this->checkedNamespacedValue(
                $position,
                $property,
                $value,
                $permittedNamespaces,
                $permitProprietary
            );
            if ($typeValue !== null) {
                $typeValueList[] = $typeValue;
            }
        }
        return $typeValueList;
    }

    protected function checkedNamespacedValue(
        string $position,
        string $property,
        $value,
        $permittedNamespaces,
        bool $permitProprietary = true
    ): ?object {
        $originalValue = $value;
        $value = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($value === null) {
            return null;
        }
        $data = $this->formatData($property, $value);

        $parts = explode(':', $value, 2);
        if (count($parts) === 1 || trim($parts[0]) === '') {
            $message = "Namespace is missing for {$property}";
            $hint = "format of {$property} must be {namespace}:{value}";
            $this->addError($message, $message, $position, $data, $hint);
            return null;
        }
        $namespace = trim($parts[0]);
        $value = trim($parts[1]);
        if ($namespace !== $parts[0] || $value !== $parts[1]) {
            $summary = "{$property} value includes whitespace";
            $message = "{$property} value '{$originalValue}' includes whitespace";
            $this->addError($summary, $message, $position, $data);
        }
        if (strtolower($namespace) === 'proprietary' || strtolower($namespace) === 'proprietary_id') {
            $message = 'Namespace Proprietary must be omitted';
            $this->addError($message, $message, $position, $data);
            return $this->checkedNamespacedValue($position, $property, $value, $permittedNamespaces);
        }
        if (! in_array($namespace, $permittedNamespaces)) {
            $correctedNamespace = $this->inArrayFuzzy($namespace, $permittedNamespaces);
            if ($correctedNamespace !== null) {
                $summary = "Spelling of {$property} namespace is wrong";
                $message = "Spelling of {$property} namespace '{$namespace}' is wrong";
                $hint = "must be spelled '{$correctedNamespace}'";
                $this->addError($summary, $message, $position, $data, $hint);
                $this->setFixed($property, $value);
                $namespace = $correctedNamespace;
            } elseif ($permitProprietary) {
                // TODO: check for not permitted standard namespaces, so that they aren't permitted as proprietary
                $value = "{$namespace}:{$value}";
                $namespace = 'Proprietary';
            } else {
                $summary = "{$property} namespace is invalid";
                $message = "{$property} namespace '{$namespace}' is invalid";
                $hint = "permitted namespaces are '" . implode("', '", $permittedNamespaces) . "'";
                $this->addError($summary, $message, $position, $data, $hint);
                return null;
            }
        }

        return (object) [
            'Type' => $namespace,
            'Value' => $value
        ];
    }

    protected function checkedAuthorIdentifier(string $position, string $property, $value)
    {
        static $permittedAuthorIdentifiers = null;

        if ($permittedAuthorIdentifiers === null) {
            $permittedAuthorIdentifiers = $this->config->getIdentifiers('Author', $this->getFormat());
        }

        $authorIdentifier = $this->checkedNamespacedValue(
            $position,
            $property,
            $value,
            array_keys($permittedAuthorIdentifiers),
            false
        );
        if ($authorIdentifier === null) {
            return null;
        }

        if (! isset($permittedAuthorIdentifiers[$authorIdentifier->Type]['check'])) {
            throw new ConfigException("check missing missing for author identifier {$authorIdentifier->Type}");
        }
        $check = $permittedAuthorIdentifiers[$authorIdentifier->Type]['check'];
        $checkedValue = $this->$check($position, $property, $authorIdentifier->Value);
        if ($authorIdentifier->Value !== $checkedValue) {
            $authorIdentifier->Value = $checkedValue;
        }

        return $authorIdentifier;
    }

    protected function checkedUrl(string $position, string $property, $value): ?string
    {
        $originalValue = $value;
        $value = $this->checkedOptionalNonEmptyString($position, $property, $value);
        if ($value === null) {
            return null;
        }
        $url = parse_urL($value);
        if ($url === false || ! isset($url['scheme']) || ! isset($url['host'])) {
            $message = "{$property} must be an URL";
            $data = $this->formatData($property, $value);
            $this->addError($message, $message, $position, $data);
            $this->setInvalid($property, $originalValue);
            return null;
        }

        return $value;
    }

    protected function checkedRegistryUrl(string $position, string $property, $value): ?string
    {
        if ($this instanceof ReportHeader) {
            // the report header is required but empty when the report provider is not compliant
            $value = $this->checkedRequiredString($position, $property, $value, false, true);
            if ($value === '') {
                $message = 'No registry URL provided';
                $data = $this->formatData($property, '');
                $hint = "COUNTER compliant report providers must include their registry URL in the {$property} header";
                $this->addWarning($message, $message, $position, $data, $hint);
                return '';
            }
        } elseif ($this instanceof StatusInfo) {
            // the status is optional and therefore must be omitted if the report provider is not compliant
            $value = $this->checkedOptionalNonEmptyString($position, $property, $value, false, true);
        } else {
            throw new \LogicException('unsupported context');
        }
        if ($value === null) {
            return null;
        }

        $matches = [];
        if (
            preg_match(
                '#^https://registry\.(countermetrics|projectcounter)\.org/(platform|usage-data-host)/' .
                '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#',
                $value,
                $matches
            )
        ) {
            if ($matches[2] === 'usage-data-host') {
                $message = 'Registry URL points to usage data host';
                $data = $this->formatData($property, $value);
                $hint = 'if the report only includes a single platfrom the registry URL must point to the platform entry';
                $this->addWarning($message, $message, $position, $data, $hint);
            }
            return $value;
        }

        if (preg_match('#^https://www\.projectcounter\.org/counter-user/.+$#', $value)) {
            $addLevel = ($this->config->getRelease() === '5' ? 'addWarning' : 'addError');
            $message = 'Registry URL is outdated';
            $data = $this->formatData($property, $value);
            $hint = 'the URL must point to the registry entry at https://registry.countermetrics.org/ (or https://registry.projectcounter.org/)';
            $this->$addLevel($message, $message, $position, $data, $hint);
            return ($this->config->getRelease() === '5' ? $value : null);
        }

        $message = 'Registry URL is invalid';
        $data = $this->formatData($property, $value);
        $hint = 'the registry URL must point to the registry entry at https://registry.countermetrics.org/ (or https://registry.projectcounter.org/)';
        $this->addError($message, $message, $position, $data, $hint);
        return null;
    }

    protected function checkedRfc3339Date(string $position, string $property, $value, bool $isRequired = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $originalValue = $value;
        if ($isRequired) {
            $value = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        } else {
            $value = $this->checkedOptionalNonEmptyString($position, $property, $value, false, true);
        }
        if ($value === null) {
            return null;
        }

        $data = $this->formatData($property, $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.[0-9]{3}[Z+-]/', $value)) {
            // format is correct except for the milliseconds
            $summary = "Format is wrong for {$property}";
            $message = "Format is wrong for {$property} value '{$value}'";
            $hint = 'the date must be in RFC3339 date-time format without milliseconds (yyyy-mm-ddThh:mm:ssZ)';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $originalValue);
            $value = substr($value, 0, 19) . substr($value, 23);
        }
        $datetime = \DateTime::createFromFormat(\DateTime::RFC3339, $value);
        // DateTime accepts lower case T and Z, so we also check the format via preg_match
        if ($datetime === false || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[Z+-]/', $value)) {
            $summary = "Format is wrong for {$property}";
            $message = "Format is wrong for {$property} value '{$value}'";
            $hint = 'the date must be in RFC3339 date-time format (yyyy-mm-ddThh:mm:ssZ)';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setInvalid($property, $originalValue);
            return null;
        }
        if ($datetime !== false) {
            $errors = $datetime->getLastErrors();
            if ($errors !== false) {
                $messages = implode('. ', $errors['errors']) . implode('. ', $errors['warnings']);
                if ($messages !== '') {
                    $summary = "{$property} value is invalid";
                    $message = "{$property} value '{$value}' is invalid: {$messages}";
                    $this->addError($summary, $message, $position, $data);
                    $this->setInvalid($property, $originalValue);
                    return null;
                }
            }
        }

        return $value;
    }

    protected function checkedDate(string $position, string $property, $value, bool $isRequired = true): ?string
    {
        static $expectedFormats = [
            'Begin_Date' => 'Y-m-d',
            'End_Date' => 'Y-m-d',
            'Publication_Date' => 'Y-m-d',
            'First_Month_Available' => 'Y-m',
            'Last_Month_Available' => 'Y-m'
        ];

        $originalValue = $value;
        if ($isRequired) {
            $value = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        } else {
            $value = $this->checkedOptionalNonEmptyString($position, $property, $value, false, true);
        }
        if ($value === null) {
            $this->setInvalid($property, $originalValue);
            return null;
        }

        $data = $this->formatData($property, $originalValue);
        $expectedFormat = ($expectedFormats[$property] ?? 'Y-m-d');
        if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $value)) {
            if ($expectedFormat !== 'Y-m') {
                $summary = "Format is wrong for {$property}";
                $message = "Format is wrong for {$property} value '{$value}'";
                $firstLast = ($property === 'Begin_Date' ? ' first' : ($property === 'End_Date' ? ' last' : ''));
                $hint = "the date must include the{$firstLast} day of the month";
                $this->addError($summary, $message, $position, $data, $hint);
            }
            $format = 'Y-m';
            // TODO: Avoid parsing dates with this format. There seems to be a bug in DateTime which sometimes
            // results in the wrong month for this format, for example 2020-03-<day> when parsing 2019-02.
        } elseif (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value)) {
            if ($expectedFormat !== 'Y-m-d') {
                $summary = "Format is wrong for {$property}";
                $message = "Format is wrong for {$property} value '{$value}'";
                $hint = "the date must not include the day of the month";
                $this->addError($summary, $message, $position, $data, $hint);
            }
            $format = 'Y-m-d';
        } else {
            // try automatic conversion with strtotime
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                $summary = "Format is wrong for {$property}";
                $message = "Format is wrong for {$property} value '{$value}'";
                $format = $expectedFormat;
                $this->setFixed($property, $originalValue);
                $value = date($format, $timestamp);
                // correct dates to far in the future
                $year = substr($value, 0, 4);
                if ($year > date('Y') + 1) {
                    $value = ($year - 100) . substr($value, 4);
                }
                $addLevel = 'addError';
            } else {
                $summary = "{$property} value is invalid";
                $message = "{$property} value '{$value}' is invalid";
                $addLevel = 'addCriticalError';
            }
            $hint = 'the date must be in yyyy-mm' . ($expectedFormat === 'Y-m-d' ? '-dd' : '') . ' format';
            $this->$addLevel($summary, $message, $position, $data, $hint);
            if ($timestamp === false) {
                $this->setInvalid($property, $originalValue);
                return null;
            } else {
                $this->setFixed($property, $originalValue);
                return $value;
            }
        }
        $datetime = \DateTime::createFromFormat("!{$format}", $value);
        if ($datetime === false || $value !== $datetime->format($format)) {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $this->addCriticalError($summary, $message, $position, $data);
            $this->setInvalid($property, $originalValue);
            return null;
        }
        $errors = $datetime->getLastErrors();
        if ($errors !== false) {
            $messages = implode('. ', $errors['errors']) . implode('. ', $errors['warnings']);
            if ($messages !== '') {
                $summary = "{$property} value is invalid";
                $message = "{$property} value '{$value}' is invalid: {$messages}";
                $this->addCriticalError($summary, $message, $position, $data);
                $this->setInvalid($property, $originalValue);
                return null;
            }
        }
        if ($format !== $expectedFormat) {
            $this->setFixed($property, $originalValue);
            if ($expectedFormat === 'Y-m-d') {
                if ($property === 'End_Date') {
                    $value = $datetime->format('Y-m-t');
                } else {
                    $value .= '-01';
                }
            } else {
                $value = substr($value, 0, 7);
            }
        }
        if ($property === 'Begin_Date' && substr($value, - 2) !== '01') {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $hint = 'day must be the first day of the month';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $originalValue);
            $value = $datetime->format("Y-m-01");
        }
        if ($property === 'End_Date' && substr($value, - 2) !== $datetime->format('t')) {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $hint = 'day must be the last day of the month';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $originalValue);
            $value = $datetime->format("Y-m-t");
        }

        return $value;
    }

    protected function checkedPublicationDate(string $position, string $property, $value): ?string
    {
        return $this->checkedDate($position, $property, $value, false);
    }

    protected function checkedPermittedValue(
        string $position,
        string $property,
        $value,
        array $permittedValues,
        bool $isRequired
    ): ?string {
        $originalValue = $value;
        if ($isRequired) {
            $value = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        } else {
            $value = $this->checkedOptionalNonEmptyString($position, $property, $value, false, true);
        }
        if ($value === null) {
            return null;
        }

        if (in_array($value, $permittedValues)) {
            return $value;
        }

        $data = $this->formatData($property, $value);
        $correctedValue = $this->inArrayFuzzy($value, $permittedValues);
        if ($correctedValue !== null) {
            $summary = "Spelling of {$property} value is wrong";
            $message = "Spelling of {$property} value '{$value}' is wrong";
            $hint = "must be spelled '{$correctedValue}'";
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $originalValue);
            return $correctedValue;
        }

        $summary = "{$property} value is invalid";
        $message = "{$property} value '{$value}' is invalid";
        sort($permittedValues);
        if ($property === 'Attributes_To_Show') {
            $hint = "permitted values are " . $this->getValuesString($property, $permittedValues);
        } else {
            $hint = "permitted values are '" . implode("', '", $permittedValues) . "'";
        }
        $this->addCriticalError($summary, $message, $position, $data, $hint);
        $this->setInvalid($property, $originalValue);
        return null;
    }

    protected function checkedIsniIdentifier(string $position, string $property, string $value): ?string
    {
        if (! preg_match('/^[0-9]{4}[ -]?[0-9]{4}[ -]?[0-9]{4}[ -]?[0-9]{3}[0-9X]$/', $value)) {
            $message = 'ISNI value is invalid';
            $data = $this->formatData($property, $value);
            $hint = 'ISNI must consist of 15 digits and a digit or X with optional spaces or hyphens between blocks of four';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedIsilIdentifier(string $position, string $property, string $value): ?string
    {
        if (! preg_match('/^([A-Z]{2}|[a-zA-Z0-9]{1,3,4})-.{1,11}/', $value)) {
            $message = 'ISIL value is invalid';
            $data = $this->formatData($property, $value);
            $hint = 'ISIL must consist of a (non-)country code followed by a hyphen and a unit identifier with upto 11 characters';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedOclcIdentifier(string $position, string $property, string $value): ?string
    {
        if (! preg_match('/^[0-9]+$/', $value)) {
            $message = 'OCLC value is invalid';
            $data = $this->formatData($property, $value);
            $hint = 'must be digits only';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedProprietary(string $position, string $property, string $value, string $checked): ?string
    {
        $message = "Proprietary {$checked} is invalid";
        $data = $this->formatData($property, $value);
        if (preg_match('/^[^:]+:$/', $value)) {
            $hint = "the {$checked} value is missing, only the platform ID is present";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        if (! preg_match('/^[^:]+:.+$/', $value)) {
            $hint = "the {$checked} must include the platform ID of the host that assigned the {$checked} followed by a colon";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        $matches = [];
        if (! preg_match('/^([a-zA-Z0-9+_.\/]+):/', $value, $matches)) {
            $hint = 'the platform ID must only consist of a-z, A-Z, 0-9, underscore, dot and forward slash';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        } elseif (strlen($matches[1]) > 17) {
            $hint = 'the maximum length allowed for the platform ID is 17 characters';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedProprietaryIdentifier(string $position, string $property, string $value): ?string
    {
        return $this->checkedProprietary($position, $property, $value, 'identifier');
    }

    protected function checkedProprietaryInstitutionIdentifier(
        string $position,
        string $property,
        string $value
    ): ?string {
        static $globalInstitutionId = '0000000000000000';

        $proprietaryInstitutionId = $this->checkedProprietary($position, $property, $value, 'identifier');
        if ($proprietaryInstitutionId === null) {
            return null;
        }

        list ($namespace, $institutionId) = explode(':', $proprietaryInstitutionId, 2);
        if ($institutionId !== $globalInstitutionId && $this->fuzzy($institutionId) === $globalInstitutionId) {
            $summary = "Spelling of {$property} value is wrong";
            $message = "Spelling of {$property} value '{$proprietaryInstitutionId}' is wrong";
            $data = $this->formatData($property, $proprietaryInstitutionId);
            $hint = "must be spelled {$namespace}:{$globalInstitutionId}";
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $proprietaryInstitutionId);
            $proprietaryInstitutionId = "{$namespace}:{$globalInstitutionId}";
        }

        return $proprietaryInstitutionId;
    }

    protected function checkedProprietaryValue(string $position, string $property, string $value): ?string
    {
        return $this->checkedProprietary($position, $property, $value, 'value');
    }

    protected function checkedRorIdentifier(string $position, string $property, string $value): ?string
    {
        if (! preg_match('/^0[0-9a-hjkmnp-z]{6}[0-9]{2}$/', $value)) {
            $message = 'ROR value is invalid';
            $data = $this->formatData($property, $value);
            $hint = 'must be leading 0 followed by 6 characters (excluding i,l,o) and a 2-digit checksum';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedOrcidIdentifier(string $position, string $property, string $value): ?string
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]$/', $value)) {
            $message = "{$property} value is invalid";
            $data = $this->formatData($property, $value);
            $hint = "{$property} must consist of 15 digits and a digit or X with hyphens between blocks of four";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        return $value;
    }

    protected function checkedDoiIdentifier(string $position, string $property, string $value): ?string
    {
        $data = $this->formatData($property, $value);
        if (preg_match('/^10\.[1-9][0-9]{2}[0-9.]*\/.+$/', $value)) {
            return $value;
        }

        $matches = [];
        if (preg_match('/\b(10\.[1-9][0-9]{2}[0-9.]*\/.+)$/', $value, $matches)) {
            $message = "Format is wrong for {$property}";
            $hint = "{$property} must only contain a DOI in format {DOI prefix}/{DOI suffix}";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            return $matches[1];
        }

        $message = "{$property} value is invalid";
        $hint = 'must be a DOI in format {DOI prefix}/{DOI suffix}';
        $this->addError($message, $message, $position, $data, $hint);
        $this->setInvalid($property, $value);
        return null;
    }

    protected function formatIsbn13(string $isbn, ?string $isbnNoHyphens = null): ?string
    {
        if ($isbnNoHyphens === null) {
            $isbnNoHyphens = str_replace('-', '', $isbn);
        }

        $rangeInfo = RangeService::getRangeInfo($isbnNoHyphens);
        if ($rangeInfo !== null && $rangeInfo->parts !== null) {
            return implode('-', $rangeInfo->parts);
        }
        return $isbnNoHyphens;
    }

    protected function checkedIsbnIdentifier(string $position, string $property, string $value): ?string
    {
        $data = $this->formatData($property, $value);
        $isbnNoHyphens = str_replace('-', '', $value);
        if (preg_match('/^97[89][0-9]{10}$/', $isbnNoHyphens)) {
            if (strlen($value) !== 17) {
                $message = "Format is wrong for {$property}";
                $hint = (strlen($value) < 17 ? 'hyphens are missing' : 'too many hyphens');
                $this->addError($message, $message, $position, $data, $hint);
                $this->setFixed($property, $value);
                return $this->formatIsbn13($value, $isbnNoHyphens);
            } elseif ($this->formatIsbn13($value, $isbnNoHyphens) !== $value) {
                $message = "Format is wrong for {$property}";
                $hint = 'hyphen positions are wrong';
                $this->addWarning($message, $message, $position, $data, $hint);
                if (substr($value, 3, 1) === '-' && substr($value, 15, 1) === '-') {
                    return $value; // ISBN is formally wrong but might be used this way, so we keep it
                } else {
                    $this->setFixed($property, $value);
                    return $this->formatIsbn13($value, $isbnNoHyphens);
                }
            } else {
                return $value;
            }
        }
        if (preg_match('/^[0-9]{13}$/', $isbnNoHyphens)) {
            $message = "{$property} value is invalid";
            $hint = 'must be an ISBN-13 starting with prefix 978 or 979';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setInvalid($property, $value);
            return null;
        }
        if (preg_match('/^[0-9]{9}[0-9xX]$/', $isbnNoHyphens)) {
            $message = "Format ISBN-10 is wrong for {$property}";
            $hint = 'format must be ISBN-13 with hyphens';
            $this->addError($message, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            return $this->formatIsbn13($this->getIsbn13($value));
        }
        $matches = [];
        if (
            preg_match('/^(97[89][0-9-]{14})\b/', $value, $matches) ||
            preg_match('/^(97[89][0-9]{10})\b/', $value, $matches)
        ) {
            $message = "Format is wrong for {$property}";
            $hint = "{$property} must only contain an ISBN-13 with hyphens";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            if (strlen($matches[1]) !== 17 || substr($matches[1], 3, 1) !== '-' || substr($matches[1], 15, 1) !== '-') {
                return $this->formatIsbn13($matches[1]);
            } else {
                return $matches[1];
            }
        }
        if (
            preg_match('/^([0-9-]{12}[0-9xX])\b/', $value, $matches) ||
            preg_match('/^([0-9]{9}[0-9xX])\b/', $value, $matches)
        ) {
            $message = "Format ISBN-10 is wrong for {$property}";
            $hint = "{$property} must only contain an ISBN-13 with hyphens";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            return $this->formatIsbn13($this->getIsbn13($matches[1]));
        }

        $message = "{$property} value is invalid";
        $hint = 'must be an ISBN-13 with hyphens';
        $this->addError($message, $message, $position, $data, $hint);
        $this->setInvalid($property, $value);
        return null;
    }

    protected function checkedIssnIdentifier(string $position, string $property, string $value): ?string
    {
        $data = $this->formatData($property, $value);
        $matches = [];
        if (preg_match('/^[0-9]{4}-?[0-9]{3}[0-9xX]$/', $value)) {
            $message = "Format is wrong for {$property}";
            if (strlen($value) === 8) {
                $hint = 'hyphen is missing';
                $this->addError($message, $message, $position, $data, $hint);
                $this->setFixed($property, $value);
                $value = substr($value, 0, 4) . '-' . substr($value, 4, 4);
            }
            if (substr($value, - 1, 1) === 'x') {
                $hint = 'x must be in upper case';
                $this->addError($message, $message, $position, $data, $hint);
                $this->setFixed($property, $value);
                $value = strtoupper($value);
            }
            return $value;
        }

        if (preg_match('/\b([0-9]{4})-?([0-9]{3}[0-9xX])\b/', $value, $matches)) {
            $message = "Format is wrong for {$property}";
            $hint = "{$property} must only contain an ISSN in format nnnn-nnn[nX]";
            $this->addError($message, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            return $matches[1] . '-' . strtoupper($matches[2]);
        }

        $message = "{$property} value is invalid";
        $hint = 'must be an ISSN in format nnnn-nnn[nX]';
        $this->addError($message, $message, $position, $data, $hint);
        $this->setInvalid($property, $value);
        return null;
    }

    protected function checkedUriIdentifier(string $position, string $property, string $value): ?string
    {
        $data = $this->formatData($property, $value);
        if (($urlComponents = parse_url($value)) !== false) {
            // URLs are only accepted if they have at least a scheme and a host
            // RFC3986 allows more schemes, but in a report only http(s) and ftp make sense
            if (
                isset($urlComponents['scheme']) && in_array($urlComponents['scheme'], [
                    'http',
                    'https',
                    'ftp'
                ]) && isset($urlComponents['host']) && preg_match('/^([^.]+\.)+[^.]{2,}$/', $urlComponents['host'])
            ) {
                return $value;
            }
        }

        if (preg_match('/^urn:[a-zA-Z0-9][a-zA-Z0-9-]{0,30}[a-zA-Z0-9]:.+/', $value)) {
            // URNs are accepted if they have a correct NID, the NSS isn't checked
            return $value;
        }

        $message = "{$property} value is invalid";
        $hint = 'must be a valid URL or URN in RFC3986 format';
        $this->addError($message, $message, $position, $data, $hint);
        $this->setInvalid($property, $value);
        return null;
    }

    protected function checkedRelease(string $position, string $property, $value): ?string
    {
        if ($value === null) {
            $message = "{$property} is missing, assuming '" . Config::$defaultRelease . "'";
            $this->addWarning($message, $message, $position, null);
            $this->setFixed($property, '');
            return Config::$defaultRelease;
        }

        $release = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($release === null) {
            $message = "{$property} is unusable, assuming '" . Config::$defaultRelease . "'";
            $data = $this->formatData($property, $value);
            $this->addWarning($message, $message, $position, $data);
            $this->setFixed($property, $value);
            return Config::$defaultRelease;
        }

        if (in_array($release, Config::supportedReleases())) {
            return $release;
        }

        $message = "{$property} '{$release}' not supported";
        $data = $this->formatData($property, $release);
        $this->addCriticalError($message, $message, $position, $data);
        $this->setInvalid($property, $release);
        return null;
    }

    protected function checkedReportId(string $position, string $property, $value, ?Config $config = null): ?string
    {
        if ($config === null) {
            $config = $this->getConfig();
        }

        $reportId = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($reportId === null) {
            return null;
        }

        if (in_array($reportId, $config->getReportIds()) || preg_match('/^[a-zA-Z0-9]+:/', $reportId)) {
            // Master Report or Standard View or a Custom Report
            $this->reportId = $reportId;
            return $reportId;
        }

        $data = $this->formatData($property, $reportId);
        $correctedReportId = $this->inArrayFuzzy($reportId, $config->getReportIds());
        if ($correctedReportId !== null) {
            $summary = "Spelling of {$property} value is wrong";
            $message = "Spelling of {$property} value '{$reportId}' is wrong";
            $hint = "must be spelled '{$correctedReportId}'";
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            $this->reportId = $correctedReportId;
            return $correctedReportId;
        }

        $summary = "{$property} value is invalid";
        $message = "{$property} value '{$reportId}' is invalid";
        $this->addCriticalError($message, $message, $position, $data);
        $this->setInvalid($property, $value);
        return null;
    }

    protected function checkedReportName(string $position, string $property, $value, ?Config $config = null): ?string
    {
        if ($config === null) {
            $config = $this->getConfig();
        }

        $reportName = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($reportName !== null && $this->reportId === null) {
            // if Report_ID is missing try to determine it from Report_Name
            $this->reportId = $config->getReportIdForName($reportName);
            if ($this->reportId !== null) {
                $message = "Report_ID determined from {$property}";
                $data = $this->formatData('Report_ID', $this->reportId);
                $this->addNotice($message, $message, $position, $data);
                $this->setData('Report_ID', $this->reportId);
                $this->setFixed('Report_ID', '');
            }
        }
        if ($this->reportId === null || ! in_array($this->reportId, $config->getReportIds())) {
            // no Report_ID or Custom Report, no check possible
            return $reportName;
        }

        $reportNameForId = $config->getReportName($this->reportId);
        if ($reportName === $reportNameForId) {
            return $reportName;
        }

        $data = $this->formatData($property, $value);
        if ($reportName !== null && $this->fuzzy($reportName) === $this->fuzzy($reportNameForId)) {
            $summary = "Spelling of {$property} value is wrong";
            $message = "Spelling of {$property} value '{$reportName}' is wrong";
            $hint = "must be spelled '{$reportNameForId}'";
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $value);
            return $reportNameForId;
        }

        if ($value === null) {
            $message = "{$property} determined from Report_ID";
            $data = $this->formatData($property, $reportNameForId);
            $this->addNotice($message, $message, $position, $data);
        } else {
            $summary = "{$property} value is invalid for Report_ID";
            $message = "{$property} value '{$reportName}' is invalid for Report_ID '{$this->reportId}'";
            $hint = "must be '{$reportNameForId}'";
            $this->addError($summary, $message, $position, $data, $hint);
        }
        $this->setFixed($property, $value);
        return $reportNameForId;
    }

    protected function checkedInstitutionName(string $position, string $property, $value): ?string
    {
        static $globalInstitutionName = 'The World';

        $institutionName = $this->checkedRequiredNonEmptyString($position, $property, $value, true, true);
        if ($institutionName === null) {
            return null;
        }

        if (
            $institutionName !== $globalInstitutionName &&
            $this->fuzzy($institutionName) === $this->fuzzy($globalInstitutionName)
        ) {
            $summary = "Spelling of {$property} value is wrong";
            $message = "Spelling of {$property} value '{$institutionName}' is wrong";
            $data = $this->formatData($property, $institutionName);
            $hint = "must be spelled {$globalInstitutionName}";
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $institutionName);
            $institutionName = $globalInstitutionName;
        }

        return $institutionName;
    }

    protected function checkedRequiredFilteredValue(string $position, string $property, $value): ?string
    {
        $reportValues = $this->reportHeader->getReportValues();
        if (isset($reportValues[$property])) {
            $permittedValues = [
                $reportValues[$property]
            ];
            return $this->checkedPermittedValue($position, $property, $value, $permittedValues, true);
        } else {
            return $this->checkedRequiredNonEmptyString($position, $property, $value);
        }
    }

    protected function checkedEnumeratedValue(string $position, string $property, $value): ?string
    {
        $reportValues = $this->reportHeader->getReportValues();
        if (! isset($reportValues[$property])) {
            throw new ConfigException("value list missing for {$property}");
        }
        $permittedValues = $reportValues[$property];
        if ($this->isJson() && $this->endsWith('_Data_Type', $property)) {
            $property = 'Data_Type';
        }
        return $this->checkedPermittedValue($position, $property, $value, $permittedValues, true);
    }

    protected function checkedDataType(string $position, string $property, $value): ?string
    {
        $value = $this->checkedEnumeratedValue($position, $property, $value);
        if ($value === null) {
            return null;
        }

        if ($value === 'Unspecified') {
            $message = "Using Data_Type 'Unspecified' may affect the audit result";
            $data = $this->formatData($property, $value);
            $section = ($this->config->getRelease() === '5' ? '3.3.10' : '3.3.9');
            $hint = "please see Section {$section} of the Code of Practice for details";
            $this->addWarning($message, $message, $position, $data, $hint);
        }

        // Full_Content_Databases may optionally provide TR, therefore Data_Type Database has to be permitted in TR,
        // but a Notice is added when Database is used in TR so that auditors can easily spot and check this case
        if ($this->reportHeader->getReportId() === 'TR' && $value === 'Database') {
            $message = "Data_Type 'Database' is only permitted for Full_Content_Databases";
            $data = $this->formatData($property, $value);
            $this->addWarning($message, $message, $position, $data);
        }

        return $value;
    }

    protected function checkedParentDataType(string $position, string $property, $value): ?string
    {
        if ($this->isJson()) {
            // TODO: hack for selecting the correct value list, reverted in checkedEnumeratedValue
            $property = "Parent_{$property}";
        }
        return $this->checkedEnumeratedValue($position, $property, $value);
    }

    protected function checkedComponentDataType(string $position, string $property, $value): ?string
    {
        if ($this->isJson()) {
            // TODO: hack for selecting the correct value list, reverted in checkedEnumeratedValue
            $property = "Component_{$property}";
        }
        return $this->checkedEnumeratedValue($position, $property, $value);
    }

    protected function checkedSectionType(string $position, string $property, $value): ?string
    {
        // TODO: trim/whitespace check? return null or ''?
        if ($this->isTabular() && $value === '') {
            // empty Section_Type is valid in tabular reports for Unique_Title metrics, this is checked later
            return null;
        }

        return $this->checkedEnumeratedValue($position, $property, $value);
    }

    protected function checkedYop(string $position, string $property, $value): ?string
    {
        $originalValue = $value;
        $value = $this->checkedRequiredNonEmptyString($position, $property, $value, false, true);
        if ($value === null) {
            return null;
        }

        if (! preg_match('/^[0-9]{1,4}$/', $value) || (int) $value === 0) {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $data = $this->formatData($property, $value);
            $hint = "{$property} must be in the range 0001-9999";
            $this->addCriticalError($summary, $message, $position, $data, $hint);
            $this->setInvalid($property, $originalValue);
            return null;
        }

        if (strlen($value) !== 4) {
            $summary = "Leading zero(s) missing for {$property} value";
            $message = "{$summary} '{$value}'";
            $data = $this->formatData($property, $value);
            $hint = 'format must be yyyy';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setFixed($property, $originalValue);
            $value = sprintf("%04d", $value);
        }

        // TODO: check YOP filter

        return $value;
    }

    protected function checkedArticleVersion(string $position, string $property, $value): ?string
    {
        static $permittedArticleVersions = [
            'AO', // Author's Original
            'SMUR', // Submitted Manuscript Under Review
            'AM', // Accepted Manuscript
            'P', // Proof
            'VoR', // Version of Record
            'CVoR', // Corrected Version of Record
            'EVoR' // Enhanced Version of Record
        ];

        return $this->checkedPermittedValue($position, $property, $value, $permittedArticleVersions, true);
    }

    protected function checkedFormat(string $position, string $property, $value): ?string
    {
        // TODO: trim/whitespace check? return null or ''?
        if ($this->isTabular() && $value === '') {
            // empty Format is valid in tabular reports for all but Total_Item_Requests, this is checked later
            return null;
        }

        return $this->checkedEnumeratedValue($position, $property, $value);
    }

    protected function checkedCountryCode(string $position, string $property, $value): ?string
    {
        $originalValue = $value;
        $value = $this->checkedRequiredNonEmptyString($position, $property, $value);
        if ($value === null) {
            return null;
        }

        if (! preg_match('/^[A-Z]{2}$/', $value)) {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $data = $this->formatData($property, $value);
            $hint = 'must be an ISO 3166-1 alpha-2 code';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setInvalid($property, $originalValue);
            return null;
        }

        return $value;
    }

    protected function checkedSubdivisionCode(string $position, string $property, $value): ?string
    {
        $originalValue = $value;
        $value = $this->checkedRequiredNonEmptyString($position, $property, $value);
        if ($value === null) {
            return null;
        }

        if (! preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $value)) {
            $summary = "{$property} value is invalid";
            $message = "{$property} value '{$value}' is invalid";
            $data = $this->formatData($property, $value);
            $hint = 'must be an ISO 3166-2 code';
            $this->addError($summary, $message, $position, $data, $hint);
            $this->setInvalid($property, $originalValue);
            return null;
        }

        return $value;
    }
}
