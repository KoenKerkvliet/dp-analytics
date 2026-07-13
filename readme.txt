=== DP Analytics ===
Contributors: designpixels
Tags: analytics, statistics, privacy, cookieless, gdpr
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* Eerste versie: cookieless pageview-tracking, dashboard met periode-selectie, KPI's (weergaven/bezoekers/sessies/bounce), grafiek over tijd, populairste pagina's, verkeersbronnen, weergaven-kolom per pagina, dashboard-widget, instelbare bewaartermijn met dagelijkse opruiming.
