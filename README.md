# 🌡️ ThermostatTile – IP-Symcon Visualisierungsmodul

Schöne, interaktive Thermostat-Kachel für das **Advanced Heating Control (AHC)**-Modul in IP-Symcon.  
Alle Variablen werden direkt im Konfigurationsformular der Instanz zugewiesen – kein Script-Schreiben nötig.

\---

## ✨ Funktionen

|Feature|Details|
|-|-|
|🌡️ Temperaturen|Soll- und Isttemperatur mit animiertem Kreisbogen-Dial|
|+/− Steuerung|Schrittweiteneinstellung, schreibt via `RequestAction` zurück|
|🔥 Modus-Umschaltung|Frei konfigurierbare Modi (Heizen/Kühlen/Eco/Boost/Aus)|
|🎨 Farbwechsel|Kachel wechselt automatisch Farbe je nach Modus|
|🪟 Fensterkontakt|Zeigt Warnung, sperrt Temperaturänderung bei offenem Fenster|
|🔒 Kindersicherung|Sperre via Boolean-Variable|
|💧 Luftfeuchtigkeit|Optional einblendbar|
|🌬️ CO₂|Optional mit Farbwarnung ab 1000 ppm|
|🔧 Ventilstellung|Prozentanzeige des Stellantriebs|
|⚡ Aktor-Status|Pulsierender Punkt wenn Heizung aktiv|
|🚀 Boost|Verbleibende Zeit in Minuten|
|🏃 Präsenz|Absenkungsanzeige bei Abwesenheit|
|📅 Wochenplan|Nächster Schaltzeitpunkt und Zieltemperatur|
|🌤️ Außentemperatur|Optional in Fußzeile|
|🔄 Live-Updates|Webhook-basiert, kein Neuladen der Seite nötig|

\---

## 📦 Installation über Module-Store

1. **Module-Store** in der IP-Symcon Verwaltungskonsole öffnen.
2. Oben rechts auf **„Modul-URL hinzufügen"** klicken.
3. URL eingeben:

```
   https://github.com/shtg24/ThermostatTile
   ```

4. Bestätigen – das Modul erscheint in der Liste.
5. Installieren und eine neue **ThermostatTile**-Instanz anlegen.

\---

## ⚙️ Konfiguration

Nach dem Anlegen der Instanz öffnet sich das Konfigurationsformular mit folgenden Abschnitten:

### 🌡️ Temperaturen

|Eigenschaft|Beschreibung|
|-|-|
|Solltemperatur|Variable mit dem AHC-Sollwert (Float)|
|Isttemperatur|Raumfühler-Variable (Float)|
|Minimum / Maximum|Grenzen für das Dial (z.B. 5 – 30 °C)|
|Schrittweite|Schrittgröße der +/− Tasten (z.B. 0,5 °C)|

### 🔥 Heizung / Modus

|Eigenschaft|Beschreibung|
|-|-|
|Betriebsmodus|Integer-Variable mit dem aktuellen Modus|
|Modus-Zuordnung|Tabelle: Wert → Bezeichnung + Farbe|
|Steuerung erlauben|Ob Modus und Temperatur über die Kachel änderbar sind|

### 🪟 Fenster / Sperren

Boolean-Variablen für Fensterkontakt und Kindersicherung.

### 💧 Luftqualität

Luftfeuchtigkeit (%) und CO₂ (ppm) – beide optional.

### 🔧 Ventil / Aktor

Ventilstellung (Float, %) und Aktor-Schaltstatus (Boolean).

### ⏱️ Boost / Abwesenheit

Boost-Status, verbleibende Boost-Zeit, Präsenzvariable und Absenkwert.

### 📅 Wochenplan

Nächster Schaltzeitpunkt (Unix-Timestamp) und zugehörige Temperatur.

### 🌤️ Außentemperatur

Optionale Außentemperaturvariable.

### 🎨 Darstellung

Raumname, Untertitel und Aktualisierungsintervall in Sekunden.

\---

## 🖥️ WebFront-Einbindung

1. Im WebFront-Editor eine **HTML-Box**-Instanz anlegen.
2. Als Quelle die Variable `HTML` der ThermostatTile-Instanz wählen.
3. Breite auf ca. 320 px einstellen.

> \*\*Alternativ:\*\* Die Kachel kann auch direkt als Webhook aufgerufen werden:  
> `http://IPSYMCON-IP:3777/hook/thermostat\_<InstanceID>`

\---

## 🔄 Kommunikation

Die Kachel kommuniziert bidirektional über einen automatisch registrierten **Webhook**:

* **GET** `/hook/thermostat\_<ID>` → liefert aktuellen Zustand als JSON
* **POST** mit `{"action":"setTemp","value":21.5}` → schreibt Solltemperatur
* **POST** mit `{"action":"setMode","value":1}` → setzt Betriebsmodus

Alle Schreibzugriffe erfolgen via `RequestAction()` – die Ziel-Variable (z.B. AHC-Sollwert) wird korrekt über das AHC-Modul weitergegeben.

\---

## 📋 Voraussetzungen

* IP-Symcon ≥ 6.0
* WebHook Control Instanz aktiv (Standard-Installation)
* Advanced Heating Control Modul installiert und konfiguriert
* PHP 8.x (Standard in IPS ≥ 6.x)

\---

## 🐛 Support \& Issues

[GitHub Issues](https://github.com/DEIN_USERNAME/ThermostatTile/issues) · [Symcon Community](https://community.symcon.de)

\---

## 📄 Lizenz

MIT License – frei verwendbar und anpassbar.

