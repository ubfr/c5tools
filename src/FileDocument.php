<?php

/**
 * FileDocument is used for creating {@see Document}s from files
 *
 * @author Bernd Oberknapp <bo@ub.uni-freiburg.de>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @copyright 2019-2025 Albert-Ludwigs-Universität, Universitätsbibliothek
 */

namespace ubfr\c5tools;

use ubfr\c5tools\exceptions\InvalidFileDocumentException;

class FileDocument extends Document
{
    public function __construct(string $filename, ?string $extension = null)
    {
        try {
            $this->fromFile($filename, $extension);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidFileDocumentException($e->getMessage());
        }
    }
}
