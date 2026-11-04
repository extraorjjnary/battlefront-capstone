---
paths:
    - 'app/Services/Reporting/OperationalExportService.php,resources/js/components/administration/OperationalExports.vue'
---

# Components Administration

## Stream operational CSV and bound PDF and print output

Operational CSV retrieves one repository page at a time and exports all authorized filtered matches. PDF and print share the existing 500-record limit and return an explicit export validation error above it, never silently truncate. Preserve stable ID tie-breakers, formula escaping, private headers, monetary strings and report scope.
