<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VentilationMonitoring extends IPSModule
{
    use VentilationMonitoring\StubsCommonLib;
    use VentilationMonitoringLocalLib;

    private $ModuleDir;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    /*
        property "open_conditions", "tilt_conditions"
            -> Fenster-Zustands-Variablen und deren Stati

        properties "delay_*"
            -> damit nicht bei einem kleinen Auf/Zu sofort geregelt wird.

        Aktion bei "open_conditions"
            Variable(n) für "Fenster offen" („WINDOW_STATE“ bei HmIP) setzen
            ODER
            Variable(n) für Solltemperatur, alte Solltemp. merken, neu setzen

            reverse bei Condition = false

        Meldung nachts deaktivieren

        Überschreitung Lüftungszeit melden
            Variable Aussentemperatur
            max. Lüftungsdauer für 3 Temperatur-Stufen (hoch/Sommer, normal/Heizperiode, Winter)

        Lüftungsempfehlung
            variable Raumtemperatur, Luftfeuchte innen & aussen
            -> abs. Feuchte berechnen
            -> Lüften sinnvoll?
            -> Schimmelgefahr

            CO2

     */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('open_conditions', json_encode([]));
        $this->RegisterPropertyString('tilt_conditions', json_encode([]));

        $this->RegisterPropertyInteger('delay_value', 30);
        $this->RegisterPropertyInteger('delay_varID', 0);
        $this->RegisterPropertyInteger('delay_timeunit', self::$TIMEUNIT_SECONDS);

        $this->RegisterPropertyInteger('outside_temp_varID', 0);

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('LoopTimer', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "CheckTimer", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->CheckConditions();
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $this->CheckConditions();
        }
    }

    private function CheckConditions()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $is_open = false;
        $is_tilt = false;
        $conditionS = '';

        $open_conditions = $this->ReadPropertyString('open_conditions');
		if (json_decode($open_conditions, true)) {
            $is_open = IPS_IsConditionPassing($open_conditions);
            $conditionS .= 'open-conditions ' . ($is_open ? 'passed' : 'blocked');
        } else {
            $conditionS .= 'no open-conditions';
        }

        $conditionS .= ', ';

        $tilt_conditions = $this->ReadPropertyString('tilt_conditions');
		if (json_decode($tilt_conditions, true)) {
            $is_tilt = IPS_IsConditionPassing($tilt_conditions);
            $conditionS .= 'tilt-conditions ' . ($is_tilt ? 'passed' : 'blocked');
        } else {
            $conditionS .= 'no tilt-conditions';
        }

        if ($is_open) {
            $closureState = self::$CLOSURE_STATE_OPEN;
        } elseif ($is_tilt) {
            $closureState = self::$CLOSURE_STATE_TILT;
        } else {
            $closureState = self::$CLOSURE_STATE_CLOSE;
        }

        $this->SendDebug(__FUNCTION__, $conditionS . ' => state=' . $closureState, 0);

        if ($closureState != $this->GetValue('ClosureState')) {
            $this->SetValue('ClosureState', $closureState);
            $this->SetValue('TriggerTime', $closureState == self::$CLOSURE_STATE_CLOSE ? 0 : time());

			$conditionS .= ' => state=' . $this->GetValueFormatted('ClosureState');
			$this->AddModuleActivity($conditionS);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $open_conditions = $this->ReadPropertyString('open_conditions');
        $tilt_conditions = $this->ReadPropertyString('tilt_conditions');
		if (json_decode($open_conditions, true) == false && json_decode($tilt_conditions, true) == false) {
            $r[] = $this->Translate('Minimum one condition (open/tiled) must be defined');
		}

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['delay_varID', 'outside_temp_varID'];
        $this->MaintainReferences($propertyNames);

        $varIDs = [];
        $open_conditions = json_decode($this->ReadPropertyString('open_conditions'), true);
        if ($open_conditions != false) {
            foreach ($open_conditions as $condition) {
                $vars = $condition['rules']['variable'];
                foreach ($vars as $var) {
                    $variableID = $var['variableID'];
                    if (in_array($variableID, $varIDs) == false) {
                        $varIDs[] = $variableID;
                    }
                    if ($this->GetArrayElem($var, 'type', 0) == 1 /* compare with variable */) {
                        $oid = $var['value'];
                        if (in_array($oid, $varIDs) == false) {
                            $varIDs[] = $oid;
                        }
                    }
                }
            }
        }
        $tilt_conditions = json_decode($this->ReadPropertyString('tilt_conditions'), true);
        if ($tilt_conditions != false) {
            foreach ($tilt_conditions as $condition) {
                $vars = $condition['rules']['variable'];
                foreach ($vars as $var) {
                    $variableID = $var['variableID'];
                    if (in_array($variableID, $varIDs) == false) {
                        $varIDs[] = $variableID;
                    }
                    if ($this->GetArrayElem($var, 'type', 0) == 1 /* compare with variable */) {
                        $oid = $var['value'];
                        if (in_array($oid, $varIDs) == false) {
                            $varIDs[] = $oid;
                        }
                    }
                }
            }
        }
        foreach ($varIDs as $varID) {
            if (IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }
        $propertyNames = ['outside_temp_varID'];
        foreach ($propertyNames as $propertyName) {
            $varID = $this->ReadPropertyInteger($propertyName);
            if (IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 0;

        $this->MaintainVariable('ClosureState', $this->Translate('Closure state'), VARIABLETYPE_INTEGER, 'VentilationMonitoring.ClosureState', $vpos++, true);

        $this->MaintainVariable('TriggerTime', $this->Translate('Triggering time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->CheckConditions();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Ventilation monitoring');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'    => 'open_conditions',
                    'type'    => 'SelectCondition',
                    'multi'   => true,
                ],
            ],
            'caption' => 'Condition for open window detection',
        ];
        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'    => 'tilt_conditions',
                    'type'    => 'SelectCondition',
                    'multi'   => true,
                ],
            ],
            'caption' => 'Condition for tilt window detection',
        ];

        $formElements[] = [
            'type'               => 'SelectVariable',
            'validVariableTypes' => [VARIABLETYPE_FLOAT],
            'width'              => '500px',
            'name'               => 'outside_temp_varID',
            'caption'            => 'Outside temperature'
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'Select',
                            'name'    => 'delay_timeunit',
                            'options' => $this->GetTimeunitAsOptions(),
                            'caption' => 'Time unit',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'minimum' => 0,
                            'name'    => 'delay_value',
                            'caption' => 'Fix value'
                        ],
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'caption' => 'or'
                        ],
                        [
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER],
                            'name'               => 'delay_varID',
                            'caption'            => 'Variable',
                            'width'              => '500px',
                        ],
                    ],
                ],
            ],
            'caption'  => 'Initial delay',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
			'type'    => 'Button',
			'caption' => 'Check conditions',
			'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "CheckConditions", "");',
		];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded ' => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded ' => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();
		$formActions[] = $this->GetModuleActivityFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
			case 'CheckConditions':
                $this->CheckConditions();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }
}
