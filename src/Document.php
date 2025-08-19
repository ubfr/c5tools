<?php

/**
 * Document is the abstract base class for handling COUNTER files and API responses
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

abstract class Document
{
    use traits\Helpers;

    protected static array $extension2mimetype = [
        'json' => 'application/json',
        'csv' => 'text/csv',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'tsv' => 'text/tab-separated-values',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    protected ?string $filename = null;

    protected ?string $buffer = null;

    protected ?string $mimetype = null;

    protected ?bool $hasBOM = null;

    protected $document = null;

    protected function fromFile(string $filename, string $extension = null): void
    {
        $this->checkFile($filename);
        $this->filename = $filename;

        if ($extension === null) {
            $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
        } else {
            $extension = strtolower($extension);
        }
        if (! isset(self::$extension2mimetype[$extension])) {
            throw new \InvalidArgumentException("file extension {$extension} not supported");
        }
        $this->mimetype = self::$extension2mimetype[$extension];

        if ($extension === 'json') {
            $buffer = file_get_contents($this->filename);
            if ($buffer === false) {
                throw new \InvalidArgumentException("unable to read file {$filename}");
            }
            $this->jsonFromBuffer($buffer);
        } else {
            $this->spreadsheetFromFile($extension);
        }
    }

    protected static function checkFile(string $filename): void
    {
        if (! file_exists($filename)) {
            throw new \InvalidArgumentException("file {$filename} not found");
        }
        if (! is_file($filename)) {
            throw new \InvalidArgumentException("{$filename} is not a file");
        }
        if (! is_readable($filename)) {
            throw new \InvalidArgumentException("file {$filename} is not readable");
        }
        $fileSize = filesize($filename);
        if ($fileSize === false) {
            throw new \InvalidArgumentException("could not determine size of file {$filename}");
        }
        if ($fileSize === 0) {
            throw new \InvalidArgumentException('file is empty');
        }
    }

    protected function jsonFromBuffer(string $buffer): void
    {
        $bom = pack('H*', 'EFBBBF');
        if (substr($buffer, 0, strlen($bom)) == $bom) {
            $this->buffer = substr($buffer, strlen($bom));
            $this->hasBOM = true;
        } else {
            $this->buffer = $buffer;
            $this->hasBOM = false;
        }

        $source = ($this->filename === null ? 'buffer' : 'file');
        if (strlen($this->buffer) === 0) {
            throw new \InvalidArgumentException("{$source} is empty");
        }
        if (mb_detect_encoding($this->buffer, 'UTF-8', true) === false) {
            throw new \InvalidArgumentException("{$source} encoding is not UTF-8");
        }
        if (! $this->isJsonObject() && ! $this->isJsonArray()) {
            throw new \InvalidArgumentException("{$source} is not a JSON object or array");
        }

        $this->document = json_decode($this->buffer);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('error decoding JSON - ' . json_last_error_msg());
        }

        $this->mimetype = self::$extension2mimetype['json'];
    }

    protected function spreadsheetFromFile(string $extension): void
    {
        $this->hasBOM = false;
        if ($this->isCsvTsv()) {
            $finfo = new \finfo();
            $encoding = $finfo->file($this->filename, FILEINFO_MIME_ENCODING);
            if ($encoding !== 'us-ascii' && $encoding !== 'utf-8') {
                throw new \InvalidArgumentException("file encoding {$encoding} is invalid, must be UTF-8");
            }

            $bom = pack('H*', 'EFBBBF');
            if (file_get_contents($this->filename, false, null, 0, 3) === $bom) {
                $this->hasBOM = true;
            }
        }

        $reader = IOFactory::createReaderForFile($this->filename);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $this->document = $reader->load($this->filename);
        if (! $this->spreadsheetHasRelease() && $reader instanceof Csv && $this->isCsvTsv()) {
            // delimiter detection might have failed, try again with explicitly set delimiter
            $reader->setDelimiter($extension === 'csv' ? ',' : "\t");
            $this->document = $reader->load($this->filename);
            if (! $this->spreadsheetHasRelease()) {
                throw new \InvalidArgumentException('Release missing (or unable to detect delimiter)');
            }
        }
    }

    protected function spreadsheetHasRelease(): string
    {
        $releaseLabelCell = $this->document->getActiveSheet()->getCell('A3', false);
        if (
            $releaseLabelCell === null || $releaseLabelCell->getValue() === null ||
            $this->fuzzy($releaseLabelCell->getValue()) !== 'release'
        ) {
            return false;
        }
        return true;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function hasBOM(): bool
    {
        if ($this->hasBOM === null) {
            throw new \LogicException('document not initialized');
        }

        return $this->hasBOM;
    }

    public function isJson(): bool
    {
        if ($this->mimetype === null) {
            throw new \LogicException('document not initialized');
        }

        return ($this->mimetype === self::$extension2mimetype['json']);
    }

    public function isCsvTsv(): bool
    {
        if ($this->mimetype === null) {
            throw new \LogicException('document not initialized');
        }

        return ($this->mimetype === self::$extension2mimetype['csv'] ||
            $this->mimetype === self::$extension2mimetype['tsv']);
    }

    public function getDocument()
    {
        if ($this->document === null) {
            throw new \LogicException('document not initialized');
        }

        return $this->document;
    }

    public function getBuffer(): string
    {
        if (! $this->isJson()) {
            throw new \LogicException('buffer is only available for JSON documents');
        }

        return $this->buffer;
    }

    protected function isJsonObject(): bool
    {
        return preg_match('/^\s*{.*}\s*$/ms', $this->buffer);
    }

    protected function isJsonArray(): bool
    {
        return preg_match('/^\s*\[.*]\s*$/ms', $this->buffer);
    }

    public function isException($document = null): bool
    {
        if (! $this->isJson()) {
            // Exception only exists as JSON
            return false;
        }

        if ($document === null) {
            $document = &$this->document;

            // top level array with at least one Exceptions?
            if (is_array($document)) {
                if (empty($document)) {
                    return false;
                }
                foreach ($document as $element) {
                    if (is_object($element) && $this->isException($element)) {
                        return true;
                    }
                }
                return false;
            }

            // top level exception element?
            if (is_object($document) && $this->hasProperty($document, 'Exception')) {
                return true;
            }
        }

        if (
            is_object($document) &&
            ($this->hasProperty($document, 'Code') || $this->hasProperty($document, 'Number'))
        ) {
            // regular exception with correct (Code) or wrong (Number) element
            return true;
        }

        return false;
    }

    public function isReportWithException(): bool
    {
        if (! $this->isJson()) {
            // only used for detecting MemberList, ReportList and StatusList exceptions which only exist as JSON
            return false;
        }

        if (! is_object($this->document)) {
            return false;
        }
        $reportHeader = $this->getProperty($this->document, 'Report_Header');
        if ($reportHeader === null) {
            return false;
        }
        $exceptions = $this->getProperty($reportHeader, 'Exceptions');
        if ($exceptions === null) {
            return false;
        }

        return $this->isException($exceptions);
    }

    public function isMemberList(): bool
    {
        if (! $this->isJson()) {
            // MemberList only exists as JSON
            return false;
        }

        if (! is_array($this->document)) {
            // single member object is a common error, so this is accepted here
            if (is_object($this->document) && $this->hasProperty($this->document, 'Customer_ID')) {
                return true;
            }
            return false;
        }

        if (empty($this->document)) {
            // empty array is a common error so this is accepted here
            return true;
        }

        foreach ($this->document as $element) {
            if (is_object($element) && $this->hasProperty($element, 'Customer_ID')) {
                // array with at least one object with Customer_ID
                return true;
            }
        }
        return false;
    }

    public function isReportList(): bool
    {
        if (! $this->isJson()) {
            // ReportList only exists as JSON
            return false;
        }

        if (! is_array($this->document)) {
            // single report object would be very unusual, so this isn't accepted
            return false;
        }

        foreach ($this->document as $element) {
            if (is_object($element) && $this->hasProperty($element, 'Report_ID')) {
                // array with at least one object with Report_ID
                return true;
            }
        }
        return false;
    }

    public function isStatusList(): bool
    {
        if (! $this->isJson()) {
            // StatusList only exists as JSON
            return false;
        }

        if (! is_array($this->document)) {
            if (is_object($this->document) && $this->hasProperty($this->document, 'Service_Active')) {
                // single status object is a common error, so this is accepted here
                return true;
            }
            return false;
        }

        if (empty($this->document)) {
            // empty array would be very unusual, so this isn't accepted
            return false;
        }

        foreach ($this->document as $element) {
            if (is_object($element) && $this->hasProperty($element, 'Service_Active')) {
                // array with at least one object with Service_Active
                return true;
            }
        }
        return false;
    }

    public function isReport(): bool
    {
        if (! $this->isJson()) {
            // all non-JSON documents are reports
            return true;
        }

        if (! is_object($this->document)) {
            return false;
        }
        if ($this->isException()) {
            // checks for top level Exception element besides Report_Header
            return false;
        }
        if ($this->hasProperty($this->document, 'Report_Header')) {
            return true;
        }
        return false;
    }
}
