/**
 * Country Selector Component
 * Handles country autocomplete and currency detection
 */

const CountrySelector = {
    selectedCountry: null,
    onSelectCallback: null,

    init(callback) {
        this.onSelectCallback = callback;
        this.render();
        this.bindEvents();
    },

    render() {
        if (document.getElementById('country-selector-overlay')) return;

        const overlay = document.createElement('div');
        overlay.id = 'country-selector-overlay';
        overlay.className = 'country-selector-overlay';
        overlay.innerHTML = `
            <div class="country-selector-card">
                <button type="button" class="close-selector" onclick="CountrySelector.hide()">
                    <img src="https://api.iconify.design/lucide:x.svg?color=white" class="w-5 h-5" alt="">
                </button>
                <h3 class="text-2xl font-bold text-white mb-2">Select Your Country</h3>
                <p class="text-white/40 text-sm">We need this to set your local currency for trading.</p>
                
                <div class="country-search-container">
                    <img src="https://api.iconify.design/lucide:search.svg?color=white" class="search-icon w-5 h-5" alt="">
                    <input type="text" id="country-search-input" class="country-search-input" placeholder="Start typing country name..." autocomplete="off">
                </div>
                
                <div id="country-results" class="country-results hidden"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    },

    bindEvents() {
        const input = document.getElementById('country-search-input');
        const resultsContainer = document.getElementById('country-results');

        input.addEventListener('input', async (e) => {
            const query = e.target.value.trim();
            if (query.length < 1) {
                resultsContainer.classList.add('hidden');
                return;
            }

            try {
                const response = await fetch(`../../backend/api/countries/search.php?q=${encodeURIComponent(query)}`);
                const countries = await response.json();
                this.displayResults(countries);
            } catch (error) {
                console.error("Failed to fetch countries:", error);
            }
        });

        // Close on overlay click (if not mandatory, but requirement says mandatory)
        // For now, only close on selection.
    },

    displayResults(countries) {
        const resultsContainer = document.getElementById('country-results');
        resultsContainer.innerHTML = '';

        if (countries.length === 0) {
            resultsContainer.innerHTML = '<div class="no-results">No countries found</div>';
        } else {
            countries.forEach(country => {
                const item = document.createElement('div');
                item.className = 'country-item';
                item.innerHTML = `
                    <span class="country-name">${country.name}</span>
                    <span class="currency-info">${country.currency_symbol} ${country.currency_code}</span>
                `;
                item.addEventListener('click', () => this.selectCountry(country));
                resultsContainer.appendChild(item);
            });
        }

        resultsContainer.classList.remove('hidden');
    },

    selectCountry(country) {
        this.selectedCountry = country;
        this.hide();

        if (this.onSelectCallback) {
            this.onSelectCallback(country);
        }
    },

    show() {
        const overlay = document.getElementById('country-selector-overlay');
        overlay.classList.add('active');
        document.getElementById('country-search-input').focus();
    },

    hide() {
        document.getElementById('country-selector-overlay').classList.remove('active');
        // Clear search input and results
        const input = document.getElementById('country-search-input');
        const results = document.getElementById('country-results');
        if (input) input.value = '';
        if (results) {
            results.innerHTML = '';
            results.classList.add('hidden');
        }
    }
};

window.CountrySelector = CountrySelector;
