(function () {
  "use strict";
  // ---- Pricing config (price per logement, monthly) ----
  var PRICES = { gratuit: 0, premium: 5.99, pro: 14.99 };
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
  // Mobile uniquement : on retire complètement la carte flottante webinaire
  if (window.matchMedia && window.matchMedia("(max-width: 768px)").matches) { card.remove(); return; }
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
  // Mobile uniquement : pas de popup intempestive (on la garde sur ordinateur)
  if (window.matchMedia && window.matchMedia("(max-width: 768px)").matches) return;
  var isEN = document.documentElement.lang === "en" || /\-en\.html$/.test(location.pathname);
  var content = isEN ? {
  badge: "WE CAN HELP YOU",
  title: 'Questions? Need a <span class="gl-accent">personalized offer</span>?',
  body: "Book a call with a GuestLucky expert. About 1 hour by video to map out your setup, answer all your questions, and unlock a tailored offer for your business.",
  cta: "Book a demo",
  ctaHref: "https://www.guestlucky.com/rdv/site",
  decline: "No thanks, continue browsing",
  footer: "Free · No commitment",
  closeLabel: "Close"
  } : {
  badge: "ON PEUT T'AIDER",
  title: 'Des questions ? Besoin d\'une <span class="gl-accent">offre personnalisée</span> ?',
  body: "Prends rendez-vous avec un expert GuestLucky. Environ 1h en visio pour cadrer ton setup, répondre à toutes tes questions et débloquer une offre adaptée à ton activité.",
  cta: "Prendre rendez-vous",
  ctaHref: "https://www.guestlucky.com/rdv/site",
  decline: "Non merci, je continue ma visite",
  footer: "Gratuit · Sans engagement",
  closeLabel: "Fermer"
  };
  var STORAGE_KEY = "gl_welcome_modal_last_shown";
  var COOLDOWN_MS = 2 * 60 * 1000;      // 2 minutes pile entre chaque affichage
  var INITIAL_DELAY_MS = 2 * 60 * 1000; // premiere apparition apres 2 minutes aussi
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
  '<a href="' + content.ctaHref + '" target="_blank" rel="noopener" class="gl-modal-cta">' +
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
  }, 15 * 1000);
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
  initAppBadges();
  initVideoTestimonial();
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();
  }
  function initAppBadges() {
  var col = document.querySelector(".site-footer .footer-grid > div");
  if (!col || document.getElementById("gl-appbadges")) return;
  var isEN = document.documentElement.lang === "en" || /\-en\.html$/.test(location.pathname);
  if (!document.getElementById("gl-appbadges-style")) {
  var style = document.createElement("style");
  style.id = "gl-appbadges-style";
  style.textContent =
  ".footer-apps{margin-top:22px}" +
  ".footer-apps-eb{display:block;font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#9B6FFF;margin-bottom:12px}" +
  ".footer-apps-badges{display:flex;flex-wrap:wrap;gap:12px}" +
  ".gl-appbadge{display:inline-flex;align-items:center;gap:10px;padding:9px 16px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);color:#fff;text-decoration:none;cursor:default;transition:background .2s,border-color .2s}" +
  ".gl-appbadge:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.28)}" +
  ".gl-appbadge svg{width:22px;height:22px;flex-shrink:0}" +
  ".gl-ab-tx{display:flex;flex-direction:column;line-height:1.15;text-align:left}" +
  ".gl-ab-small{font-size:9.5px;letter-spacing:.07em;text-transform:uppercase;opacity:.75}" +
  ".gl-ab-big{font-size:15px;font-weight:700}";
  document.head.appendChild(style);
  }
  var soon = isEN ? "Coming soon on" : "Bientôt sur";
  var eb = isEN ? "Mobile app · coming soon" : "Application mobile · bientôt disponible";
  var wrap = document.createElement("div");
  wrap.className = "footer-apps";
  wrap.id = "gl-appbadges";
  wrap.innerHTML =
  '<span class="footer-apps-eb">' + eb + '</span>' +
  '<div class="footer-apps-badges">' +
  '<span class="gl-appbadge" role="img" aria-label="App Store - ' + (isEN ? 'coming soon' : 'bientôt disponible') + '">' +
  '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.365 1.43c0 1.14-.44 2.23-1.22 3.02-.83.86-2.2 1.52-3.34 1.43-.14-1.1.42-2.28 1.16-3.02.83-.84 2.28-1.46 3.4-1.43zM20.9 17.02c-.55 1.27-.82 1.84-1.53 2.96-.99 1.57-2.39 3.52-4.12 3.53-1.54.02-1.94-1-4.03-.99-2.09.01-2.53 1.01-4.07.99-1.73-.02-3.05-1.78-4.04-3.35C.4 15.9-.14 11.36 1.4 8.95c1.09-1.71 2.81-2.71 4.43-2.71 1.65 0 2.69 1 4.06 1 1.33 0 2.14-1 4.05-1 1.44 0 2.97.79 4.06 2.15-3.57 1.96-2.99 7.06.9 8.63z"/></svg>' +
  '<span class="gl-ab-tx"><span class="gl-ab-small">' + soon + '</span><span class="gl-ab-big">App Store</span></span>' +
  '</span>' +
  '<span class="gl-appbadge" role="img" aria-label="Google Play - ' + (isEN ? 'coming soon' : 'bientôt disponible') + '">' +
  '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#00D2FF" d="M3.6 2.3 13 11.7l-9.4 9.4c-.38-.2-.6-.6-.6-1.08V3.38c0-.48.22-.88.6-1.08z"/><path fill="#00E676" d="M4.7 2.3 16 8.75l-2.9 2.95z"/><path fill="#FFCE00" d="M18.95 10.6c.7.4.7 1.42 0 1.82L16 14.15l-2.9-2.95 2.9-2.95z"/><path fill="#FF3D00" d="M4.7 21.7 13.1 12.7l2.9 2.95z"/></svg>' +
  '<span class="gl-ab-tx"><span class="gl-ab-small">' + soon + '</span><span class="gl-ab-big">Google Play</span></span>' +
  '</span>' +
  '</div>';
  col.appendChild(wrap);
  }
  function initVideoTestimonial() {
  // Page d'accueil FR uniquement
  var p = location.pathname;
  var isHome = (p === "/" || /\/index\.html$/.test(p)) && !/index-en/.test(p);
  if (!isHome) return;
  if (document.getElementById("gl-vtestimo")) return;
  // On insère juste AU-DESSUS de la section avis clients (.tv3) ; sinon avant le footer
  var anchor = document.querySelector(".tv3") || document.querySelector(".site-footer");
  if (!anchor) return;
  if (!document.getElementById("gl-vtestimo-style")) {
  var style = document.createElement("style");
  style.id = "gl-vtestimo-style";
  style.textContent =
  ".gl-vt{padding:80px 0;background:linear-gradient(180deg,#fff 0%,#f6f3ff 100%);position:relative;overflow:hidden}" +
  ".gl-vt .container{position:relative;z-index:1}" +
  ".gl-vt-head{text-align:center;max-width:720px;margin:0 auto 44px}" +
  ".gl-vt-eb{display:inline-block;padding:6px 14px;background:rgba(107,70,255,.10);border:1px solid rgba(107,70,255,.15);color:#6b46ff;border-radius:100px;font-size:12.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:14px}" +
  ".gl-vt-title{font-size:clamp(1.8rem,3.4vw,2.6rem);font-weight:800;letter-spacing:-.03em;color:#0F1A35;margin:0;line-height:1.15}" +
  ".gl-vt-title .g{background:linear-gradient(135deg,#6b46ff 0%,#E84A8C 100%);-webkit-background-clip:text;background-clip:text;color:transparent}" +
  ".gl-vt-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:44px;align-items:center;max-width:1120px;margin:0 auto}" +
  "@media (max-width:900px){.gl-vt-grid{grid-template-columns:1fr;gap:32px}}" +
  ".gl-vt-video{position:relative;width:100%;aspect-ratio:16/9;border-radius:20px;overflow:hidden;background:#000;box-shadow:0 40px 90px -30px rgba(15,26,53,.5)}" +
  ".gl-vt-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}" +
  ".gl-vt-quotes{display:flex;flex-direction:column;gap:16px}" +
  ".gl-vt-q{position:relative;padding:0 0 0 20px;color:#3a3a4a;font-size:1.02rem;line-height:1.55;border-left:3px solid transparent;border-image:linear-gradient(180deg,#6b46ff,#E84A8C) 1}" +
  ".gl-vt-q strong{color:#0F1A35;font-weight:700}" +
  ".gl-vt-author{display:flex;align-items:center;gap:16px;margin-top:10px;padding:16px 20px;background:#1a2547;border-radius:16px;color:#fff;max-width:max-content}" +
  ".gl-vt-author-badge{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#6b46ff,#E84A8C);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0}" +
  ".gl-vt-author-tx strong{display:block;font-size:15px;font-weight:800}" +
  ".gl-vt-author-tx span{font-size:13px;color:#c4c8d8}" +
  "@media (max-width:900px){.gl-vt-author{max-width:none}}";
  document.head.appendChild(style);
  }
  var sec = document.createElement("section");
  sec.className = "gl-vt";
  sec.id = "gl-vtestimo";
  sec.setAttribute("aria-label", "Témoignage client Estelle - Conciergerie AmBiens");
  sec.innerHTML =
  '<div class="container">' +
  '<div class="gl-vt-head">' +
  '<span class="gl-vt-eb">Témoignage client</span>' +
  '<h2 class="gl-vt-title">« Je ne peux plus <span class="g">m\'en passer</span> »</h2>' +
  '</div>' +
  '<div class="gl-vt-grid">' +
  '<div class="gl-vt-video">' +
  '<iframe src="https://player.vimeo.com/video/1152568026?title=0&amp;byline=0&amp;portrait=0&amp;dnt=1" title="Témoignage Estelle - Conciergerie AmBiens" allow="autoplay; fullscreen; picture-in-picture; clipboard-write" allowfullscreen loading="lazy"></iframe>' +
  '</div>' +
  '<div class="gl-vt-quotes">' +
  '<p class="gl-vt-q">« Honnêtement, aujourd\'hui, je ne sais pas comment je ferais <strong>sans GuestLucky</strong>. »</p>' +
  '<p class="gl-vt-q">« Dès qu\'une réservation tombe, la <strong>mission de ménage se crée automatiquement</strong>. Avant, je faisais tout à la main. »</p>' +
  '<p class="gl-vt-q">« J\'ai gagné du temps, de la sérénité, et je suis sûre de <strong>ne plus passer à côté d\'un ménage</strong>. »</p>' +
  '<p class="gl-vt-q">« Tout est centralisé : <strong>ménage, facturation, livret d\'accueil, services additionnels</strong>. »</p>' +
  '<p class="gl-vt-q">« GuestLucky est un outil très complet, et surtout <strong>en constante évolution</strong>. »</p>' +
  '<div class="gl-vt-author">' +
  '<div class="gl-vt-author-badge">AB</div>' +
  '<div class="gl-vt-author-tx"><strong>Estelle - Conciergerie AmBiens</strong><span>Amiens · 70 logements gérés</span></div>' +
  '</div>' +
  '</div>' +
  '</div>' +
  '</div>';
  anchor.parentNode.insertBefore(sec, anchor);
  }
  if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
  } else {
  init();
  }
})();
