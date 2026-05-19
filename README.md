# ThermostatTile – IP-Symcon Modul

Interaktive Thermostat-Kachel für **Advanced Heating Control (AHC)** im IP-Symcon WebFront.  
Alle AHC-Variablen werden direkt im Konfigurationsformular der Instanz zugewiesen.

---

## Installation

1. **Module-Store** öffnen → „Modul-URL hinzufügen"
2. URL eingeben: `https://github.com/shthg24/ThermostatTile`
3. Modul installieren
4. Neue **ThermostatTile**-Instanz anlegen
5. Im Konfigurationsformular die AHC-Variablen zuweisen

## WebFront-Einbindung

- Im WebFront-Editor eine **HTML-Box**-Instanz anlegen
- Als Quelle die Variable `HTML` der ThermostatTile-Instanz wählen
- Breite auf ca. 320 px einstellen

## Konfigurierbare Variablen

| Kategorie | Variablen |
|---|---|
| Temperaturen | Soll (Float), Ist (Float), Min/Max/Schritt |
| Modus | Integer + frei konfigurierbare Modus-Tabelle |
| Fenster/Sperre | Fensterkontakt (Bool), Kindersicherung (Bool) |
| Luftqualität | Luftfeuchtigkeit (Float), CO₂ (Float/Int) |
| Ventil/Aktor | Ventilstellung (Float), Aktor-Status (Bool) |
| Boost | Aktiv (Bool), verbleibende Zeit (Int, Minuten) |
| Präsenz | Anwesenheit (Bool), Absenkwert (Float) |
| Wochenplan | Aktiv (Bool), nächster Zeitpunkt (Int, Timestamp), Zieltemperatur (Float) |
| Außentemperatur | Außentemperatur (Float) |

## Kommunikation

Die Kachel kommuniziert bidirektional über einen Webhook:

- **GET** `/hook/thermostat_<InstanceID>` → aktueller Zustand als JSON
- **POST** `{"action":"setTemp","value":21.5}` → Solltemperatur setzen
- **POST** `{"action":"setMode","value":1}` → Betriebsmodus setzen

Alle Schreibzugriffe erfolgen via `RequestAction()`.

## Voraussetzungen

- IP-Symcon ≥ 6.0
- WebHook Control aktiv
- Advanced Heating Control installiert und konfiguriert

## Lizenz

MIT
