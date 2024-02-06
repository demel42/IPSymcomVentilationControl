[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Das Modul beschäftigt sich mit der Überwachung der Lüftung von Räumen in verschiedenen Aspekten

- Absenkung der Temperatur<br>
  Beim Lüften sollte man die Temperatur absenken um den Energieverlust zu begrenzen. Hierzu kann man Sensoren am Fenster benutzen um die Öffnung zu überwachen.
  Homamatic bietet zwar die Möglichkeit, die Fenstersensoren mit den Thermostaten zu verknüpfen, um eine solche Absenkung automatisch durchzuführen, das stösst
  aber auf einige Unschönheiten, z.B. die Absenkung erfolgt sofort, wenn der Sensor ein offenens Fenster meldet - bedeutet, das ggfs. das Ventil ständig auf
  und zu geht.
  Die Konfiguration erfolgt in Homematic  dezentral in den jeweiligen Aktoren und auch nicht so einfach beeinflussbar.
  Zudem wird's kompliziert, wenn man mehr als ein Fenster in einem Raum hat bzw. mehr als einen Themostaten.
  Geht alles, wollte ich aber lieber im IPS zusammen steuern.
  Ich weis auch nicht, ob alle diversen Steuersysteme eine solche Verknüfung ermöglichen; geht aber sicherlich eher nicht, wenn die beteiligten Komponenten
  nicht "aus einem Guß" sind.

- (wiederholte) Meldung, wenn lang genug gelüftet wurde<br>

- Luftfeuchtigkeit<br>
  ein Lüften zur Verringerung der Luftfeuchtigkeit macht ja nur Sinn, wenn die Luftfeuchtigkeit draussen um einiges kliner ist als drinnen. 
  Genauer gesagt, geht es nicht um die relative Liuftfeuchtigkeit sondern um die absolute (in g/m³ Luft) bzw. spezifische ( in g/kg Luft) Feuchte.
  Wenn z.B. Aussen 30°C bei 80 % Feuchte und Innen 23°C bei 65 % Feuchte sind, ist der Wassergehalt in der Luft Aussen 21.1 g/kg und Innen 11.3 g/kg
  -> ein Lüften würde also die Leuchtigkeit innen erhöhen.
  Diese Berechnung mach das Modul und stellt das Ergebnis (mit einer gewissen Hysterese) als Variable zur Verfügung.
  Mit Hilfe dieser Variable kann man z.B. eine automatische Lüftung steuern.

- Warnung vor dem Risiko von Schimmelbildung<br>
  ein Thema, das spannend wird, wenn man damit ein Problem hat, ist die Gefahr der Schimmelbildung. Das Problem ist nicht nur die Luftfeuchte im
  Innenraum sondern die Temperatur der Wand… an der sich das Wasser ggfs, niederschlägt. Da man die Temperatur der Wand ja nicht permanent überwachen
  kann, kann man näherungsweise den Wärmeverlust der Aussenwand (sog. "Gesamtwärmeübergangswiderstand") heranziehen, um die vermutliche Wandtemperatur
  zu berechnen.
  <br>... hier kommt noch ein bisschen Theorie ...<br>
  Dieser Teil ist noch experimentell und muss noch weiter verprüft werden!

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul
unter dem Suchbegriff *Lüftungsüberwachung* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/)
unter Angabe der URL `https://github.com/demel42/IPSymconVentilationMonitoring.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

alle Funktionen sind über _RequestAction_ der jeweiligen Variablen ansteuerbar

## 5. Konfiguration

### VentilationMonitoring

#### Properties

| Eigenschaft                       | Typ      | Standardwert | Beschreibung |
| :-------------------------------- | :------  | :----------- | :----------- |
| Instanz deaktivieren              | boolean  | false        | Instanz temporär deaktivieren |
|                                   |          |              | |
| Erkennung eines offene Fensters   |          |              | Bedingungen um zu erkennen, ob min. ein Fenster offen ist |
| Erkennung eines gekippte Fensters |          |              | Bedingungen um zu erkennen, ob min. ein Fenster gekippt ist |
|                                   |          |              | |
| Temperaturabsenkung               |          |              | |
| ... Variable zur Kontrolle        | boolean  | false        | erzeugt eine zusätzliche Variable, mit der die Überwachung temporär deaktiviert werden kann |
| ... Start-Verzögerung             |          |              | optionale Angabe einer Dauer,bis die Temperatur-Absenkung aktiviert wird |
| ... Absenkungsmodus               | integer  | 0            | 0=Temperatur setzen, 1=Auslöser setzen, 2=Script aufrufen |
|     ... Zielwert/Variable         | float    | 12           | Zielwert, an den abgesenkt werden soll |
|     ... Auslöser-Wert             | integer  | 1            | Wert, der der Trigger annehmen soll |
|     ... Script                    | integer  | 0            | aufzurufendes Script |
| ... Dauer bis Benachrichtigung    |          |              | Angabe der Dauer bis zur ersten Benachrichtigung |
|     ... Temperatur                | float    |              | ermöglicht je nach Temperatur unterschiedliche Zeiträume zu definieren |
|     ... Komplexe Bedingungen      |          |              | ... und in komplex |
|     ... Dauer bei "offen"         | integer  |              | Lüftungsdauer bei offenen Fenstern |
|     ... Dauer bei "gekippt"       | integer  |              | Lüftungsdauer bei gekippten Fenstern
|                                   |          |              | |
| Benachrichtigung                  |          |              | |
| ... Script                        | string   |              | Script zum Aufruf einer Benachrichtigung |
| ... Pause                         | integer  |              | Pause bis zur erneuten Benachrichtigung |
| ... max Wiederholung              | integer  |              | Maximale Anzahl von Wiederholungen der Benachrichtigungen (-1=unendlich) |
|                                   |          |              | |
| Luftfeuchtigkeit                  |          |              | |
| ... Lüften möglich                | boolean  |              | Lüften ist sinnvoll möglich, wenn die Feuchte drinnen > draussen |
| ... Schimmelbildung               | boolean  |              | Hinweis auf die Gefahr einer Schimmelbildung |
|     ... Gesamtwärmeübergang...    | float    |              | Wert für den Wärmeverlust einer Aussenwand |
|     ... min. Feuchte              | float    |              | minimale Luftfeuchte, bei der Schimmel entstehen könnte |
|                                   |          |              | |
| Messwerte                         |          |              | verschiedenen Messwerte (Temperatur, Luftfeuchte), die je nach genutzer Funktion benötigt werden |
| ... Variablen ... anlegen         | boolean  |              | man kann für die berechneten Werte Variablen anlegen lassen, man kann diese aber auch im "Exterten-Bereich" einsehen |

- Benachrichtigungs-Script
Dem Script werden Parameter übergeben, die als Bestandteil von *_IPS* enthalten sind

| Name         | Typ      | Beschreibung |
| :----------- | :------  | :----------- |
| instanceID   | integer  | ID der aufrufenden Instanz
|              |          | |
| reason       | string   | Grund der Benachrichtigung (_ReduceHumidityImpossible_, _Duration_) |
|              |          | |
| ClosureState | string   | Öffnungsstatus (gemäß *VentilationMonitoring.ClosureState*) |
|              |          | |
| TriggerTime  | integer  | Zeitpunkt der Erkennung des Öffnungsstatus |
| duration     | string   | Dauer ab Öffnung in lesbarer Form |
|              |          | |
| targets      | string   | Komma-separierte Liste der Ziele der Temperaturabsenkung |
|              |          | |
| *            |          | die diversen gemessenen oder berechneten Werte (siehe *Experten-Bereich*) |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |
| Bedingungen prüfen         | |
|                            | |
| Wärmewiderstand berechnen  | |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
  VentilationMonitoring.ReduceHumidityPossible,
  VentilationMonitoring.YesNo

* Integer<br>
  VentilationMonitoring.ClosureState,
  VentilationMonitoring.RiskOfMold

* Float<br>
  VentilationMonitoring.AbsoluteHumidity,
  VentilationMonitoring.Dewpoint,
  VentilationMonitoring.SpecificHumidity

## 6. Anhang

### GUIDs
- Modul: `{5D307E4A-BA97-8249-2798-31FA6110509A}`
- Instanzen:
  - VentilationMonitoring: `{B5B6C453-45CB-660B-5F1F-A9E58277D649}`
- Nachrichten:

### Quellen
   - https://smart-wohnen.org/homematic-raumklimaueberwachung-und-entfeuchtung
   - https://homematic-forum.de/forum/viewtopic.php?f=26&t=45178
   - https://homematic-forum.de/forum/viewtopic.php?f=43&t=9835
   - https://www.wetterochs.de/wetter/feuchte.html
   - https://www.geo.fu-berlin.de/met/service/wetterdaten/luftfeuchtigkeit.html
   - https://www.cactus2000.de/de/unit/masshum.shtml

## 7. Versions-Historie

- 1.6 @ 06.02.2024 09:46
  - Verbesserung: Angleichung interner Bibliotheken anlässlich IPS 7
  - update submodule CommonStubs

- 1.5 @ 03.11.2023 11:06
  - Neu: Ermittlung von Speicherbedarf und Laufzeit (aktuell und für 31 Tage) und Anzeige im Panel "Information"
  - update submodule CommonStubs

- 1.4 @ 06.07.2023 09:41
  - Neu: Angabe der Aussentemperatur bis zu der geheizt wird (also eine Absenkung Sinn macht)
  - Vorbereitung auf IPS 7 / PHP 8.2
  - update submodule CommonStubs
    - Absicherung bei Zugriff auf Objekte und Inhalte

- 1.3 @ 20.11.2022 15:51
  - Fix: die erste Wartezeit bis zur Meldung wird um die Verzögerung verkürzt
  - Neu: bei aktivierter "Lüften möglich"-Prüfung wird eine Benachrichtigung ausgelöst
  - update submodule CommonStubs

- 1.2.1 @ 18.10.2022 15:12
  - Fix: Korrektur zu 1.2 (VM_UPDATE-Handling)

- 1.2 @ 18.10.2022 11:37
  - Neu: optionale Variable zur temporären Unterdrückung von Benachrichtigungen
  - Fix: Verbesserung in MessageSink() um VM_UPDATE-Meldungen zu vermeiden 
  - update submodule CommonStubs

- 1.1 @ 08.10.2022 14:48
  - Neu: Absicherung des Zugriffs via Semaphore

- 1.0.4 @ 07.10.2022 13:31
  - update submodule CommonStubs
    Fix: Update-Prüfung wieder funktionsfähig

- 1.0.3 @ 27.09.2022 11:56
  - Neu: Einstellung zum Verhalten, falls die eingestellte Temperatur in der Absenkphase geändert wird

- 1.0.2 @ 19.09.2022 17:45
  - Neu: max. Anzahl von Wiederholung der Benachrichgtigung

- 1.0.1 @ 19.09.2022 10:10
  - Neu: Übergabe-Paramater an das "notice_script" erweitert und dokumentiert

- 1.0 @ 17.09.2022 11:57
  - Initiale Version
