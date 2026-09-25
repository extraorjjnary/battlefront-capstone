param(
    [string] $InputDirectory,
    [string] $ManifestPath
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$projectRoot = Split-Path $PSScriptRoot -Parent
if (-not $InputDirectory) {
    $InputDirectory = Join-Path $projectRoot 'storage/app/imports/product_catalog'
}
if (-not $ManifestPath) {
    $ManifestPath = Join-Path $projectRoot 'storage/app/private/product-catalog-images/manifest.json'
}

function Read-ZipXml {
    param([System.IO.Compression.ZipArchive] $Archive, [string] $EntryName)

    $entry = $Archive.GetEntry($EntryName)
    if (-not $entry) {
        throw "Workbook is missing $EntryName."
    }

    $reader = [System.IO.StreamReader]::new($entry.Open())
    try {
        return [xml] $reader.ReadToEnd()
    } finally {
        $reader.Dispose()
    }
}

function Get-ReferenceImage {
    param([string] $Category)

    switch ($Category) {
        { $_ -in @('Graphics Card', 'PC Case', 'Power Supply', 'Cooling Components') } { return 'images/demo-products/graphics-card.png' }
        { $_ -in @('Processor', 'RAM', 'Motherboard', 'DIY Electronics & Microcontrollers') } { return 'images/demo-products/processor.png' }
        { $_ -in @('Storage', 'Cables & Adapters', 'Power Accessories', 'Cleaning & Maintenance Supplies', 'Office & Packaging Supplies', 'Vending & Coin-Op Machine Parts', 'Service') } { return 'images/demo-products/storage.png' }
        default { return 'images/demo-products/peripherals.png' }
    }
}

function Get-CellValue {
    param([System.Xml.XmlNode] $Row, [string] $Column, [System.Xml.XmlNamespaceManager] $Namespaces, [string[]] $SharedStrings)

    $cell = $Row.SelectSingleNode("./x:c[starts-with(@r, '$Column')]", $Namespaces)
    if (-not $cell) {
        return ''
    }

    $value = $cell.SelectSingleNode('./x:v', $Namespaces)
    if ($cell.Attributes['t'] -and $cell.Attributes['t'].Value -eq 's') {
        if (-not $value) {
            return ''
        }
        return $SharedStrings[[int] $value.InnerText]
    }
    if ($value) {
        return $value.InnerText
    }

    $inline = $cell.SelectNodes('./x:is//x:t', $Namespaces)
    return ($inline | ForEach-Object { $_.InnerText }) -join ''
}

$files = @(Get-ChildItem -LiteralPath $InputDirectory -Filter '*.xlsx' -File | Where-Object { $_.Name -notlike '~$*' } | Sort-Object Name)
if ($files.Count -eq 0) {
    throw "No category workbooks found in $InputDirectory."
}

$entries = [System.Collections.Generic.List[object]]::new()
$seenCodes = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
$slugs = @{}

foreach ($file in $files) {
    $archive = [System.IO.Compression.ZipFile]::OpenRead($file.FullName)
    try {
        $worksheets = @($archive.Entries | Where-Object FullName -like 'xl/worksheets/sheet*.xml')
        if ($worksheets.Count -ne 1) {
            throw "$($file.Name) must contain exactly one worksheet."
        }

        $sheet = Read-ZipXml $archive $worksheets[0].FullName
        $namespaces = [System.Xml.XmlNamespaceManager]::new($sheet.NameTable)
        $namespaces.AddNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main')
        $sharedStrings = @()
        if ($archive.GetEntry('xl/sharedStrings.xml')) {
            $shared = Read-ZipXml $archive 'xl/sharedStrings.xml'
            $sharedStrings = @($shared.SelectNodes('//x:si', $namespaces) | ForEach-Object {
                ($_.SelectNodes('.//x:t', $namespaces) | ForEach-Object { $_.InnerText }) -join ''
            })
        }

        $rows = $sheet.SelectNodes('//x:sheetData/x:row', $namespaces)
        $headerRow = 0
        $fileRows = 0
        $fileCategory = $null
        foreach ($row in $rows) {
            $code = Get-CellValue $row 'A' $namespaces $sharedStrings
            $name = Get-CellValue $row 'B' $namespaces $sharedStrings

            if ($code -ceq 'Product Code' -and $name -ceq 'Product Name') {
                $expectedHeaders = @('Product Code', 'Product Name', 'Category', 'Quantity', 'Price (PHP)', 'Price Tier (Tags)', 'Use Case (Tags)', 'Special Traits (Tags)')
                $actualHeaders = @('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' | ForEach-Object { Get-CellValue $row $_ $namespaces $sharedStrings })
                if (($actualHeaders -join '|') -cne ($expectedHeaders -join '|')) {
                    throw "Unexpected headers in $($file.Name)."
                }
                $headerRow = [int] $row.Attributes['r'].Value
                continue
            }

            if ($headerRow -eq 0 -or [int] $row.Attributes['r'].Value -le $headerRow) {
                continue
            }
            if (-not $code -and -not $name) {
                continue
            }

            $rowNumber = [int] $row.Attributes['r'].Value
            $category = Get-CellValue $row 'C' $namespaces $sharedStrings
            $quantity = Get-CellValue $row 'D' $namespaces $sharedStrings
            $price = Get-CellValue $row 'E' $namespaces $sharedStrings
            if ($code -cnotmatch '^[A-Za-z0-9]{1,64}$' -or -not $name -or -not $category -or $price -notmatch '^\d+(\.\d{1,2})?$' -or ($quantity -and $quantity -notmatch '^\d+$')) {
                throw "Invalid product identity or numeric field in $($file.Name) row $rowNumber."
            }
            if (-not $seenCodes.Add($code)) {
                throw "Duplicate product code $code in $($file.Name) row $rowNumber."
            }

            $slug = ([regex]::Replace($category.ToLowerInvariant(), '[^a-z0-9]+', '-')).Trim('-')
            $filenameSlug = ([regex]::Replace($file.BaseName.ToLowerInvariant(), '[^a-z0-9]+', '-')).Trim('-')
            if ($slug -cne $filenameSlug -or ($fileCategory -and $fileCategory -cne $category)) {
                throw "Category mismatch in $($file.Name) row $rowNumber."
            }
            $fileCategory = $category
            if (-not $slug -or ($slugs.ContainsKey($slug) -and $slugs[$slug] -cne $category)) {
                throw "Category folder collision for $category."
            }
            $slugs[$slug] = $category
            $reference = Get-ReferenceImage $category
            if (-not (Test-Path -LiteralPath (Join-Path $projectRoot "public/$reference"))) {
                throw "Missing approved reference $reference."
            }

            $subject = if ($category -eq 'Service') {
                'one enclosure in a restrained diagnostic workbench scene'
            } elseif ($category -eq 'Bundles & Packages') {
                'the actual sold set, arranged naturally as one studio scene'
            } else {
                'one product matching the named category and physical form'
            }
            $prompt = "Premium realistic square studio product photograph. Exact catalog name: `"$name`". Category: $category. Show $subject, centered and clearly visible on a dark black background with restrained Battlefront red accent lighting. Follow the visual style of $reference. Preserve brand or model identity naturally on the physical product only when supported by the name. Do not invent features. No collage, grid, large promotional text, or watermark."
            $importIssues = [string[]] @()
            if (-not $quantity) {
                $importIssues = [string[]] @('missing_quantity')
            }

            $entries.Add([ordered] @{
                product_code = $code
                name = $name
                category = $category
                category_slug = $slug
                source_file = $file.Name
                source_row = $rowNumber
                source_sha256 = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
                quantity = if ($quantity) { $quantity } else { $null }
                import_issues = $importIssues
                price = $price
                price_tier_tags = (Get-CellValue $row 'F' $namespaces $sharedStrings)
                use_case_tags = (Get-CellValue $row 'G' $namespaces $sharedStrings)
                special_traits_tags = (Get-CellValue $row 'H' $namespaces $sharedStrings)
                reference_image = $reference
                prompt = $prompt
                image_path = "products/$slug/$code.webp"
                status = 'pending'
                attempts = 0
                last_error = $null
                image_sha256 = $null
                image_bytes = $null
                image_width = $null
                image_height = $null
            })
            $fileRows++
        }
        if ($headerRow -eq 0 -or $fileRows -eq 0) {
            throw "No product rows found in $($file.Name)."
        }
        Write-Host "$($file.Name): $fileRows rows"
    } finally {
        $archive.Dispose()
    }
}

$manifest = [ordered] @{ version = 1; entries = @($entries.ToArray()) }
if (Test-Path -LiteralPath $ManifestPath) {
    $existing = Get-Content -LiteralPath $ManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $oldByCode = @{}
    foreach ($entry in $existing.entries) {
        $oldByCode[$entry.product_code] = $entry
    }
    if ($existing.version -ne 1 -or $oldByCode.Count -ne $entries.Count) {
        throw 'Existing manifest differs from the workbooks. Review it before rebuilding.'
    }
    foreach ($entry in $entries) {
        $old = $oldByCode[$entry.product_code]
        if (-not $old -or $old.name -cne $entry.name -or $old.category -cne $entry.category -or $old.source_file -cne $entry.source_file -or $old.source_row -ne $entry.source_row -or $old.source_sha256 -cne $entry.source_sha256) {
            throw "Existing manifest differs at product code $($entry.product_code). Review before rebuilding."
        }
    }
    Write-Host "Validated existing manifest: $($entries.Count) rows. Progress preserved."
    exit 0
}

$parent = Split-Path $ManifestPath -Parent
New-Item -ItemType Directory -Path $parent -Force | Out-Null
$temporary = "$ManifestPath.tmp"
try {
    $json = $manifest | ConvertTo-Json -Depth 8
    [System.IO.File]::WriteAllText($temporary, $json, [System.Text.UTF8Encoding]::new($false))
    [System.IO.File]::Move($temporary, $ManifestPath)
} finally {
    if (Test-Path -LiteralPath $temporary) {
        Remove-Item -LiteralPath $temporary
    }
}
Write-Host "Prepared $($entries.Count) product image jobs at $ManifestPath."
