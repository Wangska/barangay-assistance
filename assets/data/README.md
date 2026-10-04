# Philippine registration locations

Source: https://github.com/alkevintan/psgc-rb (data/regions.json, data/provinces.json, data/cities_municipalities.json), retrieved 2026-09-28. The source describes its data as PSA PSGC 1Q 2026. See PSGC-LICENSE.txt.

philippines-locations.json contains 18 regions, 82 provinces, and 1,642 cities/municipalities. It is bundled locally; registration makes no third-party data requests.

Parent relationships use the 10-digit PSGC code (first two digits for region, first five for province). Manila's 14 sub-municipal districts are omitted, retaining City of Manila. Entries without an actual province are grouped as Metro Manila, Independent cities, or BARMM Special Geographic Area, explicitly labeled "no province". These are UI groupings, not additional provinces. Names are saved to the existing profile columns.

To refresh, regenerate this hierarchy from a reviewed PSGC snapshot, retain the license, verify the counts and parent relationships, and run the registration smoke/browser checks.

## Barangays

`philippines-barangays.json` lists all 42,010 PSA barangays under the same region, province, and city or municipality names used in `philippines-locations.json`. Every one of the 1,642 cities has a list. The registration page asks `api/barangays.php` for the selected city only, so the full file is not sent with the form. City of Manila includes the barangays of its 14 districts. Rebuild the file with `node outputs/build-barangays.mjs` after replacing the PSGC snapshots in `outputs/`.
