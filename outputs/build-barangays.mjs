import { readFileSync, writeFileSync } from 'node:fs';

const locations = JSON.parse(readFileSync('assets/data/philippines-locations.json', 'utf8'));
const source = JSON.parse(readFileSync('outputs/psgc-source.json', 'utf8'));
const records = JSON.parse(readFileSync('outputs/psgc-barangays.json', 'utf8'));

const regionCodeByName = new Map(source.regions.map((region) => [region.name, region.code.slice(0, 2)]));
const citiesByName = new Map();
for (const city of source.cities) {
    const list = citiesByName.get(city.name) ?? [];
    list.push(city);
    citiesByName.set(city.name, list);
}

function provinceBucket(city) {
    const province = source.provinces.find((item) => city.code.startsWith(item.code.slice(0, 5)));
    if (province) return province.name;
    if (city.code.startsWith('13')) return 'Metro Manila (no province)';
    if (city.code.startsWith('1999')) return 'Special Geographic Area (no province)';
    return 'Independent cities (no province)';
}

const namesByPrefix = new Map();
for (const record of records) {
    const prefix = record.code.slice(0, 7);
    const list = namesByPrefix.get(prefix) ?? [];
    list.push(record.name);
    namesByPrefix.set(prefix, list);
}

const output = {};
const missing = [];
const ambiguous = [];
const empty = [];
let total = 0;

for (const [regionName, provinces] of Object.entries(locations)) {
    const regionCode = regionCodeByName.get(regionName);
    output[regionName] = {};
    for (const [provinceName, cityNames] of Object.entries(provinces)) {
        output[regionName][provinceName] = {};
        for (const cityName of cityNames) {
            const matches = (citiesByName.get(cityName) ?? []).filter((city) => city.code.slice(0, 2) === regionCode && provinceBucket(city) === provinceName);
            if (matches.length !== 1) {
                (matches.length === 0 ? missing : ambiguous).push(`${regionName} / ${provinceName} / ${cityName} (${matches.length})`);
                output[regionName][provinceName][cityName] = [];
                continue;
            }
            const city = matches[0];
            let names = namesByPrefix.get(city.code.slice(0, 7)) ?? [];
            if (names.length === 0 && city.code.endsWith('0000')) {
                const districtPrefix = city.code.slice(0, 5);
                names = records.filter((record) => record.code.startsWith(districtPrefix)).map((record) => record.name);
            }
            names = [...new Set(names)].sort((left, right) => left.localeCompare(right, 'en'));
            if (names.length === 0) empty.push(`${regionName} / ${provinceName} / ${cityName}`);
            output[regionName][provinceName][cityName] = names;
            total += names.length;
        }
    }
}

const assigned = new Set();
for (const provinces of Object.values(output)) {
    for (const cities of Object.values(provinces)) {
        for (const names of Object.values(cities)) {
            for (const name of names) assigned.add(name);
        }
    }
}
const usedCodes = new Set();
for (const [regionName, provinces] of Object.entries(locations)) {
    const regionCode = regionCodeByName.get(regionName);
    for (const [provinceName, cityNames] of Object.entries(provinces)) {
        for (const cityName of cityNames) {
            const city = (citiesByName.get(cityName) ?? []).find((item) => item.code.slice(0, 2) === regionCode && provinceBucket(item) === provinceName);
            if (!city) continue;
            const prefix = (namesByPrefix.get(city.code.slice(0, 7)) ?? []).length === 0 && city.code.endsWith('0000')
                ? city.code.slice(0, 5)
                : city.code.slice(0, 7);
            for (const record of records) {
                if (record.code.startsWith(prefix)) usedCodes.add(record.code);
            }
        }
    }
}
writeFileSync('assets/data/philippines-barangays.json', JSON.stringify(output));
console.log(JSON.stringify({
    total,
    cities: Object.values(output).reduce((sum, provinces) => sum + Object.values(provinces).reduce((inner, cities) => inner + Object.keys(cities).length, 0), 0),
    missing: missing.length,
    ambiguous: ambiguous.length,
    empty: empty.length,
    sourceRecords: records.length,
    assignedRecords: usedCodes.size,
    missingSamples: missing.slice(0, 5),
    ambiguousSamples: ambiguous.slice(0, 5),
    emptySamples: empty.slice(0, 5),
    uniqueNames: assigned.size,
}, null, 2));
