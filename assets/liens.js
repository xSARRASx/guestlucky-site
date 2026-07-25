/* ============================================================================
   GuestLucky — LIENS CENTRALISÉS & ÉDITABLES
   ----------------------------------------------------------------------------
   👉 Sébastien : c'est LE SEUL fichier à modifier pour changer les destinations
      des boutons Formations et des liens affiliés de la Boutique.
      Pas besoin de toucher au HTML des pages.

   Comment ça marche :
   - Dans le HTML, un bouton porte un attribut  data-lien="ma-cle"
   - Ici, on associe "ma-cle" à une URL.
   - Au chargement de la page, le href est injecté automatiquement.

   Pour changer une destination : remplace simplement l'URL entre guillemets.
   ============================================================================ */

window.GL_LIENS = {

  /* ----- FORMATIONS OFFERTES (page formations.html) -----
     Destinations provisoires = pages lesousloueur.fr (fonctionnent tant que
     le domaine reste actif). Remplace par les URLs finales quand tu les as. */
  "formation-sousloc-serie":      "https://lesousloueur.fr/formation-sous-location/",
  "formation-sousloc-1h":         "https://lesousloueur.fr/formation-sous-location/",
  "formation-conciergerie-serie": "https://lesousloueur.fr/formation-conciergerie-airbnb/",
  "formation-conciergerie-1h":    "https://lesousloueur.fr/formation-conciergerie-airbnb/",
  "formation-nettoyage":          "https://lesousloueur.fr/formation-societe-de-nettoyage/",

  /* ----- BOUTIQUE (page boutique.html) -----
     Liens affiliés Amazon. IMPORTANT : garde ton tag d'affiliation (?tag=...)
     dans chaque URL, sinon les ventes ne te sont pas attribuées.
     Astuce : tu peux centraliser ton tag ci-dessous et le réutiliser. */
  "boutique-tag-affiliation": "guestlucky-21",   // <-- ton tag Amazon (exemple)

  "boutique-produit-1": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",
  "boutique-produit-2": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",
  "boutique-produit-3": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",
  "boutique-produit-4": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",
  "boutique-produit-5": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",
  "boutique-produit-6": "https://www.amazon.fr/dp/XXXXXXXXXX?tag=guestlucky-21",

  /* ----- PODCAST (page podcast.html) -----
     Liens vers les plateformes d'écoute. */
  "podcast-apple":   "https://podcasts.apple.com/fr/podcast/entreprendre-avec-sebastien-more",
  "podcast-spotify": "https://open.spotify.com/show/XXXXXXXXXXXX",
  "podcast-ausha":   "https://podcast.ausha.co/entreprendre-avec-sebastien-more",
  "podcast-youtube": "https://www.youtube.com/@sebastienmore",

  /* ----- CANAL FORMATION (contact / académie) -----
     Canal DIFFÉRENT du produit GuestLucky (audiences / trackers distincts). */
  "formation-whatsapp": "https://wa.me/33756992692",
  "formation-email":    "mailto:formation@guestlucky.com",

  /* ----- WEBINAIRE FORMATION (bootcamp conciergerie & sous-location) -----
     Page distincte du webinaire produit. Remplace par ton lien d'inscription
     (WebinarJam / autre) quand tu l'as. */
  "webinaire-formation-inscription": "https://lesousloueur.fr/webinaire-conciergerie-et-sous-location/"
};

/* ---- Injection automatique des href (ne pas modifier) ---- */
(function () {
  function applyLinks() {
    var map = window.GL_LIENS || {};
    document.querySelectorAll("[data-lien]").forEach(function (el) {
      var key = el.getAttribute("data-lien");
      if (map[key]) { el.setAttribute("href", map[key]); }
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", applyLinks);
  } else {
    applyLinks();
  }
})();
