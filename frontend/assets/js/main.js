// Global Utilities and Session Management
(function () {
    // Helper to detect project root (e.g., /Legacy of Spices)
    // For Vercel deployment, we use a remote backend URL
    window.BACKEND_URL = 'https://legacyofspices-production.up.railway.app';

    window.getProjectRoot = () => {
        const path = window.location.pathname;
        if (path.includes('/frontend/')) {
            return path.substring(0, path.indexOf('/frontend/'));
        }
        return '';
    };

    window.getFrontendPath = (relativePath) => {
        const root = window.getProjectRoot();
        // If we are in local dev with /frontend/ in path
        if (root || window.location.pathname.includes('/frontend/')) {
            return root + '/frontend/' + relativePath;
        }
        // If we are on Vercel (root is frontend)
        return '/' + relativePath;
    };

    const projectRoot = window.getProjectRoot();

    // Inject Loader HTML
    // Inject Loader HTML
    const loaderHTML = `
    <div id="global-loader">
        <div class="spinner"></div>
    </div>`;

    // Append to body when DOM is ready
    if (document.body) {
        document.body.insertAdjacentHTML('beforeend', loaderHTML);
    } else {
        window.addEventListener('DOMContentLoaded', () => {
            document.body.insertAdjacentHTML('beforeend', loaderHTML);
        });
    }

    const getLoader = () => document.getElementById('global-loader');

    window.showLoader = () => {
        const loader = getLoader();
        if (loader) loader.classList.add('visible');
    };

    window.hideLoader = () => {
        const loader = getLoader();
        if (loader) loader.classList.remove('visible');
    };

    // Override fetch to handle loader, session validation, and remote backend routing
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
        let url = args[0] ? args[0].toString() : '';
        let options = args[1] || {};

        // 1. Remote Backend Routing: If url points to backend, prepend BACKEND_URL if set
        if (window.BACKEND_URL && (url.includes('/api/') || url.includes('../../api/'))) {
            // Convert relative paths to absolute using BACKEND_URL
            const apiPath = url.split('/api/')[1];
            url = window.BACKEND_URL + '/backend/api/' + apiPath;
            
            // 2. Add credentials for cross-domain session support
            options.credentials = 'include';
            console.log("Intercepted Fetch ->", url);
            args[0] = url;
            args[1] = options;
        }

        // Don't show loader for background tasks like currency check
        const isBackground = url.includes('get-profile.php') || url.includes('analytics') || url.includes('get-rates.php');

        if (!isBackground) showLoader();

        try {
            const response = await originalFetch(...args);

            // Global Session Validation: If any API returns 401, redirect to login
            if (response.status === 401 && !url.includes('auth/login.php')) {
                const projectRoot = window.getProjectRoot();
                const isProtected = window.location.pathname.includes('/customer/') ||
                    window.location.pathname.includes('/farmer/') ||
                    window.location.pathname.includes('/admin/');

                if (isProtected) {
                    window.location.href = window.getFrontendPath('auth/login.html');
                }
            }

            return response;
        } catch (error) {
            console.error(`Fetch error for ${url}:`, error);
            throw error;
        } finally {
            if (!isBackground) hideLoader();
        }
    };

    // Global Session Check for protected pages
    window.checkSession = async () => {
        try {
            const projectRoot = window.getProjectRoot();
            // Find path to check-session.php relative to project root
            let path = projectRoot + '/backend/api/auth/check-session.php';

            if (window.location.pathname.endsWith('index.html') || window.location.pathname === '/' || window.location.pathname.endsWith('Caravan%20of%20Spices/')) return; // Home skip
            if (window.location.pathname.includes('/auth/')) return; // Auth skip

            const response = await fetch(path);
            const result = await response.json();
            if (!result.logged_in) {
                window.location.href = projectRoot + '/frontend/auth/login.html';
            }
        } catch (e) { console.error('Session check failed', e); }
    };

    // Handle Back Button and Cache: Force re-validation
    window.addEventListener('pageshow', (event) => {
        if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
            // Page loaded from cache (e.g. back button)
            if (window.checkSession) window.checkSession();
        }
    });
})();

// Global Currency Manager
const CurrencyManager = {
    settings: null,
    isFetching: false,

    // Exchange Rates (Base: INR)
    // Initially empty, populated from backend
    rates: {},

    async init() {
        // Return immediately if we have settings AND rates
        if (this.settings && Object.keys(this.rates).length > 0) return this.settings;

        // Try session storage for instant render
        const cached = sessionStorage.getItem('user_currency_settings');
        const cachedRates = sessionStorage.getItem('exchange_rates');

        if (cachedRates) {
            try {
                this.rates = JSON.parse(cachedRates);
                // Cache-bust: If rates appear to be old USD-based (where INR ~ 80+), clear it
                if (this.rates && this.rates['INR'] > 50) {
                    sessionStorage.removeItem('exchange_rates');
                    sessionStorage.removeItem('user_currency_settings');
                    this.rates = {};
                    this.settings = null;
                }
            } catch (e) { console.error('Error parsing rates', e); }
        }

        if (cached) {
            try {
                this.settings = JSON.parse(cached);
                // Sanitize: Force INR to 1 even from cache
                if (this.settings.code === 'INR') this.settings.rate = 1;

                this.applyToStaticMarkers();
                this.fetchSettings(); // Verify in background
                this.fetchRates();    // Verify rates in background
                return this.settings;
            } catch (e) {
                console.error('Error parsing cached settings', e);
            }
        }

        // Parallel fetch
        const p1 = this.fetchSettings();
        const p2 = this.fetchRates();
        await Promise.all([p1, p2]);
        return this.settings;
    },

    async fetchRates() {
        const projectRoot = window.getProjectRoot();
        const paths = [
            projectRoot + '/backend/api/currency/get-rates.php',
            '../../backend/api/currency/get-rates.php',
            '../backend/api/currency/get-rates.php'
        ];

        for (const path of paths) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000);
                const response = await fetch(path, { signal: controller.signal });
                clearTimeout(timeoutId);
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.rates) {
                        // Cache-bust: If rates appear to be old USD-based, reject them
                        if (result.rates['INR'] > 50) {
                            console.warn('Received stale USD-based rates from backend. Forcing refresh.');
                            return;
                        }
                        this.rates = result.rates;
                        sessionStorage.setItem('exchange_rates', JSON.stringify(this.rates));
                        if (this.settings && this.settings.code) {
                            this.settings.rate = this.rates[this.settings.code] || 1;
                            sessionStorage.setItem('user_currency_settings', JSON.stringify(this.settings));
                            this.refreshUI();
                        }
                        return;
                    }
                }
            } catch (e) { continue; }
        }
    },

    async fetchSettings() {
        if (this.isFetching) return;
        this.isFetching = true;

        const projectRoot = window.getProjectRoot();
        const paths = [
            projectRoot + '/backend/api/auth/get-profile.php',
            '../../backend/api/auth/get-profile.php',
            '../backend/api/auth/get-profile.php'
        ];

        for (const path of paths) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000);

                const response = await fetch(path, { signal: controller.signal });
                clearTimeout(timeoutId);

                if (response.ok) {
                    const text = await response.text();
                    try {
                        const result = JSON.parse(text);
                            if (result.success) {
                                // Check for unverified farmer status
                                if (result.data.role === 'farmer' && parseInt(result.data.is_verified) === 0) {
                                    const isCertPage = window.location.pathname.includes('certificates.html');
                                    if (!isCertPage) {
                                        window.location.href = 'certificates.html';
                                        return;
                                    }
                                    // Visual enforcement on sidebar
                                    document.querySelectorAll('aside nav a').forEach(link => {
                                        if (!link.getAttribute('href').includes('certificates.html')) {
                                            link.style.opacity = '0.4';
                                            link.style.pointerEvents = 'none';
                                            link.style.filter = 'grayscale(100%)';
                                            link.title = 'Complete verification to unlock this section';
                                        }
                                    });
                                } else if (result.data.role === 'farmer' && parseInt(result.data.is_verified) === 1) {
                                    // Show verified badge if it exists on the page
                                    const badge = document.getElementById('header-verified-badge');
                                    if (badge) badge.style.display = 'block';
                                }

                                const code = result.data.currency_code || 'INR';
                            const newSettings = {
                                symbol: result.data.currency_symbol || 'â‚¹',
                                code: code,
                                rate: (code === 'INR') ? 1 : (this.rates[code] || 1) // Set rate based on code, force 1 for INR
                            };

                            this.settings = newSettings;
                            sessionStorage.setItem('user_currency_settings', JSON.stringify(this.settings));
                            this.isFetching = false;

                            this.applyToStaticMarkers();
                            this.refreshUI();
                            return this.settings;
                        }
                    } catch (e) { }
                }
            } catch (error) { continue; }
        }

        this.isFetching = false;
        if (!this.settings) {
            this.settings = { symbol: '₹', code: 'INR', rate: 1 };
            this.applyToStaticMarkers();
        }
        return this.settings;
    },

    // Update rates dynamically from external sources (e.g. after a save)
    updateRates(newRates) {
        if (!newRates || typeof newRates !== 'object') return;
        this.rates = { ...this.rates, ...newRates };
        sessionStorage.setItem('exchange_rates', JSON.stringify(this.rates));

        // Also update local settings rate if code exists
        if (this.settings && this.settings.code) {
            this.settings.rate = this.rates[this.settings.code] || 1;
            sessionStorage.setItem('user_currency_settings', JSON.stringify(this.settings));
        }

        this.applyToStaticMarkers();
        this.refreshUI();
    },

    // central method to update settings and trigger UI refresh
    updateSettings(newSettings) {
        this.settings = { ...this.settings, ...newSettings };
        sessionStorage.setItem('user_currency_settings', JSON.stringify(this.settings));
        this.applyToStaticMarkers();
        this.refreshUI();
    },

    abbreviate(num) {
        if (num === null || num === undefined) return '0';
        const val = parseFloat(num);
        if (isNaN(val)) return num;

        if (val >= 1000000000) {
            return (val / 1000000000).toFixed(1).replace(/\.0$/, '') + 'B';
        }
        if (val >= 1000000) {
            return (val / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (val >= 1000) {
            return (val / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        }
        return val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    },

    convert(amount, fromCode, toCode) {
        if (!fromCode || !toCode || fromCode === toCode) return parseFloat(amount);

        // Convert fromCode -> INR -> toCode
        const rates = this.rates || {};
        const fromRate = fromCode === 'INR' ? 1 : rates[fromCode];
        const toRate = toCode === 'INR' ? 1 : rates[toCode];

        if (!fromRate || !toRate || fromRate > 10 || toRate > 10) {
            // Safety: If rates look suspicious (e.g. fromRate is 83 but should be 1), 
            // it means we have a base mismatch. Skip conversion to avoid crazy numbers.
            console.warn(`Currency base mismatch detected: fromRate=${fromRate}, toRate=${toRate}`);
            return parseFloat(amount);
        }

        const amountInBase = parseFloat(amount) / fromRate;
        return amountInBase * toRate;
    },

    snap(val) {
        if (typeof val !== 'number' || isNaN(val)) return val;

        // 1. "Pretty Rounding" for large values (clean numbers)
        // We prioritize this for large values so that conversion artifacts like 299,983
        // snap to 300,000 before they just get rounded to the nearest (ugly) integer.
        if (val > 1000) {
            const tolerances = [10000, 5000, 1000, 500, 100, 50];
            const threshold = val * 0.0002; // Reduced tolerance to 0.02% (e.g. ±60 for 300k, ±0.6 for 3k)

            for (const t of tolerances) {
                if (t > val / 2) continue;
                let pretty = Math.round(val / t) * t;
                if (Math.abs(val - pretty) <= threshold) {
                    return pretty;
                }
            }
        }

        // 2. Basic rounding to nearest integer (Magnitude-aware)
        // Increased tolerance to 0.15 to handle cases like 250.12
        let rounded = Math.round(val);
        if (Math.abs(val - rounded) < Math.max(0.15, val * 0.0002)) return rounded;

        return val;
    },

    format(amount) {
        const settings = this.settings || { symbol: '₹', code: 'INR', rate: 1 };
        let num = parseFloat(amount);
        if (isNaN(num)) return amount;

        // Default format assumes input is INR
        const rate = (settings.code === 'INR') ? 1 : (this.rates[settings.code] || 1);
        let val = this.snap(num * rate);

        return settings.symbol + val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    },

    formatFrom(amount, fromCode) {
        const settings = this.settings || { symbol: '₹', code: 'INR', rate: 1 };
        const converted = this.convert(amount, fromCode, settings.code);
        const val = this.snap(converted);
        return settings.symbol + val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    },

    formatAbbreviated(amount) {
        const settings = this.settings || { symbol: 'â‚¹', code: 'INR', rate: 1 };
        let num = parseFloat(amount);
        if (isNaN(num)) return amount;
        const rate = (settings.code === 'INR') ? 1 : (this.rates[settings.code] || 1);
        const val = num * rate;
        return settings.symbol + this.abbreviate(val);
    },

    formatFromAbbreviated(amount, fromCode) {
        const settings = this.settings || { symbol: 'â‚¹', code: 'INR', rate: 1 };
        const converted = this.convert(amount, fromCode, settings.code);
        return settings.symbol + this.abbreviate(converted);
    },

    formatHistorical(amountUSD, storedRate, storedCurrencyCode) {
        const userCurrency = this.getCode(); // Current viewer's currency
        const isOriginalCurrency = (userCurrency === storedCurrencyCode);

        // HYBRID LOGIC:
        // 1. If viewing in same currency as order: Use Stored Rate (Shows exact original price like 100 INR)
        // 2. If viewing in DIFFERENT currency: Use Live Rate (Shows fair value in new currency)

        let val;

        if (isOriginalCurrency) {
            // Use Stored Rate -> Snaps to original integer
            val = parseFloat(amountUSD || 0) * (parseFloat(storedRate) || 1);
            let rounded = Math.round(val);
            if (Math.abs(val - rounded) < Math.max(0.05, val * 0.0001)) val = rounded;
        } else {
            // Use Live Rate -> Convert INR to UserCurrency
            // (amount is now assumed to be in base INR)
            val = this.convert(amountUSD, 'INR', userCurrency);
        }

        return val.toLocaleString(undefined, {
            style: 'currency',
            currency: userCurrency,
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    },

    getSymbol() {
        return this.settings ? this.settings.symbol : '₹';
    },

    getCode() {
        return this.settings ? this.settings.code : 'INR';
    },

    applyToStaticMarkers() {
        const symbol = this.getSymbol();
        const code = this.getCode();

        document.querySelectorAll('.currency-symbol').forEach(el => {
            el.textContent = symbol;
            // Ensure visibility
            el.style.opacity = '1';
        });
        document.querySelectorAll('.currency-code').forEach(el => {
            el.textContent = code;
        });
    },

    refreshUI() {
        // List of update functions on various pages
        const refreshers = [
            'loadDashboard',   // Dashboard
            'loadStats',       // Dashboard legacy/etc
            'loadProducts',    // Inventory
            'loadOrders',      // Orders
            'loadCatalog',     // Customer Catalog
            'loadCart',        // Cart
            'loadAdminStats',  // Admin
            'loadAuctions',    // Auctions
            'renderProducts',  // Inventory secondary
            'renderOrders',    // Generic
            'loadTransactions', // Admin Transactions
            'loadActivityLogs', // Admin Activity
            'updatePaymentPrice' // Payment Page Update
        ];

        refreshers.forEach(fn => {
            if (typeof window[fn] === 'function') {
                // Call them safely
                try {
                    window[fn]();
                } catch (e) {
                    console.warn(`Safe refresh failed for ${fn}:`, e);
                }
            }
        });
    }
};

window.CurrencyManager = CurrencyManager;
window.formatPrice = (amount) => CurrencyManager.format(amount);

// Centralized status normalization helper
window.normalizeStatus = (status) => {
    if (!status) return 'ordered';
    const s = String(status).trim().toLowerCase();
    // Default problematic or empty statuses to 'ordered'
    if (s === '' || s === 'unknown' || s === 'unknown status') return 'ordered';
    return s;
};

document.addEventListener('DOMContentLoaded', () => {
    // Staggered init to prevent server overload (502s)
    setTimeout(() => {
        CurrencyManager.init();
    }, 100);

    // Standard UI Interactions
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.product-card').forEach((product, index) => {
        product.style.opacity = '0';
        product.style.transform = 'translateY(20px)';
        product.style.transition = `all 0.6s ease-out ${index * 0.1}s`;
        observer.observe(product);
    });
});
