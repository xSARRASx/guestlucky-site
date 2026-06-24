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
  // Dismiss expire après 7 jours (nouveau key pour invalider les anciennes fermetures permanentes)
  var dismissed = null;
  try {
  var raw = localStorage.getItem("guestlucky_webinar_dismissed_at");
  if (raw) {
  var elapsed = Date.now() - parseInt(raw, 10);
  if (elapsed < 7 * 24 * 60 * 60 * 1000) dismissed = true;
  }
  } catch (e) {}
  if (!dismissed) {
  setTimeout(function () { card.classList.add("show"); }, 1500);
  }
  var closeBtn = card.querySelector("[data-webinar-close]");
  if (closeBtn) {
  closeBtn.addEventListener("click", function () {
  card.classList.remove("show");
  try { localStorage.setItem("guestlucky_webinar_dismissed_at", Date.now().toString()); } catch (e) {}
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
  function initInterfaceTour() {
  var wrapper = document.querySelector("[data-interface-tour]");
  if (!wrapper) return;
  var tabs = wrapper.querySelectorAll("[data-tour-tab]");
  var panels = wrapper.querySelectorAll("[data-tour-panel]");
  function showPanel(id) {
  tabs.forEach(function (t) {
  var active = t.getAttribute("data-tour-tab") === id;
  t.classList.toggle("active", active);
  t.setAttribute("aria-selected", active ? "true" : "false");
  });
  panels.forEach(function (p) {
  var active = p.getAttribute("data-tour-panel") === id;
  p.classList.toggle("active", active);
  if (active) p.removeAttribute("hidden");
  else p.setAttribute("hidden", "");
  });
  // Scroll the active tab into view on mobile
  var activeTab = wrapper.querySelector("[data-tour-tab=\"" + id + "\"]");
  if (activeTab && activeTab.scrollIntoView) {
  activeTab.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
  }
  }
  tabs.forEach(function (tab) {
  tab.addEventListener("click", function () {
  showPanel(tab.getAttribute("data-tour-tab"));
  });
  });
  // Sub-tabs (one per image inside a panel)
  wrapper.querySelectorAll("[data-tour-subtabs]").forEach(function (group) {
  var subtabs = group.querySelectorAll("[data-tour-subtab]");
  var panel = group.parentNode;
  while (panel && !panel.hasAttribute("data-tour-panel")) {
  panel = panel.parentNode;
  }
  if (!panel) return;
  var shots = panel.querySelectorAll("[data-tour-shot]");
  subtabs.forEach(function (st) {
  st.addEventListener("click", function () {
  var idx = st.getAttribute("data-tour-subtab");
  subtabs.forEach(function (s) {
  var active = s.getAttribute("data-tour-subtab") === idx;
  s.classList.toggle("active", active);
  s.setAttribute("aria-selected", active ? "true" : "false");
  });
  shots.forEach(function (sh) {
  var active = sh.getAttribute("data-tour-shot") === idx;
  sh.classList.toggle("active", active);
  if (active) sh.removeAttribute("hidden");
  else sh.setAttribute("hidden", "");
  });
  });
  });
  });
  }
  function initShareCopy() {
  document.querySelectorAll(".share-copy[data-copy-url]").forEach(function (btn) {
  btn.addEventListener("click", function (e) {
  e.preventDefault();
  var url = btn.getAttribute("data-copy-url") || window.location.href;
  var feedback = btn.querySelector(".share-copy-feedback");
  var done = function () {
  btn.classList.add("copied");
  if (feedback) feedback.style.display = "block";
  setTimeout(function () {
  btn.classList.remove("copied");
  if (feedback) feedback.style.display = "none";
  }, 1800);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
  navigator.clipboard.writeText(url).then(done, done);
  } else {
  var ta = document.createElement("textarea");
  ta.value = url;
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand("copy"); } catch (err) {}
  document.body.removeChild(ta);
  done();
  }
  });
  });
  }
  function initFloatingLogo() {
  var container = document.querySelector(".floating-contact");
  if (!container) return;
  if (container.querySelector(".bubble.logo")) return;
  var isEN = document.documentElement.lang === "en" || /\-en\.html$/.test(location.pathname);
  var link = document.createElement("a");
  link.href = isEN ? "index-en.html" : "index.html";
  link.className = "bubble logo";
  link.setAttribute("aria-label", "GuestLucky");
  var img = document.createElement("img");
  img.src = "https://www.locationcourteduree.fr/wp-content/uploads/2026/05/Capture-decran-2026-05-16-a-11.02.01.png";
  img.alt = "GuestLucky";
  img.width = 40;
  img.height = 40;
  link.appendChild(img);
  container.insertBefore(link, container.firstChild);
  }
  function initWelcomeModal() {
  if (document.getElementById("gl-welcome-modal")) return;
  var isEN = document.documentElement.lang === "en" || /\-en\.html$/.test(location.pathname);
  var content = isEN ? {
  badge: "WE CAN HELP YOU",
  title: 'Questions? Need a <span class="gl-accent">personalized offer</span>?',
  body: "Book a call with a GuestLucky expert. 30 minutes by video to map out your setup, answer all your questions, and unlock a tailored offer for your business.",
  cta: "Book a demo",
  ctaHref: "demo-en.html",
  decline: "No thanks, continue browsing",
  footer: "Free · No commitment",
  closeLabel: "Close"
  } : {
  badge: "ON PEUT T'AIDER",
  title: 'Des questions ? Besoin d\'une <span class="gl-accent">offre personnalisée</span> ?',
  body: "Prends rendez-vous avec un expert GuestLucky. 30 minutes en visio pour cadrer ton setup, répondre à toutes tes questions et débloquer une offre adaptée à ton activité.",
  cta: "Prendre rendez-vous",
  ctaHref: "demo.html",
  decline: "Non merci, je continue ma visite",
  footer: "Gratuit · Sans engagement",
  closeLabel: "Fermer"
  };
  var STORAGE_KEY = "gl_welcome_modal_last_shown";
  var COOLDOWN_MS = 3 * 60 * 1000;
  var INITIAL_DELAY_MS = 20 * 1000;
  function getLastShown() {
  try { return parseInt(localStorage.getItem(STORAGE_KEY), 10) || 0; } catch (e) { return 0; }
  }
  function markShown() {
  try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch (e) {}
  }
  function shouldShowNow() {
  return (Date.now() - getLastShown()) >= COOLDOWN_MS;
  }
  var modal = document.createElement("div");
  modal.id = "gl-welcome-modal";
  modal.className = "gl-modal";
  modal.setAttribute("role", "dialog");
  modal.setAttribute("aria-modal", "true");
  modal.setAttribute("aria-hidden", "true");
  modal.innerHTML =
  '<div class="gl-modal-overlay" data-gl-close></div>' +
  '<div class="gl-modal-card" role="document">' +
  '<button type="button" class="gl-modal-close" data-gl-close aria-label="' + content.closeLabel + '">' +
  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
  '</button>' +
  '<span class="gl-modal-badge">' +
  '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.7 5.2L19 9l-5.3 1.7L12 16l-1.7-5.3L5 9l5.3-1.8z"/></svg>' +
  content.badge +
  '</span>' +
  '<h3 class="gl-modal-title">' + content.title + '</h3>' +
  '<p class="gl-modal-body">' + content.body + '</p>' +
  '<a href="' + content.ctaHref + '" class="gl-modal-cta">' +
  '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
  '<span>' + content.cta + '</span>' +
  '<span class="gl-modal-arrow">→</span>' +
  '</a>' +
  '<button type="button" class="gl-modal-decline" data-gl-close>' + content.decline + '</button>' +
  '<p class="gl-modal-footer">' + content.footer + '</p>' +
  '</div>';
  document.body.appendChild(modal);
  function openModal() {
  modal.classList.add("show");
  modal.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
  markShown();
  }
  function closeModal() {
  modal.classList.remove("show");
  modal.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "";
  }
  modal.querySelectorAll("[data-gl-close]").forEach(function (el) {
  el.addEventListener("click", closeModal);
  });
  document.addEventListener("keydown", function (e) {
  if (e.key === "Escape" && modal.classList.contains("show")) closeModal();
  });
  if (shouldShowNow()) {
  setTimeout(openModal, INITIAL_DELAY_MS);
  }
  setInterval(function () {
  if (!modal.classList.contains("show") && shouldShowNow()) openModal();
  }, 30 * 1000);
  }
  function initTranquilleToggle() {
  var toggle = document.querySelector(".ap-toggle");
  if (!toggle) return;
  var board = document.querySelector(".ap-board");
  if (!board) return;
  toggle.addEventListener("click", function (e) {
  var btn = e.target.closest(".ap-tg");
  if (!btn) return;
  toggle.querySelectorAll(".ap-tg").forEach(function (b) { b.classList.remove("active"); });
  btn.classList.add("active");
  var mode = btn.getAttribute("data-ap");
  board.setAttribute("data-mode", mode);
  });
  }
  function init() {
  initPricing();
  // ---- Auto-scroll si URL contient #agenda au chargement ----
  if (window.location.hash === '#agenda') {
    setTimeout(function() {
      var target = document.getElementById('agenda');
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
  }
  // ---- Smooth scroll vers #agenda ----
  document.querySelectorAll('a[href="#agenda"], a[href$="index.html#agenda"]').forEach(function(link) {
    link.addEventListener('click', function(e) {
      var target = document.getElementById('agenda');
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.pushState(null, '', '#agenda');
      }
    });
  });
  initFAQ();
  initCTAMenu();
  initMobileMenu();
  initCookieBanner();
  initWebinarFloat();
  initLangDropdown();
  initInterfaceTour();
  initShareCopy();
  initTranquilleToggle();
  // initFloatingLogo(); // disabled - bubble removed per Martin
  initWelcomeModal();
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();
  }
  if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
  } else {
  init();
  }
})();
