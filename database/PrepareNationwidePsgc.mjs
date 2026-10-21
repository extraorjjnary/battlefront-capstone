import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

// Development-time transformation of the pinned PSA-derived files; never runs at checkout.
const input = process.argv[2];
if (!input)
    throw new Error(
        'Pass the directory containing the four pinned source JSON files.',
    );
const hashes = {
    regions: '4b7efc1bc611f7a365027fb2b359c2a4eb36d03e',
    provinces: '41452fd6dff02c71a2f840ad78bd9944c2bd372f',
    cities_municipalities: '250aba0b930310650a0816fffd31a103ffb2bef8',
    barangays: '49f9fa82420187596178ee958855ec7fd4cf4710',
};
const source = {};
for (const [name, hash] of Object.entries(hashes)) {
    const bytes = fs.readFileSync(path.join(input, `${name}.json`));
    const actual = crypto
        .createHash('sha1')
        .update(`blob ${bytes.length}\0`)
        .update(bytes)
        .digest('hex');
    if (actual !== hash)
        throw new Error(`Pinned source hash mismatch: ${name}`);
    source[name] = JSON.parse(bytes);
}
const legacy = JSON.parse(
    fs.readFileSync('resources/data/psgc-delivery-2026-03-31.v1.json'),
);
const aliases = new Map(
    legacy.destinations.map((record) => [record.code, record.destination]),
);
const named = ({ code, name }) => {
    if (typeof code !== 'string' || !/^\d{10}$/.test(code) || !name?.trim())
        throw new Error('Invalid named code');
    return { code, name };
};
const regions = source.regions.map(named);
const provinces = source.provinces.map((record) => ({
    ...named(record),
    region_code: `${record.code.slice(0, 2)}00000000`,
}));
const districts = source.cities_municipalities.filter(
    (record) => record.code.startsWith('13806') && record.code !== '1380600000',
);
const districtCodes = new Set(districts.map((record) => record.code));
const cities = source.cities_municipalities
    .filter((record) => !districtCodes.has(record.code))
    .map((record) => {
        const province = provinces.find(
            (candidate) =>
                candidate.code.slice(0, 5) === record.code.slice(0, 5),
        );
        return {
            ...named(record),
            region_code: `${record.code.slice(0, 2)}00000000`,
            province_code: province?.code ?? null,
            destination: aliases.get(record.code) ?? record.name,
            type: record.name.includes('City') ? 'city' : 'municipality',
            city_class:
                record.code.slice(2, 3) === '3' ||
                (record.code.startsWith('138') && record.code !== '1381701000')
                    ? 'HUC'
                    : null,
        };
    });
const byPrefix = new Map(
    cities.map((record) => [record.code.slice(0, 7), record]),
);
const seen = new Set();
const barangays = {};
for (const record of source.barangays) {
    const district = districts.find(
        (candidate) => candidate.code.slice(0, 7) === record.code.slice(0, 7),
    );
    const city = district
        ? cities.find((candidate) => candidate.code === '1380600000')
        : byPrefix.get(record.code.slice(0, 7));
    if (!city || seen.has(record.code))
        throw new Error(`Orphan or duplicate barangay: ${record.code}`);
    seen.add(record.code);
    (barangays[city.region_code] ??= []).push({
        ...named(record),
        city_code: city.code,
        ...(district ? { district_code: district.code } : {}),
    });
}
if (
    regions.length !== 18 ||
    provinces.length !== 82 ||
    cities.length !== 1642 ||
    seen.size !== 42010
)
    throw new Error('Unexpected nationwide counts');
for (const city of cities) {
    if (
        !regions.some((region) => region.code === city.region_code) ||
        !barangays[city.region_code]?.some(
            (record) => record.city_code === city.code,
        )
    )
        throw new Error(`Invalid city hierarchy: ${city.code}`);
}
const sort = (a, b) =>
    a.name.localeCompare(b.name, 'en', { numeric: true }) ||
    a.code.localeCompare(b.code);
const output = 'resources/data';
const version = '2026-03-31.v2';
const metadata = {
    ...legacy.source,
    revision: '279ffe1918c2fd28206c2153f6c5c2d8280fb9f3',
    git_blob_hashes: hashes,
    publisher: 'Philippine Statistics Authority',
    release: '2026-03-31',
    modifications:
        'Nationwide full-code parent normalization; Manila districts retained as barangay metadata under City of Manila; region-partitioned barangays. Province-less records retain null province.',
};
fs.writeFileSync(
    `${output}/psgc-nationwide-${version}.json`,
    JSON.stringify(
        {
            schema_version: 2,
            version,
            source: metadata,
            counts: {
                regions: 18,
                provinces: 82,
                cities_municipalities: 1642,
                barangays: 42010,
            },
            regions: regions.sort(sort),
            provinces: provinces.sort(sort),
            cities: cities.sort(sort),
            districts: districts.map(named),
        },
        null,
        2,
    ) + '\n',
);
for (const [region, records] of Object.entries(barangays)) {
    fs.writeFileSync(
        `${output}/psgc-barangays-${version}-${region}.json`,
        JSON.stringify({
            schema_version: 2,
            version,
            region_code: region,
            barangays: records.sort(sort),
        }) + '\n',
    );
}
console.log(
    'Prepared 18 regions, 82 provinces, 1642 cities/municipalities and 42010 barangays.',
);
