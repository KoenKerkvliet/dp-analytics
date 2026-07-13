=== DP Analytics ===
Contributors: designpixels
Tags: analytics, statistics, privacy, cookieless, gdpr
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later

Privacy-vriendelijke, cookieless website-statistieken voor WordPress.

== Description ==

DP Analytics telt weergaven, bezoekers, sessies en verkeersbronnen — zonder cookies en zonder persoonsgegevens op te slaan. Daardoor is er geen cookie-toestemming nodig.

* **Cookieless & privacy-first**: geen cookies, geen local storage, geen IP-opslag. Bezoekers worden geteld via een per-dag roterende, onomkeerbare hash. Valt buiten de cookie-toestemmingsplicht.
* **Cache-safe**: tracking gebeurt via een lichte JavaScript-beacon naar een REST-endpoint, dus page-caches (LiteSpeed e.d.) zitten niet in de weg en bots die geen JavaScript draaien tellen niet mee.
* **Overzichtelijk dashboard**: weergaven, bezoekers, sessies en bouncepercentage per periode (vandaag / 7 / 30 dagen / 12 maanden), met een grafiek over tijd, populairste pagina's en verkeersbronnen.
* **Weergaven per pagina**: een "Weergaven"-kolom in de lijst van pagina's en berichten.
* **Dashboard-widget**: een compact overzicht op het WordPress-hoofddashboard.
* **Licht**: drie compacte databasetabellen, geen externe diensten.

== Changelog ==

= 1.3.1 =
* Fix: de MainWP-koppeling gebruikte de verkeerde sync-hook (mainwp_child_sync_others_data i.p.v. mainwp_site_sync_others_data), waardoor het statistiekblok niet werd meegestuurd. Nu correct.

= 1.3.0 =
* Nieuw: MainWP-koppeling. Op sites die via een MainWP-dashboard beheerd worden (MainWP Child actief), stuurt DP Analytics bij elke sync automatisch een compact statistiekblok mee (de laatste 12 maanden aan bezoekers, weergaven, sessies, verkeersbronnen en — op webshops — omzet). Een MainWP-dashboard kan die cijfers zo in het maandelijkse klantrapport tonen, zonder aparte API-key of losse verbinding. Er stromen alleen geaggregeerde, cookieloze cijfers over; geen bezoekersdata.

= 1.2.0 =
* Nieuw: periodiek e-mailrapport. Laat DP Analytics maandelijks of wekelijks automatisch een overzichtelijke HTML-mail sturen met de belangrijkste cijfers (bezoekers, weergaven, sessies, bouncepercentage, populairste pagina's, verkeersbronnen, en op webshops de omzet en conversie) — inclusief de verandering ten opzichte van de vorige periode. Ideaal om klanten periodiek te laten zien wat hun website oplevert. Meerdere ontvangers instelbaar, met een knop om direct een testrapport te sturen. Verzending via de mailconfiguratie van de site (wp_mail).

= 1.1.0 =
* Nieuw: WooCommerce-integratie. Op sites met WooCommerce toont het dashboard nu ook omzet, aantal bestellingen, gemiddelde orderwaarde, conversieratio (bestellingen ÷ sessies) en de best verkochte producten voor de gekozen periode. De omzetcijfers komen rechtstreeks uit WooCommerce (statussen "verwerkt" en "voltooid", aanpasbaar via de filter dpa_woo_paid_statuses); er wordt niets extra's getrackt. De omzet verschijnt ook op de dashboard-widget.

= 1.0.0 =
* Eerste versie: cookieless pageview-tracking, dashboard met periode-selectie, KPI's (weergaven/bezoekers/sessies/bounce), grafiek over tijd, populairste pagina's, verkeersbronnen, weergaven-kolom per pagina, dashboard-widget, instelbare bewaartermijn met dagelijkse opruiming.
