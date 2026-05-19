<?php

/**
 * ThermostatTile – IP-Symcon Visualisierungsmodul
 *
 * Zeigt eine schöne Thermostat-Kachel für Advanced Heating Control (AHC)
 * im WebFront an. Alle Variablen werden über das Konfigurationsformular
 * zugewiesen und live per Webhook aktualisiert.
 *
 * Kompatibel mit IP-Symcon ≥ 6.x
 */

declare(strict_types=1);

class ThermostatTile extends IPSModule
{
    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        // Temperaturen
        $this->RegisterPropertyInteger('TargetTemperatureID', 0);
        $this->RegisterPropertyInteger('ActualTemperatureID', 0);
        $this->RegisterPropertyFloat('TempMin', 5.0);
        $this->RegisterPropertyFloat('TempMax', 30.0);
        $this->RegisterPropertyFloat('TempStep', 0.5);

        // Modus
        $this->RegisterPropertyInteger('HeatingModeID', 0);
        $this->RegisterPropertyString('ModeMapping', json_encode([
            ['Value' => 0, 'Label' => 'Aus',    'Color' => 'off'],
            ['Value' => 1, 'Label' => 'Heizen', 'Color' => 'heat'],
            ['Value' => 2, 'Label' => 'Kühlen', 'Color' => 'cold'],
            ['Value' => 3, 'Label' => 'Eco',    'Color' => 'eco'],
            ['Value' => 4, 'Label' => 'Boost',  'Color' => 'heat'],
        ]));
        $this->RegisterPropertyBoolean('AllowModeChange', true);
        $this->RegisterPropertyBoolean('AllowTempChange', true);

        // Fenster / Sperre
        $this->RegisterPropertyInteger('WindowOpenID', 0);
        $this->RegisterPropertyInteger('LockID', 0);

        // Luftqualität
        $this->RegisterPropertyInteger('HumidityID', 0);
        $this->RegisterPropertyInteger('CO2ID', 0);

        // Ventil / Aktor
        $this->RegisterPropertyInteger('ValvePositionID', 0);
        $this->RegisterPropertyInteger('ActuatorStateID', 0);

        // Boost / Präsenz
        $this->RegisterPropertyInteger('BoostActiveID', 0);
        $this->RegisterPropertyInteger('BoostDurationID', 0);
        $this->RegisterPropertyInteger('PresenceID', 0);
        $this->RegisterPropertyInteger('AbsenceOffsetID', 0);

        // Wochenplan
        $this->RegisterPropertyInteger('ScheduleActiveID', 0);
        $this->RegisterPropertyInteger('NextSwitchTimeID', 0);
        $this->RegisterPropertyInteger('NextSwitchTempID', 0);

        // Außentemperatur
        $this->RegisterPropertyInteger('OutdoorTempID', 0);

        // Darstellung
        $this->RegisterPropertyString('RoomName', 'Raumname');
        $this->RegisterPropertyString('RoomSubtitle', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        // Timer für periodische Aktualisierung
        $this->RegisterTimer('UpdateTimer', 0, 'THERMO_Update($_IPS["TARGET"]);');

        // HTML-Ausgabevariable fuer HTML-Box
        $this->MaintainVariable('HTML', 'Thermostat HTML', 3, '~HTMLBox', 0, true);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Alle konfigurierten Variablen beobachten
        $this->UnregisterAllMessages();
        $watchProps = [
            'TargetTemperatureID', 'ActualTemperatureID', 'HeatingModeID',
            'WindowOpenID', 'LockID', 'HumidityID', 'CO2ID',
            'ValvePositionID', 'ActuatorStateID', 'BoostActiveID',
            'BoostDurationID', 'PresenceID', 'AbsenceOffsetID',
            'ScheduleActiveID', 'NextSwitchTimeID', 'NextSwitchTempID',
            'OutdoorTempID',
        ];
        foreach ($watchProps as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Webhook registrieren: /hook/thermostat_<InstanceID>
        $this->RegisterHook('/hook/thermostat_' . $this->InstanceID);

        // Update-Timer
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);

        $this->Update();
    }

    // ─── Nachrichten (Variable geändert) ─────────────────────────────────────

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->Update();
        }
    }

    // ─── Öffentliche Funktionen ───────────────────────────────────────────────

    /**
     * Wird vom Timer und von MessageSink aufgerufen.
     * Aktualisiert die HTML-Ausgabe der Instanz.
     */
    public function Update(): void
    {
        $data = $this->CollectData();
        $html = $this->RenderHTML($data);
        $this->SetValue('HTML', $html);        // für HTML-Box Modul
    }

    /**
     * Webhook-Handler: liefert JSON-Daten oder verarbeitet POST-Kommandos.
     */
    protected function ProcessHookData(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $body = file_get_contents('php://input');
            $cmd  = json_decode($body, true);
            if (is_array($cmd)) {
                $this->HandleCommand($cmd);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            return;
        }

        // GET → JSON für die Kachel
        header('Content-Type: application/json');
        echo json_encode($this->CollectData());
    }

    // ─── Kommando-Verarbeitung ────────────────────────────────────────────────

    private function HandleCommand(array $cmd): void
    {
        $action = $cmd['action'] ?? '';

        if ($action === 'setTemp' && $this->ReadPropertyBoolean('AllowTempChange')) {
            $id = $this->ReadPropertyInteger('TargetTemperatureID');
            if ($id > 0 && IPS_VariableExists($id)) {
                $val = (float) ($cmd['value'] ?? 20.0);
                $val = max($this->ReadPropertyFloat('TempMin'),
                       min($this->ReadPropertyFloat('TempMax'), $val));
                RequestAction($id, $val);
            }
        }

        if ($action === 'setMode' && $this->ReadPropertyBoolean('AllowModeChange')) {
            $id = $this->ReadPropertyInteger('HeatingModeID');
            if ($id > 0 && IPS_VariableExists($id)) {
                RequestAction($id, (int) ($cmd['value'] ?? 0));
            }
        }

        $this->Update();
    }

    // ─── Daten sammeln ────────────────────────────────────────────────────────

    private function CollectData(): array
    {
        $g = function (string $prop, $default = null) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                return GetValue($id);
            }
            return $default;
        };

        $modeRaw    = $g('HeatingModeID', 0);
        $modeMap    = json_decode($this->ReadPropertyString('ModeMapping'), true) ?? [];
        $modeEntry  = array_values(array_filter($modeMap, fn($m) => $m['Value'] == $modeRaw));
        $modeLabel  = $modeEntry[0]['Label'] ?? 'Unbekannt';
        $modeColor  = $modeEntry[0]['Color'] ?? 'off';

        // Nächster Schaltzeitpunkt als lesbarer String
        $nextTs     = $g('NextSwitchTimeID', 0);
        $nextLabel  = '';
        if ($nextTs > 0) {
            $diff = $nextTs - time();
            if ($diff > 0 && $diff < 86400) {
                $nextLabel = date('H:i', $nextTs) . ' Uhr';
            } elseif ($diff >= 86400) {
                $nextLabel = date('D H:i', $nextTs);
            }
        }

        // Modus-Liste für die Buttons
        $modeList = array_map(fn($m) => [
            'value' => $m['Value'],
            'label' => $m['Label'],
            'color' => $m['Color'],
        ], $modeMap);

        return [
            'roomName'         => $this->ReadPropertyString('RoomName'),
            'roomSubtitle'     => $this->ReadPropertyString('RoomSubtitle'),
            'targetTemp'       => round((float) $g('TargetTemperatureID', 20.0), 1),
            'actualTemp'       => round((float) $g('ActualTemperatureID', 20.0), 1),
            'tempMin'          => $this->ReadPropertyFloat('TempMin'),
            'tempMax'          => $this->ReadPropertyFloat('TempMax'),
            'tempStep'         => $this->ReadPropertyFloat('TempStep'),
            'modeValue'        => (int) $modeRaw,
            'modeLabel'        => $modeLabel,
            'modeColor'        => $modeColor,
            'modeList'         => $modeList,
            'allowTempChange'  => $this->ReadPropertyBoolean('AllowTempChange'),
            'allowModeChange'  => $this->ReadPropertyBoolean('AllowModeChange'),
            'windowOpen'       => (bool) $g('WindowOpenID', false),
            'locked'           => (bool) $g('LockID', false),
            'humidity'         => $g('HumidityID') !== null ? round((float) $g('HumidityID'), 0) : null,
            'co2'              => $g('CO2ID') !== null ? (int) $g('CO2ID') : null,
            'valve'            => $g('ValvePositionID') !== null ? round((float) $g('ValvePositionID'), 0) : null,
            'actuatorActive'   => (bool) $g('ActuatorStateID', false),
            'boostActive'      => (bool) $g('BoostActiveID', false),
            'boostMinutes'     => $g('BoostDurationID') !== null ? (int) $g('BoostDurationID') : null,
            'presence'         => (bool) $g('PresenceID', true),
            'absenceOffset'    => $g('AbsenceOffsetID') !== null ? (float) $g('AbsenceOffsetID') : null,
            'scheduleActive'   => (bool) $g('ScheduleActiveID', false),
            'nextSwitchLabel'  => $nextLabel,
            'nextSwitchTemp'   => $g('NextSwitchTempID') !== null ? round((float) $g('NextSwitchTempID'), 1) : null,
            'outdoorTemp'      => $g('OutdoorTempID') !== null ? round((float) $g('OutdoorTempID'), 1) : null,
            'hookUrl'          => '/hook/thermostat_' . $this->InstanceID,
            'timestamp'        => time(),
        ];
    }

    // ─── HTML rendern ─────────────────────────────────────────────────────────

    private function RenderHTML(array $d): string
    {
        $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #0f1117; --surface: #181c27; --rim: #232843;
  --accent: #e8784a; --accent2: #f0a86c; --cold: #5ba4cf;
  --eco: #50b478; --text: #e8e4dd; --muted: #6b7080;
  --heat-glow: rgba(232,120,74,.18); --cold-glow: rgba(91,164,207,.15);
  --eco-glow: rgba(80,180,120,.14); --radius: 20px;
  font-family: 'DM Mono', monospace;
}
body { background: var(--bg); display: flex; justify-content: center;
       align-items: flex-start; padding: 20px; min-height: 100vh; }
.card { width: 300px; background: var(--surface); border: 1px solid var(--rim);
        border-radius: var(--radius); padding: 24px 22px 20px; position: relative;
        overflow: hidden; transition: box-shadow .4s; user-select: none; }
.card::before { content: ''; position: absolute; inset: 0; border-radius: var(--radius);
  background: radial-gradient(ellipse at 50% -10%, var(--heat-glow), transparent 65%);
  pointer-events: none; transition: background .6s; }
.card.cold::before  { background: radial-gradient(ellipse at 50% -10%, var(--cold-glow), transparent 65%); }
.card.eco::before   { background: radial-gradient(ellipse at 50% -10%, var(--eco-glow),  transparent 65%); }
.card.off::before   { background: none; }
.card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; gap: 8px; }
.room-name { font-family: 'DM Serif Display', serif; font-size: 1.3rem; color: var(--text); line-height: 1.15; }
.room-sub  { font-size: .6rem; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-top: 3px; }
.badges { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.mode-badge { font-size: .58rem; letter-spacing: .08em; text-transform: uppercase;
  padding: 3px 8px; border-radius: 30px; border: 1px solid currentColor; white-space: nowrap; transition: color .4s; }
.mode-badge.heat { color: var(--accent); }
.mode-badge.cold { color: var(--cold); }
.mode-badge.eco  { color: var(--eco); }
.mode-badge.off  { color: var(--muted); }
.icon-badge { font-size: .7rem; }
.dial-wrap { position: relative; width: 176px; height: 176px; margin: 0 auto 18px; }
.dial-svg  { width: 100%; height: 100%; transform: rotate(-135deg); }
.track    { fill: none; stroke: var(--rim); stroke-width: 10; stroke-linecap: round; }
.progress { fill: none; stroke-width: 10; stroke-linecap: round; stroke: var(--accent);
  transition: stroke-dashoffset .5s cubic-bezier(.4,0,.2,1), stroke .4s; }
.dial-center { position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 1px; }
.temp-set   { font-size: 2.5rem; font-weight: 500; color: var(--text); line-height: 1;
  letter-spacing: -.03em; transition: color .4s; cursor: default; }
.temp-unit  { font-size: .72rem; color: var(--muted); letter-spacing: .06em; margin-top: 2px; }
.temp-actual-label { font-size: .56rem; color: var(--muted); letter-spacing: .06em;
  text-transform: uppercase; margin-top: 6px; }
.temp-actual { font-size: .84rem; color: var(--accent); transition: color .4s; }
.dial-btn { position: absolute; top: 50%; transform: translateY(-50%);
  width: 30px; height: 30px; border-radius: 50%; background: var(--rim); border: none;
  color: var(--text); font-size: 1.2rem; cursor: pointer; display: flex;
  align-items: center; justify-content: center; transition: background .2s, transform .1s; }
.dial-btn:hover  { background: #2e3450; }
.dial-btn:active { transform: translateY(-50%) scale(.9); }
.dial-btn.minus  { left: -14px; }
.dial-btn.plus   { right: -14px; }
.dial-btn:disabled { opacity: .3; cursor: not-allowed; }
.info-row  { display: flex; justify-content: space-between; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
.info-chip { flex: 1; min-width: 72px; background: rgba(255,255,255,.03);
  border: 1px solid var(--rim); border-radius: 12px; padding: 7px 8px; text-align: center; }
.chip-label { font-size: .52rem; letter-spacing: .09em; text-transform: uppercase;
  color: var(--muted); margin-bottom: 3px; }
.chip-val   { font-size: .85rem; color: var(--text); }
.chip-val.accent { color: var(--accent); }
.chip-val.cold   { color: var(--cold); }
.chip-val.eco    { color: var(--eco); }
.chip-val.warn   { color: #e8c94a; }
.chip-val.muted  { color: var(--muted); }
.mode-row { display: flex; gap: 6px; margin-bottom: 14px; }
.mode-btn { flex: 1; background: rgba(255,255,255,.03); border: 1px solid var(--rim);
  border-radius: 10px; color: var(--muted); font-family: 'DM Mono', monospace;
  font-size: .58rem; letter-spacing: .07em; text-transform: uppercase;
  padding: 8px 4px; cursor: pointer; transition: background .2s, color .2s, border-color .2s;
  display: flex; flex-direction: column; align-items: center; gap: 3px; }
.mode-btn:hover { background: var(--rim); color: var(--text); }
.mode-btn:disabled { opacity: .3; cursor: not-allowed; }
.mode-btn.active-heat { background: rgba(232,120,74,.12); border-color: var(--accent); color: var(--accent); }
.mode-btn.active-cold { background: rgba(91,164,207,.12);  border-color: var(--cold);   color: var(--cold); }
.mode-btn.active-eco  { background: rgba(80,180,120,.12);  border-color: var(--eco);    color: var(--eco); }
.mode-btn.active-off  { background: rgba(255,255,255,.05); border-color: var(--muted);  color: var(--text); }
.footer { display: flex; align-items: center; margin-top: 4px;
  font-size: .56rem; color: var(--muted); letter-spacing: .04em; gap: 5px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
  background: var(--accent); box-shadow: 0 0 6px var(--accent); animation: pulse 2s ease-in-out infinite; }
.status-dot.idle   { background: var(--muted); box-shadow: none; animation: none; }
.status-dot.cold   { background: var(--cold);  box-shadow: 0 0 6px var(--cold); }
.status-dot.eco    { background: var(--eco);   box-shadow: 0 0 6px var(--eco); }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.35} }
.outdoor-bar { display: flex; justify-content: space-between; align-items: center;
  border-top: 1px solid var(--rim); margin-top: 12px; padding-top: 10px;
  font-size: .6rem; color: var(--muted); }
.outdoor-val { font-size: .82rem; color: var(--text); }
</style>
</head>
<body>
<div class="card" id="thermo-card">
  <div class="card-header">
    <div>
      <div class="room-name" id="roomName"></div>
      <div class="room-sub"  id="roomSub"></div>
    </div>
    <div class="badges">
      <div class="mode-badge" id="modeBadge"></div>
      <div class="icon-badge" id="iconBadge"></div>
    </div>
  </div>

  <div class="dial-wrap">
    <svg class="dial-svg" viewBox="0 0 176 176">
      <circle class="track" cx="88" cy="88" r="76" stroke-dasharray="358.14" stroke-dashoffset="89.5"/>
      <circle class="progress" id="dialProgress" cx="88" cy="88" r="76" stroke-dasharray="358.14" stroke-dashoffset="89.5"/>
    </svg>
    <div class="dial-center">
      <div class="temp-set"          id="tempSet">--</div>
      <div class="temp-unit">°C Soll</div>
      <div class="temp-actual-label">Ist-Temperatur</div>
      <div class="temp-actual"       id="tempActual">-- °C</div>
    </div>
    <button class="dial-btn minus" id="btnMinus" onclick="changeTemp(-1)">−</button>
    <button class="dial-btn plus"  id="btnPlus"  onclick="changeTemp(+1)">+</button>
  </div>

  <div class="info-row" id="infoRow"></div>
  <div class="mode-row" id="modeRow"></div>

  <div class="footer">
    <span class="status-dot" id="statusDot"></span>
    <span id="footerText">–</span>
  </div>
  <div class="outdoor-bar" id="outdoorBar" style="display:none">
    <span>Außentemperatur</span>
    <span class="outdoor-val" id="outdoorVal">–</span>
  </div>
</div>

<script>
const CFG = $json;
const DASH = 358.14, GAP = 89.5;

let state = Object.assign({}, CFG);

function tempToOffset(t) {
  const pct = (t - state.tempMin) / (state.tempMax - state.tempMin);
  return DASH - Math.max(0, Math.min(1, pct)) * DASH + GAP;
}

function modeIconSvg(color) {
  if (color === 'heat') return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/><path d="M8 14s1.5-2 4-2 4 2 4 2"/></svg>';
  if (color === 'cold') return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 7l-5 5-5-5"/><path d="M17 17l-5-5-5 5"/><line x1="2" y1="12" x2="22" y2="12"/></svg>';
  if (color === 'eco') return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22s4-2 6-6c2 4 8 6 14 4-2-6-8-10-14-8 0 0 2 4 0 10"/></svg>';
  return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="8" x2="16" y2="16"/></svg>';
}

function render() {
  const s = state;
  const card = document.getElementById('thermo-card');

  // Card class
  card.className = 'card ' + s.modeColor;

  // Header
  document.getElementById('roomName').textContent = s.roomName;
  document.getElementById('roomSub').textContent  = s.roomSubtitle;
  const badge = document.getElementById('modeBadge');
  badge.textContent  = s.modeLabel;
  badge.className    = 'mode-badge ' + s.modeColor;

  // Window / lock badges
  let icons = [];
  if (s.windowOpen) icons.push('🪟');
  if (s.locked)     icons.push('🔒');
  if (s.boostActive) icons.push('🚀');
  if (!s.presence)  icons.push('🏃');
  document.getElementById('iconBadge').textContent = icons.join(' ');

  // Dial
  document.getElementById('tempSet').textContent    = s.targetTemp.toFixed(1);
  document.getElementById('tempActual').textContent = s.actualTemp.toFixed(1) + ' °C';
  const prog = document.getElementById('dialProgress');
  prog.style.strokeDashoffset = tempToOffset(s.targetTemp);
  const strokeColor = { heat:'#e8784a', cold:'#5ba4cf', eco:'#50b478', off:'#6b7080' }[s.modeColor] || '#e8784a';
  prog.style.stroke = strokeColor;
  document.getElementById('tempActual').style.color = strokeColor;

  // Buttons
  const minus = document.getElementById('btnMinus');
  const plus  = document.getElementById('btnPlus');
  minus.disabled = !s.allowTempChange || s.locked || s.windowOpen;
  plus.disabled  = !s.allowTempChange || s.locked || s.windowOpen;

  // Info chips
  const infoRow = document.getElementById('infoRow');
  infoRow.innerHTML = '';
  if (s.humidity !== null) addChip(infoRow, 'Feuchte', s.humidity + ' %', '');
  if (s.valve !== null)    addChip(infoRow, 'Ventil',  s.valve + ' %',   s.modeColor === 'cold' ? 'cold' : 'accent');
  if (s.co2 !== null)      addChip(infoRow, 'CO₂',    s.co2 + ' ppm',   s.co2 > 1000 ? 'warn' : '');
  if (s.boostActive && s.boostMinutes !== null)
    addChip(infoRow, 'Boost', s.boostMinutes + ' min', 'accent');
  else if (s.nextSwitchLabel)
    addChip(infoRow, 'Nächste', s.nextSwitchLabel, 'muted');
  if (s.absenceOffset !== null && !s.presence)
    addChip(infoRow, 'Absenkung', '−' + s.absenceOffset + ' °C', 'eco');

  // Mode buttons
  const modeRow = document.getElementById('modeRow');
  modeRow.innerHTML = '';
  (s.modeList || []).forEach(m => {
    const btn = document.createElement('button');
    btn.className = 'mode-btn' + (m.value === s.modeValue ? ' active-' + m.color : '');
    btn.disabled  = !s.allowModeChange;
    btn.innerHTML = modeIconSvg(m.color) + '<span>' + m.label + '</span>';
    btn.onclick   = () => setMode(m.value);
    modeRow.appendChild(btn);
  });

  // Footer
  const dot  = document.getElementById('statusDot');
  const foot = document.getElementById('footerText');
  if (s.modeColor === 'off') {
    dot.className = 'status-dot idle';
    foot.textContent = 'Heizkreis inaktiv';
  } else {
    dot.className = 'status-dot ' + (s.modeColor !== 'heat' ? s.modeColor : '');
    let txt = s.actuatorActive ? 'Heizkreis aktiv' : 'Bereitschaft';
    if (s.nextSwitchLabel) txt += ' · ' + s.nextSwitchLabel;
    foot.textContent = txt;
  }

  // Outdoor
  const outdoorBar = document.getElementById('outdoorBar');
  if (s.outdoorTemp !== null) {
    outdoorBar.style.display = 'flex';
    document.getElementById('outdoorVal').textContent = s.outdoorTemp.toFixed(1) + ' °C';
  } else {
    outdoorBar.style.display = 'none';
  }
}

function addChip(row, label, val, cls) {
  const chip = document.createElement('div');
  chip.className = 'info-chip';
  chip.innerHTML = '<div class="chip-label">' + label + '</div>'
                 + '<div class="chip-val ' + cls + '">' + val + '</div>';
  row.appendChild(chip);
}

function changeTemp(dir) {
  if (!state.allowTempChange) return;
  let t = state.targetTemp + dir * state.tempStep;
  t = Math.round(t * 10) / 10;
  t = Math.max(state.tempMin, Math.min(state.tempMax, t));
  state.targetTemp = t;
  render();
  postCmd({ action: 'setTemp', value: t });
}

function setMode(val) {
  if (!state.allowModeChange) return;
  const entry = (state.modeList || []).find(m => m.value === val);
  if (!entry) return;
  state.modeValue = val;
  state.modeLabel = entry.label;
  state.modeColor = entry.color;
  render();
  postCmd({ action: 'setMode', value: val });
}

function postCmd(cmd) {
  fetch(state.hookUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(cmd)
  }).then(r => r.json())
    .then(() => loadData())
    .catch(() => {});
}

function loadData() {
  fetch(state.hookUrl)
    .then(r => r.json())
    .then(d => { state = d; render(); })
    .catch(() => {});
}

// Init & Polling
render();
const interval = parseInt('{$d[\"UpdateInterval\"]}', 10) || 30;
setInterval(loadData, Math.max(5, interval) * 1000);
</script>
</body>
</html>
HTML;
    }

    // ─── Variable registrieren (für HTML-Box) ─────────────────────────────────

    protected function RegisterHtmlVariable(): void
    {
        if (!IPS_VariableProfileExists('~HTMLBox')) {
            IPS_CreateVariableProfile('~HTMLBox', 3);
        }
        $this->MaintainVariable('HTML', 'Thermostat HTML', 3, '~HTMLBox', 0, true);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }
}
