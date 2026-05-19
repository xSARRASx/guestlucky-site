(function () {
    "use strict";

    // ---- Pricing config (price per logement, monthly) ----
    var PRICES = { gratuit: 0, premium: 5.99, pro: 9.99 };
    var ANNUAL_DISCOUNT = 0.20;
    var GRATUIT_MAX = 2;
    var CUSTOM_THRESHOLD = 50;

    var t = (document.documentElement.lang || "fr").toLowerCase().indexOf("en") === 0
        ? {
            totalMonthly: "Total: {v}/month",
            totalYearly: "Total: {v}/year (billed annually)",
            gratuitCap: "Limited to " + GRATUIT_MAX + " properties",
            customHint: "Custom plan recommended (50+ properties)"
        }
        : {
            totalMonthly: "Total : {v}/mois",
            totalYearly: "Total : {v}/an (facturé annuellement)",
            gratuitCap: "Limité à " + GRATUIT_MAX + " logements",
            customHint: "Plan personnalisé recommandé (50+ logements)"
        };

    var state = { logements: 1, annual: false };

    function fmt(v) {
        var r = Math.round(v * 100) / 100;
        return r.toFixed(2).replace(".", ",") + " €";
    }
    function fmtPerProperty(v) {
        return v.toFixed(2).replace(".", ",") + " €";
    }

    function updatePricing() {
        if (!document.querySelector("[data-counter-value]")) return;
        var n = state.logements;
        var annual = state.annual;
        var factor = annual ? (1 - ANNUAL_DISCOUNT) : 1;
        var premiumPer = PRICES.premium * factor;
        var proPer = PRICES.pro * factor;

        document.querySelectorAll("[data-price]").forEach(function (el) {
            var p = el.getAttribute("data-price");
            if (p === "premium") el.textContent = fmtPerProperty(premiumPer);
            if (p === "pro") el.textContent = fmtPerProperty(proPer);
        });

        var totals = {
            gratuit: 0,
            premium: premiumPer * n * (annual ? 12 : 1),
            pro: proPer * n * (annual ? 12 : 1)
        };

        document.querySelectorAll("[data-total]").forEach(function (el) {
            var plan = el.getAttribute("data-total");
            if (plan === "gratuit") {
                el.textContent = n > GRATUIT_MAX
                    ? t.gratuitCap
                    : (annual ? t.totalYearly.replace("{v}", fmt(0)) : t.totalMonthly.replace("{v}", fmt(0)));
                return;
            }
            if (plan === "custom") {
                el.textContent = n >= CUSTOM_THRESHOLD ? t.customHint : "";
                el.style.display = n >= CUSTOM_THRESHOLD ? "inline-block" : "none";
                return;
            }
            var val = totals[plan];
            if (val === undefined) return;
            el.textContent = annual
                ? t.totalYearly.replace("{v}", fmt(val))
                : t.totalMonthly.replace("{v}", fmt(val));
        });

        var valEl = document.querySelector("[data-counter-value]");
        if (valEl) valEl.textContent = n;
        var dec = document.querySelector("[data-counter-dec]");
        if (dec) dec.disabled = n <= 1;

        document.querySelectorAll("[data-billing]").forEach(function (el) {
            el.classList.toggle("active-label", (el.getAttribute("data-billing") === "annual") === annual);
        });
        var sw = document.querySelector("[data-switch]");
        if (sw) sw.classList.toggle("is-annual", annual);
    }

    function initPricing() {
        var inc = document.querySelector("[data-counter-inc]");
        var dec = document.querySelector("[data-counter-dec]");
        if (inc) inc.addEventListener("click", function () {
            state.logements = Math.min(state.logements + 1, 99);
            updatePricing();
        });
        if (dec) dec.addEventListener("click", function () {
            state.logements = Math.max(state.logements - 1, 1);
            updatePricing();
        });
        var sw = document.querySelector("[data-switch]");
        if (sw) sw.addEventListener("click", function () {
            state.annual = !state.annual;
            updatePricing();
        });
        document.querySelectorAll("[data-billing]").forEach(function (el) {
            el.addEventListener("click", function () {
                state.annual = el.getAttribute("data-billing") === "annual";
                updatePricing();
            });
        });
        updatePricing();
    }

    function initFAQ() {
        document.querySelectorAll(".faq-item").forEach(function (item) {
            var btn = item.querySelector(".faq-question");
            if (!btn) return;
            btn.addEventListener("click", function () {
                item.classList.toggle("open");
            });
        });
    }

    function initCTAMenu() {
        document.querySelectorAll("[data-cta-toggle]").forEach(function (btn) {
            var menu = btn.parentElement.querySelector("[data-cta-menu]");
            if (!menu) return;
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                // close other menus
                document.querySelectorAll("[data-cta-menu].open").forEach(function (m) {
                    if (m !== menu) m.classList.remove("open");
                });
                menu.classList.toggle("open");
            });
        });
        document.addEventListener("click", function (e) {
            document.querySelectorAll("[data-cta-menu].open").forEach(function (m) {
                if (!m.contains(e.target)) m.classList.remove("open");
            });
        });
    }

    function initMobileMenu() {
        var btn = document.querySelector("[data-menu-toggle]");
        var nav = document.querySelector("[data-main-nav]");
        if (!btn || !nav) return;
        btn.addEventListener("click", function () {
            nav.classList.toggle("open");
        });
    }

    function initWebinarFloat() {
        var card = document.querySelector("[data-webinar-float]");
        if (!card) return;
        var dismissed = null;
        try { dismissed = localStorage.getItem("guestlucky_webinar_dismissed"); } catch (e) {}
        if (!dismissed) {
            setTimeout(function () { card.classList.add("show"); }, 1500);
        }
        var closeBtn = card.querySelector("[data-webinar-close]");
        if (closeBtn) {
            closeBtn.addEventListener("click", function () {
                card.classList.remove("show");
                try { localStorage.setItem("guestlucky_webinar_dismissed", "1"); } catch (e) {}
            });
        }
    }

    function initCookieBanner() {
        var banner = document.querySelector("[data-cookie-banner]");
        if (!banner) return;
        var stored = null;
        try { stored = localStorage.getItem("guestlucky_cookie_choice"); } catch (e) {}
        if (!stored) {
            // Defer to next tick so banner animates in
            setTimeout(function () { banner.classList.add("show"); }, 400);
        }
        banner.querySelectorAll("[data-cookie-action]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var choice = btn.getAttribute("data-cookie-action");
                try { localStorage.setItem("guestlucky_cookie_choice", choice); } catch (e) {}
                banner.classList.remove("show");
            });
        });
    }

    function initLangDropdown() {
        document.querySelectorAll("[data-lang-toggle]").forEach(function (btn) {
            var dropdown = btn.parentElement;
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                document.querySelectorAll(".lang-dropdown.open").forEach(function (d) {
                    if (d !== dropdown) d.classList.remove("open");
                });
                dropdown.classList.toggle("open");
            });
        });
        document.addEventListener("click", function (e) {
            document.querySelectorAll(".lang-dropdown.open").forEach(function (d) {
                if (!d.contains(e.target)) d.classList.remove("open");
            });
        });
    }

    function init() {
        initPricing();
        initFAQ();
        initCTAMenu();
        initMobileMenu();
        initCookieBanner();
        initWebinarFloat();
        initLangDropdown();
        var yearEl = document.getElementById("year");
        if (yearEl) yearEl.textContent = new Date().getFullYear();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
