/* SALW CONSULTING · espace admin : menu mobile, confirmations, envoi automatique, code QR. */
(function () {
  "use strict";

  // Menu latéral sur mobile.
  var menu = document.getElementById("menu"), nav = document.getElementById("nav");
  if (menu && nav) {
    menu.addEventListener("click", function () {
      var ouvert = nav.classList.toggle("ouvert");
      menu.setAttribute("aria-expanded", ouvert ? "true" : "false");
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") nav.classList.remove("ouvert"); });
  }

  // Confirmation avant une action sensible.
  document.querySelectorAll("form[data-confirmer]").forEach(function (f) {
    f.addEventListener("submit", function (e) { if (!window.confirm(f.getAttribute("data-confirmer"))) e.preventDefault(); });
  });

  // Listes qui s'enregistrent dès qu'on change la valeur (pipeline, rôles, filtres).
  document.querySelectorAll("select[data-envoi-auto]").forEach(function (s) {
    s.addEventListener("change", function () { if (s.form) s.form.submit(); });
  });

  // Code QR de la double authentification (bibliothèque chargée seulement sur cette page).
  var qr = document.querySelector("[data-qr]");
  if (qr) {
    var script = document.createElement("script");
    script.src = "https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js";
    script.onload = function () {
      /* global qrcode */
      var q = qrcode(0, "M");
      q.addData(qr.getAttribute("data-qr"));
      q.make();
      qr.innerHTML = q.createImgTag(5, 8, "Code QR à scanner avec votre application d'authentification");
    };
    script.onerror = function () { qr.textContent = "Code QR indisponible : saisissez la clé ci-dessous."; qr.style.height = "auto"; };
    document.head.appendChild(script);
  }
})();
