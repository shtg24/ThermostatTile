<?php

declare(strict_types=1);

class ThermostatTile extends IPSModule
{
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
        $this->RegisterPropertyString('RoomName', 'Raum');
        $this->RegisterPropertyString('RoomSubtitle', '');
        $this->RegisterPropertyInteger('UpdateInterval', 30);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'THERMO_Update($_IPS["TARGET"]);');

        // HTML-Ausgabevariable (String)
        $this->MaintainVariable('HTML', 'Thermostat HTML', 3, '', 0, true);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Alle konfigurierten Variablen beobachten
        $this->UnregisterAllMessages();
        $watch = [
            'TargetTemperatureID', 'ActualTemperatureID', 'HeatingModeID',
            'WindowOpenID', 'LockID', 'HumidityID', 'CO2ID',
            'ValvePositionID', 'ActuatorStateID', 'BoostActiveID',
            'BoostDurationID', 'PresenceID', 'AbsenceOffsetID',
            'ScheduleActiveID', 'NextSwitchTimeID', 'NextSwitchTempID',
            'OutdoorTempID',
        ];
        foreach ($watch as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Webhook
        $this->RegisterHook('/hook/thermostat_' . $this->InstanceID);

        // Timer
        $sec = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $sec > 0 ? $sec * 1000 : 0);

        $this->Update();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->Update();
        }
    }

    public function Update(): void
    {
        $data = $this->CollectData();
        $html = $this->BuildHTML($data);
        $this->SetValue('HTML', $html);
    }

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

        header('Content-Type: application/json');
        echo json_encode($this->CollectData());
    }

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

    private function CollectData(): array
    {
        $g = function (string $prop, $default = null) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                return GetValue($id);
            }
            return $default;
        };

        $modeRaw   = (int) $g('HeatingModeID', 1);
        $modeMap   = json_decode($this->ReadPropertyString('ModeMapping'), true) ?? [];
        $modeEntry = array_values(array_filter($modeMap, fn($m) => $m['Value'] == $modeRaw));
        $modeLabel = $modeEntry[0]['Label'] ?? 'Heizen';
        $modeColor = $modeEntry[0]['Color'] ?? 'heat';

        $nextTs    = (int) $g('NextSwitchTimeID', 0);
        $nextLabel = '';
        if ($nextTs > 0) {
            $diff = $nextTs - time();
            if ($diff > 0 && $diff < 86400) {
                $nextLabel = date('H:i', $nextTs) . ' Uhr';
            } elseif ($diff >= 86400) {
                $nextLabel = date('D, H:i', $nextTs);
            }
        }

        $modeList = array_map(fn($m) => [
            'value' => (int) $m['Value'],
            'label' => $m['Label'],
            'color' => $m['Color'],
        ], $modeMap);

        $humidity = $g('HumidityID');
        $co2      = $g('CO2ID');
        $valve    = $g('ValvePositionID');
        $boost    = $g('BoostDurationID');
        $absOff   = $g('AbsenceOffsetID');
        $nxtTemp  = $g('NextSwitchTempID');
        $outdoor  = $g('OutdoorTempID');

        return [
            'roomName'        => $this->ReadPropertyString('RoomName'),
            'roomSubtitle'    => $this->ReadPropertyString('RoomSubtitle'),
            'targetTemp'      => round((float) $g('TargetTemperatureID', 20.0), 1),
            'actualTemp'      => round((float) $g('ActualTemperatureID', 20.0), 1),
            'tempMin'         => $this->ReadPropertyFloat('TempMin'),
            'tempMax'         => $this->ReadPropertyFloat('TempMax'),
            'tempStep'        => $this->ReadPropertyFloat('TempStep'),
            'modeValue'       => $modeRaw,
            'modeLabel'       => $modeLabel,
            'modeColor'       => $modeColor,
            'modeList'        => $modeList,
            'allowTempChange' => $this->ReadPropertyBoolean('AllowTempChange'),
            'allowModeChange' => $this->ReadPropertyBoolean('AllowModeChange'),
            'windowOpen'      => (bool) $g('WindowOpenID', false),
            'locked'          => (bool) $g('LockID', false),
            'humidity'        => $humidity !== null ? (int) round((float) $humidity) : null,
            'co2'             => $co2 !== null ? (int) $co2 : null,
            'valve'           => $valve !== null ? (int) round((float) $valve) : null,
            'actuatorActive'  => (bool) $g('ActuatorStateID', false),
            'boostActive'     => (bool) $g('BoostActiveID', false),
            'boostMinutes'    => $boost !== null ? (int) $boost : null,
            'presence'        => (bool) $g('PresenceID', true),
            'absenceOffset'   => $absOff !== null ? round((float) $absOff, 1) : null,
            'scheduleActive'  => (bool) $g('ScheduleActiveID', false),
            'nextSwitchLabel' => $nextLabel,
            'nextSwitchTemp'  => $nxtTemp !== null ? round((float) $nxtTemp, 1) : null,
            'outdoorTemp'     => $outdoor !== null ? round((float) $outdoor, 1) : null,
            'hookUrl'         => '/hook/thermostat_' . $this->InstanceID,
            'updateInterval'  => $this->ReadPropertyInteger('UpdateInterval'),
        ];
    }

    private function BuildHTML(array $d): string
    {
        $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1117;--surf:#181c27;--rim:#232843;
  --heat:#e8784a;--cold:#5ba4cf;--eco:#50b478;
  --text:#e8e4dd;--muted:#6b7080;
  font-family:'DM Mono',monospace;
}
body{background:var(--bg);display:flex;justify-content:center;align-items:flex-start;padding:16px;min-height:100vh}
.card{width:300px;background:var(--surf);border:1px solid var(--rim);border-radius:20px;
  padding:22px 20px 18px;position:relative;overflow:hidden;user-select:none;transition:box-shadow .4s}
.card::before{content:'';position:absolute;inset:0;border-radius:20px;pointer-events:none;transition:background .5s;
  background:radial-gradient(ellipse at 50% -10%,rgba(232,120,74,.16),transparent 65%)}
.card.cold::before{background:radial-gradient(ellipse at 50% -10%,rgba(91,164,207,.14),transparent 65%)}
.card.eco::before{background:radial-gradient(ellipse at 50% -10%,rgba(80,180,120,.13),transparent 65%)}
.card.off::before{background:none}
/* Header */
.hdr{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:16px}
.room{font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--text);line-height:1.15}
.sub{font-size:.58rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:3px}
.badges{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.mbadge{font-size:.56rem;letter-spacing:.08em;text-transform:uppercase;
  padding:3px 8px;border-radius:20px;border:1px solid currentColor;transition:color .4s}
.mbadge.heat{color:var(--heat)}.mbadge.cold{color:var(--cold)}
.mbadge.eco{color:var(--eco)}.mbadge.off{color:var(--muted)}
.ibadge{font-size:.72rem}
/* Dial */
.dial-wrap{position:relative;width:172px;height:172px;margin:0 auto 16px}
.dial-svg{width:100%;height:100%;transform:rotate(-135deg)}
.track{fill:none;stroke:var(--rim);stroke-width:10;stroke-linecap:round}
.prog{fill:none;stroke-width:10;stroke-linecap:round;stroke:var(--heat);
  transition:stroke-dashoffset .5s cubic-bezier(.4,0,.2,1),stroke .4s}
.dc{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px}
.tset{font-size:2.45rem;font-weight:500;color:var(--text);line-height:1;letter-spacing:-.03em}
.tunit{font-size:.68rem;color:var(--muted);letter-spacing:.06em;margin-top:2px}
.talbl{font-size:.54rem;color:var(--muted);letter-spacing:.07em;text-transform:uppercase;margin-top:5px}
.tact{font-size:.82rem;color:var(--heat);transition:color .4s}
.dbtn{position:absolute;top:50%;transform:translateY(-50%);
  width:30px;height:30px;border-radius:50%;background:var(--rim);border:none;
  color:var(--text);font-size:1.15rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .2s,transform .1s}
.dbtn:hover{background:#2e3450}.dbtn:active{transform:translateY(-50%) scale(.88)}
.dbtn:disabled{opacity:.3;cursor:not-allowed}
.dminus{left:-14px}.dplus{right:-14px}
/* Chips */
.chips{display:flex;gap:5px;margin-bottom:13px;flex-wrap:wrap}
.chip{flex:1;min-width:68px;background:rgba(255,255,255,.03);border:1px solid var(--rim);
  border-radius:11px;padding:6px 7px;text-align:center}
.clbl{font-size:.5rem;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.cval{font-size:.82rem;color:var(--text)}
.cval.h{color:var(--heat)}.cval.c{color:var(--cold)}.cval.e{color:var(--eco)}.cval.w{color:#e8c94a}.cval.m{color:var(--muted)}
/* Mode buttons */
.modes{display:flex;gap:5px;margin-bottom:13px}
.mbtn{flex:1;background:rgba(255,255,255,.03);border:1px solid var(--rim);border-radius:10px;
  color:var(--muted);font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.07em;
  text-transform:uppercase;padding:7px 3px;cursor:pointer;transition:background .2s,color .2s,border-color .2s;
  display:flex;flex-direction:column;align-items:center;gap:3px}
.mbtn:hover{background:var(--rim);color:var(--text)}
.mbtn:disabled{opacity:.3;cursor:not-allowed}
.mbtn.ah{background:rgba(232,120,74,.12);border-color:var(--heat);color:var(--heat)}
.mbtn.ac{background:rgba(91,164,207,.12);border-color:var(--cold);color:var(--cold)}
.mbtn.ae{background:rgba(80,180,120,.12);border-color:var(--eco);color:var(--eco)}
.mbtn.ao{background:rgba(255,255,255,.05);border-color:var(--muted);color:var(--text)}
/* Footer */
.foot{display:flex;align-items:center;gap:5px;font-size:.54rem;color:var(--muted)}
.dot{width:7px;height:7px;border-radius:50%;background:var(--heat);
  box-shadow:0 0 6px var(--heat);animation:pulse 2s ease-in-out infinite;flex-shrink:0}
.dot.idle{background:var(--muted);box-shadow:none;animation:none}
.dot.c{background:var(--cold);box-shadow:0 0 6px var(--cold)}
.dot.e{background:var(--eco);box-shadow:0 0 6px var(--eco)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.outbar{display:flex;justify-content:space-between;align-items:center;
  border-top:1px solid var(--rim);margin-top:11px;padding-top:9px;font-size:.58rem;color:var(--muted)}
.outval{font-size:.8rem;color:var(--text)}
</style>
</head>
<body>
<div class="card" id="card">
  <div class="hdr">
    <div>
      <div class="room" id="rname"></div>
      <div class="sub"  id="rsub"></div>
    </div>
    <div class="badges">
      <div class="mbadge" id="mbadge"></div>
      <div class="ibadge" id="ibadge"></div>
    </div>
  </div>

  <div class="dial-wrap">
    <svg class="dial-svg" viewBox="0 0 172 172">
      <circle class="track" cx="86" cy="86" r="74" stroke-dasharray="349.5" stroke-dashoffset="87.4"/>
      <circle class="prog" id="prog" cx="86" cy="86" r="74" stroke-dasharray="349.5" stroke-dashoffset="87.4"/>
    </svg>
    <div class="dc">
      <div class="tset" id="tset">--</div>
      <div class="tunit">°C Soll</div>
      <div class="talbl">Ist-Temperatur</div>
      <div class="tact" id="tact">-- °C</div>
    </div>
    <button class="dbtn dminus" id="bminus" onclick="chTemp(-1)">−</button>
    <button class="dbtn dplus"  id="bplus"  onclick="chTemp(+1)">+</button>
  </div>

  <div class="chips" id="chips"></div>
  <div class="modes" id="modes"></div>

  <div class="foot">
    <span class="dot" id="dot"></span>
    <span id="ftxt">–</span>
  </div>
  <div class="outbar" id="outbar" style="display:none">
    <span>Außentemperatur</span>
    <span class="outval" id="outval">–</span>
  </div>
</div>

<script>
var S = $json;
var DASH = 349.5, GAP = 87.4;

function offset(t) {
  var pct = (t - S.tempMin) / (S.tempMax - S.tempMin);
  pct = Math.max(0, Math.min(1, pct));
  return DASH - pct * DASH + GAP;
}

var colorMap = {heat:'var(--heat)', cold:'var(--cold)', eco:'var(--eco)', off:'var(--muted)'};
var dotClass = {heat:'', cold:'c', eco:'e', off:'idle'};
var activeCls = {heat:'ah', cold:'ac', eco:'ae', off:'ao'};

function icon(c) {
  if(c==='heat') return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2"/><path d="M8 14s1.5-2 4-2 4 2 4 2"/></svg>';
  if(c==='cold') return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 7l-5 5-5-5M17 17l-5-5-5 5"/><line x1="2" y1="12" x2="22" y2="12"/></svg>';
  if(c==='eco')  return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22s4-2 6-6c2 4 8 6 14 4-2-6-8-10-14-8 0 0 2 4 0 10"/></svg>';
  return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="8" x2="16" y2="16"/></svg>';
}

function chip(lbl, val, cls) {
  return '<div class="chip"><div class="clbl">'+lbl+'</div><div class="cval '+cls+'">'+val+'</div></div>';
}

function render() {
  // card
  document.getElementById('card').className = 'card ' + S.modeColor;
  document.getElementById('rname').textContent = S.roomName;
  document.getElementById('rsub').textContent  = S.roomSubtitle || '';
  // badge
  var b = document.getElementById('mbadge');
  b.textContent = S.modeLabel; b.className = 'mbadge ' + S.modeColor;
  // icon badges
  var icons = [];
  if(S.windowOpen) icons.push('🪟');
  if(S.locked)     icons.push('🔒');
  if(S.boostActive) icons.push('🚀');
  if(!S.presence)  icons.push('🏃');
  document.getElementById('ibadge').textContent = icons.join(' ');
  // dial
  document.getElementById('tset').textContent  = S.targetTemp.toFixed(1);
  document.getElementById('tact').textContent  = S.actualTemp.toFixed(1) + ' °C';
  var p = document.getElementById('prog');
  p.style.strokeDashoffset = offset(S.targetTemp);
  p.style.stroke = colorMap[S.modeColor] || colorMap.heat;
  document.getElementById('tact').style.color = colorMap[S.modeColor] || colorMap.heat;
  // buttons
  var dis = !S.allowTempChange || S.locked || S.windowOpen;
  document.getElementById('bminus').disabled = dis;
  document.getElementById('bplus').disabled  = dis;
  // chips
  var ch = '';
  if(S.humidity !== null)   ch += chip('Feuchte', S.humidity + ' %', '');
  if(S.valve !== null)      ch += chip('Ventil', S.valve + ' %', S.modeColor==='cold'?'c':'h');
  if(S.co2 !== null)        ch += chip('CO₂', S.co2 + ' ppm', S.co2>1000?'w':'');
  if(S.boostActive && S.boostMinutes !== null) ch += chip('Boost', S.boostMinutes + ' min', 'h');
  else if(S.nextSwitchLabel) ch += chip('Nächste', S.nextSwitchLabel, 'm');
  if(S.absenceOffset !== null && !S.presence) ch += chip('Absenkung', '−'+S.absenceOffset+' °C', 'e');
  document.getElementById('chips').innerHTML = ch;
  // mode buttons
  var mr = document.getElementById('modes');
  mr.innerHTML = '';
  (S.modeList||[]).forEach(function(m) {
    var btn = document.createElement('button');
    btn.className = 'mbtn' + (m.value===S.modeValue ? ' '+activeCls[m.color] : '');
    btn.disabled  = !S.allowModeChange;
    btn.innerHTML = icon(m.color) + '<span>'+m.label+'</span>';
    btn.onclick   = function(){ setMode(m.value, m.label, m.color); };
    mr.appendChild(btn);
  });
  // footer
  var dot = document.getElementById('dot');
  dot.className = 'dot ' + (dotClass[S.modeColor] || '');
  var ft = S.modeColor==='off' ? 'Heizkreis inaktiv' : (S.actuatorActive ? 'Heizkreis aktiv' : 'Bereitschaft');
  if(S.nextSwitchLabel) ft += ' · ' + S.nextSwitchLabel;
  document.getElementById('ftxt').textContent = ft;
  // outdoor
  var ob = document.getElementById('outbar');
  if(S.outdoorTemp !== null) {
    ob.style.display = 'flex';
    document.getElementById('outval').textContent = S.outdoorTemp.toFixed(1) + ' °C';
  } else {
    ob.style.display = 'none';
  }
}

function chTemp(dir) {
  if(!S.allowTempChange || S.locked || S.windowOpen) return;
  var t = Math.round((S.targetTemp + dir * S.tempStep) * 10) / 10;
  t = Math.max(S.tempMin, Math.min(S.tempMax, t));
  S.targetTemp = t;
  render();
  post({action:'setTemp', value:t});
}

function setMode(val, lbl, col) {
  if(!S.allowModeChange) return;
  S.modeValue = val; S.modeLabel = lbl; S.modeColor = col;
  render();
  post({action:'setMode', value:val});
}

function post(cmd) {
  fetch(S.hookUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(cmd)})
    .then(function(){reload();}).catch(function(){});
}

function reload() {
  fetch(S.hookUrl).then(function(r){return r.json();}).then(function(d){S=d;render();}).catch(function(){});
}

render();
var iv = Math.max(5, S.updateInterval || 30) * 1000;
setInterval(reload, iv);
</script>
</body>
</html>
HTML;
    }

    public function GetConfigurationForm(): string
    {
        return file_get_contents(__DIR__ . '/form.json');
    }
}
