=== Smart WP Integrations ===
Contributors: freelancermartin
Donate link: https://freelancermartin.com/
Tags: woocommerce, merit aktiva, simplebooks, arved, raamatupidamine, eesti
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Ühendab WooCommerce tellimused automaatselt Merit Aktiva ja Simplebooks raamatupidamistarkvaraga.

== Description ==

**Smart WP Integrations** on WooCommerce laiendus, mis edastab tellimused automaatselt Eesti raamatupidamistarkvaradesse. Plugin töötab läbi turvalise vaheserveri (Laravel), mis krüpteerib andmed ja haldab API ühendusi.

= Toetatud integratsioonid =

* **Merit Aktiva** – arvete automaatne loomine, maksude kaardistus, osakondade tugi, sünkroniseerimise kontroll
* **Simplebooks** – arvete ja klientide automaatne loomine
* **Smart Accounts** – arvete edastamine (konfigureeriv)

= Peamised funktsioonid =

* Arved luuakse automaatselt kui tellimus jõuab valitud staatusesse (nt "Lõpetatud")
* Kõik andmed krüpteeritakse AES-256-GCM-iga enne vaheserverisse saatmist
* Maksude kaardistus: igale WooCommerce käibemaksumäärale vastav Merit/Simplebooks VAT kood
* Tarneviiside kaardistus: WooCommerce tarnemeetod → raamatupidamissüsteemi artikkel
* Kategooria → osakond kaardistus (Merit Aktiva)
* Ebaõnnestunud arvete automaatne uuesti saatmine (max 3 katset)
* Saatmise ajalugu (viimased 50 saatmist) leheküljestusega
* Seadete eksport/import JSON formaadis
* E-maili teavitus kui arve saatmine ebaõnnestub
* Sünkroniseerimise kontroll: võrdleb WooCommerce tellimusi raamatupidamissüsteemis olevate arvetega
* Täielik HPOS (High Performance Order Storage) tugi

= Nõuded =

* WordPress 6.0+
* WooCommerce 7.0+
* PHP 8.1+
* Aktiivne litsents vaheserveris (https://wp-liides.freelancermartin.ee)
* Merit Aktiva või Simplebooks konto koos API ligipääsuga

= Kuidas töötab =

1. Plugin kogub WooCommerce tellimusandmed
2. Andmed krüpteeritakse AES-256-GCM algoritmiga (võti on unikaalne igale litsentsile)
3. Krüpteeritud pakett saadetakse vaheserverisse koos litsentsivõtmega
4. Vaheserver dekrüpteerib, valideerib litsentsi ja edastab andmed valitud raamatupidamissüsteemi API-le
5. Arve luuakse automaatselt raamatupidamistarkvaras

== Installation ==

1. Laadi plugin alla ja paki lahti kausta `/wp-content/plugins/smart-wp-integrations/`
   või installi otse WordPress admin paneeli kaudu
2. Aktiveeri plugin menüüst **Pluginad**
3. Ava **WooCommerce → Seaded → Smart WP Integrations**
4. Lisa litsentsivõti ja krüptovõti (saad need vaheserveri litsentsileheküljelt)
5. Seadista Merit Aktiva või Simplebooks API võtmed vaheserveris: **Litsentsid → Seadista**
6. Lülita soovitud integratsioon sisse ja vali tellimuse staatus, millal arve luuakse

== Frequently Asked Questions ==

= Kust saan litsentsi- ja krüptovõtme? =

Loo konto aadressil https://wp-liides.freelancermartin.ee, vali toode (Merit Aktiva moodul, Simplebooks moodul vms) ja tee makse. Pärast makse kinnitust kuvatakse litsentsivõti ja krüptovõti litsentsileheküljel.

= Kas plugin saadab andmeid otse Merit Aktiva serverisse? =

Ei. Kõik andmed liiguvad läbi turvalise vaheserveri, mis valideerib litsentsi ja krüpteerib ühenduse. Plugin ise ei ühenda kunagi Merit Aktiva ega Simplebooks API-ga otse.

= Mis juhtub kui arve saatmine ebaõnnestub? =

Plugin märgib tellimuse uuesti saatmiseks (retry). Kordussaatmist proobitakse kuni 3 korda tunni tagant. Kui kõik katsed ebaõnnestuvad, saadetakse (valikul) e-mail administraatorile. Kõik saatmiskatsed on nähtavad **Tööriistad → Saatmise ajalugu** all.

= Kas "Korduv arve number" viga on tõsine? =

Ei – see tähendab et arve on raamatupidamissüsteemis juba olemas. Plugin tuvastab selle automaatselt ja märgib tellimuse saadetuna ilma uuesti proovimata.

= Kuidas toimib maksude kaardistus? =

Merit Aktiva → **Maksude kaardistus** paneelis saad igale WooCommerce käibemaksumäärale (nt 20%, 9%, 0%) määrata Merit Aktiva VAT UUID. Kui kaardistus puudub, kasutatakse üldseadetes valitud vaikimisi maksumäära.

= Toetab plugin WooCommerce HPOS-i? =

Jah, täielik HPOS (High Performance Order Storage) tugi alates v1.0.0. Plugin kasutab `$order->get_meta()` / `$order->update_meta_data()` API-t.

= Kuidas eksportida seadeid ühelt saidilt teisele? =

Ava **Tööriistad** paneel ja klõpsa **Ekspordi seaded** – saad JSON faili. Teisel saidil ava sama paneel ja kasuta **Impordi seaded**.

== Screenshots ==

1. Merit Aktiva üldseaded – litsentsi seadistus, arve eesliide, maksetähtaeg
2. Maksude kaardistus – WooCommerce maksumäär → Merit Aktiva VAT UUID
3. Tarneviiside kaardistus – tarnemeetod → raamatupidamise artikkel
4. Sünkroniseerimise kontroll – võrdleb WooCommerce tellimusi Merit arvetega
5. Saatmise ajalugu – viimased 50 saatmist leheküljestusega
6. Tööriistad – seadete eksport/import

== Changelog ==

= 1.0.0 =
* Esialgne väljalase
* Merit Aktiva integratsioon: automaatne arve loomine, retry loogika, sünk kontroll
* Simplebooks integratsioon: automaatne arve ja kliendi loomine
* AES-256-GCM krüpteering kõikide andmete edastamisel
* HPOS tugi (wp_wc_orders_meta)
* Maksude kaardistus UUID-põhiselt
* Tarneviiside kaardistus
* Kategooria → osakond kaardistus (Merit Aktiva)
* Saatmise ajalugu koos leheküljestusega
* Seadete eksport/import
* E-maili teavitused ebaõnnestunud saatmistel

== Upgrade Notice ==

= 1.0.0 =
Esimene stabiilne väljalase. HPOS-ühilduvus tagatud.
