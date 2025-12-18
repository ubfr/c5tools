# Changelog

All notable changes to the ubfr/c5tools are documented in this file, starting with the first version used in the COUNTER Validation Tool (v0.6.0, with support for COUNTER Release 5.1).

Note that the first published version was v0.8.0. The commits for older versions are not included in the GitHub repository.

## [Unreleased]

## 2025-12-18 - v0.8.2

- Changed: Update dependencies to latest versions

## 2025-08-19 - v0.8.1

- Fixed: MIME type detection for file extensions specified in upper or mixed case (#1)

## 2025-08-05 - v0.8.0

- Changed: Replace Sushi with (Counter)Api in file, class, method and variable names

## 2025-08-03 - v0.7.2

- Added: README, CHANGELOG and demo scripts
- Added: Author and homepage information to composer.json

## 2025-07-31 - v0.7.1

- Fixed: Error messages with lists of permitted namespaces
- Added: License, author and copyright information
- Changed: PSR-12 formatting and some code cleanup

## 2025-07-24 - v0.7.0

- Changed: Require PHP 8.2 or newer, update dependencies to latest versions

## 2025-07-22 - v0.6.30

- Fixed: Separator for Metric_Types values in error messages for tabular reports
- Changed: Use \u notation for some UTF-8 characters in error messages.

## 2025-06-27 - v0.6.29

- Added: Docker support with simple REST API (provided by [@beda42](https://www.github.com/beda42))

## 2025-06-22 - v0.6.28

- Added: Missing checks for empty Report_Filters values for Author, Database, Item_ID and Platform
- Added: Missing checks for Report_Filters Country_Code and Subdivision_Code (pattern only)
- Fixed: Permitted Report_Filters, remove Country_Name and Subdivision_Name
- Fixed: R5.1 Report_Filters, replace Item_Contributor with Author (renamed in R5.1)
- Fixed: Error message for elements present but not included in Attribute_To_Show
- Fixed: Remove superseeded R5 JSON elements from R5.1 configuration
- Changed: Sort order for messages without position (always first)

## 2025-05-30 - v0.6.27

- Added: Missing check for permitted Data_Types for No_Licence/Limit_Exceeded in DR

## 2025-05-07 - v0.6.26

- Fixed: Check for permitted Metric_Types for Data_Type Platform

## 2025-05-06 - v0.6.25

- Fixed: Check for empty Performance in JSON reports

## 2025-05-03 - v0.6.24

- Added: Missing check for 303x Exception in JSON report when Report_Items element is missing

## 2025-05-03 - v0.6.23

- Fixed: JSON report status (unusable) when Report_Items element is missing

## 2025-03-01 - v0.6.22

- Added: Missing error message for empty Institution_ID in tabular R5.1 reports

## 2025-02-20 - v0.6.21

- Fixed: Check for Requests for Data_Type Database_AI in tabular reports

## 2025-02-15 - v0.6.20

- Added: Missing check for empty Report_Attributes
- Added: Missing check for empty Institution_ID
- Added: Missing check for 303x Exception in tabular reports without usage
- Changed: Improve error message for missing Exception in reports without usage
- Fixed: Report status (unusable) when Report_Header is unusable

## 2025-02-14 - v0.6.19

- Added: Missing check for permitted Metric_Types for Data_Type Database_AI/Aggregated/Full

## 2025-02-04 - v0.6.18

- Fixed: Visibility of AttributePerformance51::getJsonAttributes() (public)

## 2025-02-02 - v0.6.17

- Fixed: Use Release from request if available or 5.1 as default for Exceptions

## 2025-01-26 - v0.6.16

- Changed: Use 5.1 instead of 5 as default if Release is missing or unusable
- Added: Warning if default Release is used for tabular reports
- Changed: Improve error message for missing required Report_Filters
- Changed: Improve error message for non-empty row 13 (R5)/14 (R5.1) in tabular report
- Changed: Platform filter check (with list of usage data host that use platform as originally inteded)
- Fixed: Remove superseeded R5 JSON elements from R5.1 configuration

## 2024-11-01 - v0.6.15

- Fixed: Error message for Metric_Types values missing from the report header
- Changed: Improve error message for missing column headings in tabular reports

## 2024-10-31 - v0.6.14

- Fixed: Check for customer_id/Proprietary Institution_IDs for R5.1 COUNTER API requests
- Fixed: Platform parameter check for Elsevier usage data host (that uses platform as originally intended)

## 2024-10-31 - v0.6.13

- Added: Missing check for null values for identifiers
- Added: Missing check for empty Report_Attribute/Filters and identifier lists
- Fixed: Configuration for Linking_ISSN

## 2024-10-30 - v0.6.12

- Removed: Some debugging

## 2024-10-30 - v0.6.11

- Fixed: Error message for Items with inconsistent Parents
- Fixed: Check for default values for single-valued Report_Attributes

## 2024-10-29 - v0.6.10

- Changed: List of permitted (Parent_)Data_Types for databases for R5.1.0.1
- Fixed: Error message for unrequested values in Report_Attributes/Filters
- Fixed: Initialization of DateTime objects used for date computations

## 2024-09-18 - v0.6.9

- Removed: Some debugging

## 2024-09-18 - v0.6.8

- Fixed: Check for single-valued Report_Attributes
- Fixed: Handling of Item_ID and YOP when comparing COUNTER API request with response
- Fixed: Handling of default values for Report_Filters when comparing COUNTER API request with response

## 2024-09-17 - v0.6.7

- Added: Warning for Exception 3040 (which often is used for wrong cases)
- Added: Method for checking whether the report includes a specific Exception

## 2024-09-17 - v0.6.6

- Fixed: Error message for missing report header labels in tabular reports
- Fixed: Error message for tabular reports with multiple sheets

## 2024-09-10 - v0.6.5

- Fixed: Release detection for COUNTER API requests
- Fixed: Attributes_To_Show handling for R5.1 COUNTER API requests
- Fixed: Report status (unusable) when wrong Data_Types are present
- Changed: Improve check for Proprietary values

## 2024-09-05 - v0.6.4

- Fixed: Author validation for tabular reports
- Changed: Improve CheckResult::asJson()

## 2024-09-03 - v0.6.3

- Changed: Also accept old COUNTER domain in registry URLs for now

## 2024-08-27 - v0.6.2

- Fixed: Exception handling for R5.1

## 2024-08-25 - v0.6.1

- Fixed: Check for Metric_Types without counts in JSON reports
- Fixed: Check for empty Parent Data_Type in JSON reports
- Changed: Better wording for some error messages for tabular reports

## 2024-08-21 - v0.6.0

- Added: Support for COUNTER Release 5.1
- Added: Checks for HTTP status codes and Exceptions for COUNTER API responses
- Added: Check whether the COUNTER API response matches the request
