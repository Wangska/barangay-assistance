(() => {
    const locations = JSON.parse(document.getElementById('philippines-locations').textContent);
    const region = document.getElementById('region');
    const province = document.getElementById('province');
    const city = document.getElementById('city_municipality');
    const barangay = document.getElementById('barangay');
    const barangayLabel = document.querySelector('label[for="barangay"]');
    let requestId = 0;

    function populate(select, values, placeholder) {
        const selected = select.dataset.selected || select.value;
        select.replaceChildren(new Option(placeholder, ''));
        values.forEach(value => select.add(new Option(value, value)));
        if (values.includes(selected)) select.value = selected;
        delete select.dataset.selected;
        select.disabled = values.length === 0;
        select.required = values.length > 0;
    }

    function updateBarangays() {
        const current = ++requestId;
        const regionName = region.value;
        const provinceName = province.value;
        const cityName = city.value;
        if (!regionName || !provinceName || !cityName) {
            populate(barangay, [], 'Choose barangay');
            barangayLabel.classList.toggle('required', false);
            return;
        }
        barangay.replaceChildren(new Option('Choose barangay', ''));
        barangay.disabled = true;
        barangay.required = false;
        barangayLabel.classList.remove('required');
        const params = new URLSearchParams({ region: regionName, province: provinceName, city: cityName });
        fetch('api/barangays.php?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then(response => response.ok ? response.json() : [])
            .then(values => {
                if (current !== requestId) return;
                const list = Array.isArray(values) ? values : [];
                populate(barangay, list, 'Choose barangay');
                barangayLabel.classList.toggle('required', list.length > 0);
            })
            .catch(() => {
                if (current !== requestId) return;
                populate(barangay, [], 'Choose barangay');
                barangayLabel.classList.toggle('required', false);
            });
    }

    function updateCities() {
        populate(city, locations[region.value]?.[province.value] ?? [], 'Choose city or municipality');
        updateBarangays();
    }

    region.addEventListener('change', () => {
        populate(province, Object.keys(locations[region.value] ?? {}), 'Choose province or area');
        if (province.options.length === 2) province.selectedIndex = 1;
        updateCities();
    });
    province.addEventListener('change', updateCities);
    city.addEventListener('change', updateBarangays);
    if (city.value) updateBarangays();
})();
