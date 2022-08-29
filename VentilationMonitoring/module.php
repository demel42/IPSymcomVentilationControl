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

    private static $semaphoreID = __CLASS__;
    private static $semaphoreTM = 5 * 1000;

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

    public static $LOWERING_MODE_TEMP = 0;
    public static $LOWERING_MODE_TRIGGER = 1;
    public static $LOWERING_MODE_SCRIPT = 2;

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

        $this->RegisterPropertyInteger('lowering_mode', self::$LOWERING_MODE_TEMP);
        $this->RegisterPropertyFloat('lowering_temp_value', 12);
        $this->RegisterPropertyInteger('lowering_temp_varID', 0);
        $this->RegisterPropertyInteger('lowering_trigger', 1);
        $this->RegisterPropertyInteger('target0_varID', 0);
        $this->RegisterPropertyInteger('target1_varID', 0);
        $this->RegisterPropertyInteger('target2_varID', 0);
        $this->RegisterPropertyInteger('lowering_scriptID', 0);

        $this->RegisterPropertyString('durations', json_encode([]));

        $this->RegisterAttributeString('UpdateInfo', '');

        $this->RegisterAttributeString('state', json_encode([]));

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

        $propertyNames = [
            'delay_varID',
            'outside_temp_varID',
            'lowering_temp_varID',
            'target0_varID', 'target1_varID', 'target2_varID',
            'lowering_scriptID',
        ];
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

        $lowering_mode = $this->ReadPropertyInteger('lowering_mode');
        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'type'     => 'Select',
                    'name'     => 'lowering_mode',
                    'caption'  => 'Lowering mode',
                    'options'  => [
                        [
                            'caption' => $this->Translate('Set temperatur'),
                            'value'   => self::$LOWERING_MODE_TEMP,
                        ],
                        [
                            'caption' => $this->Translate('Set trigger'),
                            'value'   => self::$LOWERING_MODE_TRIGGER,
                        ],
                        [
                            'caption' => $this->Translate('Call script'),
                            'value'   => self::$LOWERING_MODE_SCRIPT,
                        ],
                    ],
                    'onChange' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateFormField4Lowering", $lowering_mode);',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'NumberSpinner',
                            'digits'  => 1,
                            'minimum' => 0,
                            'maximum' => 30,
                            'name'    => 'lowering_temp_value',
                            'caption' => 'Fix value'
                        ],
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'caption' => 'or'
                        ],
                        [
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_FLOAT],
                            'name'               => 'lowering_temp_varID',
                            'caption'            => 'Variable'
                        ],
                    ],
                    'name'    => 'lowering_temperature',
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_temperature'),
                    'caption' => 'Lowering temperatur'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'lowering_trigger',
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_trigger'),
                    'caption' => 'Trigger value'
                ],
                [
                    'type'    => 'ColumnLayout',
                    'items'   => [
                        [
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                            'width'              => '500px',
                            'name'               => 'target0_varID',
                            'caption'            => 'Target variable 1'
                        ],
                        [
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                            'width'              => '500px',
                            'name'               => 'target1_varID',
                            'caption'            => 'Target variable 2'
                        ],
                        [
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                            'name'               => 'target2_varID',
                            'width'              => '500px',
                            'caption'            => 'Target variable 3'
                        ],
                    ],
                    'name'               => 'lowering_targets',
                    'visible'            => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_targets'),
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'lowering_scriptID',
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_scriptID'),
                    'caption' => 'Script to lower temperatur'
                ],
            ],
            'caption' => 'Lower temperatur',
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

    private function LoweringFieldsIsVisible($mode, $field)
    {
        switch ($mode) {
            case self::$LOWERING_MODE_TEMP:
                $visible_flds = [
                    'lowering_temperature',
                    'lowering_targets',
                ];
                break;
            case self::$LOWERING_MODE_TRIGGER:
                $visible_flds = [
                    'lowering_trigger',
                    'lowering_targets',
                ];
                break;
            case self::$LOWERING_MODE_SCRIPT:
                $visible_flds = [
                    'lowering_scriptID',
                ];
                break;
        }
        return in_array($field, $visible_flds);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'CheckConditions':
                $this->CheckConditions();
                break;
            case 'CheckTimer':
                $this->CheckTimer();
                break;
            case 'UpdateFormField4Lowering':
                $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);
                $fields = [
                    'lowering_temperature',
                    'lowering_trigger',
                    'lowering_targets',
                    'lowering_scriptID',
                ];
                foreach ($fields as $field) {
                    $b = $this->LoweringFieldsIsVisible($value, $field);
                    $this->UpdateFormField($field, 'visible', $b);
                }
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

    private function CheckConditions()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if (IPS_SemaphoreEnter(self::$semaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . self::$semaphoreID . ' is not accessable', 0);
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

        $this->SendDebug(__FUNCTION__, $conditionS . ' => closureState=' . $closureState, 0);

        $oldClosureState = $this->GetValue('ClosureState');
        if ($closureState != $oldClosureState) {
            $oldClosureStateS = $this->GetValueFormatted('ClosureState');

            $this->SetValue('ClosureState', $closureState);
            $this->SetValue('TriggerTime', $closureState == self::$CLOSURE_STATE_CLOSE ? 0 : time());

            $closureStateS = $this->GetValueFormatted('ClosureState');
            $conditionS .= ': closureState=' . $closureStateS . ' (old=' . $oldClosureStateS . ')';

            $jstate = json_decode($this->ReadAttributeString('state'), true);
            if ($closureState == self::$CLOSURE_STATE_CLOSE) {
                if ($jstate != []) {
                    $msg = $conditionS . ' => stop timer';
                    $this->WriteAttributeString('state', json_encode([]));
                } else {
                    $msg = $conditionS . ' => no timer';
                }
                $this->MaintainTimer('LoopTimer', 0);
            } else {
                $duration = $this->CalcDuration();
                if ($duration > 0) {
                    $varID = $this->ReadPropertyInteger('delay_varID');
                    if (IPS_VariableExists($varID)) {
                        $tval = GetValueInteger($varID);
                    } else {
                        $tval = $this->ReadPropertyInteger('delay_value');
                    }
                    if ($tval > 0) {
                        $unit = $this->ReadPropertyInteger('delay_timeunit');
                        $sec = $this->CalcByTimeunit($unit, $tval);
                        $tvS = $tval . $this->Timeunit2Suffix($unit);
                        $msg = $conditionS . ', started with delay of ' . $tvS;
                        $jstate['step'] = 0;
                        $this->WriteAttributeString('state', json_encode($jstate));
                        $this->MaintainTimer('LoopTimer', $sec * 1000);
                    } else {
                        $msg = $conditionS . ', started ventilation phase of ' . $duration . 's';
                        $jstate['step'] = 1;
                        $this->WriteAttributeString('state', json_encode($jstate));
                        $this->MaintainTimer('LoopTimer', $duration * 1000);
                    }
                } else {
                    $msg = $conditionsS . ', no duration defined';
                    $this->WriteAttributeString('state', json_encode([]));
                    $this->MaintainTimer('LoopTimer', 0);
                }
            }

            $this->SendDebug(__FUNCTION__, $msg, 0);
            $this->AddModuleActivity($msg);
        }

        IPS_SemaphoreLeave(self::$semaphoreID);
    }

    private function CheckTimer()
    {
        if (IPS_SemaphoreEnter(self::$semaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . self::$semaphoreID . ' is not accessable', 0);
            return;
        }

        $msg = '';
        $duration = $this->CalcDuration();
        if ($duration > 0) {
            $jstate = json_decode($this->ReadAttributeString('state'), true);
            if (isset($jstate['step']) && $jstate['step'] == 0) {
                $msg .= ', started ventilation phase of ' . $duration . 's';
                $jstate['step'] = 1;
                $this->WriteAttributeString('state', json_encode($jstate));
                $this->MaintainTimer('LoopTimer', $duration * 1000);
            } else {
                $msg .= ', make notification';
                $this->WriteAttributeString('state', json_encode([]));
                $this->MaintainTimer('LoopTimer', 0);
            }
        } else {
            $msg .= ', no duration defined';
            $this->WriteAttributeString('state', json_encode([]));
            $this->MaintainTimer('LoopTimer', 0);
        }

        $this->SendDebug(__FUNCTION__, $msg, 0);
        $this->AddModuleActivity($msg);

        IPS_SemaphoreLeave(self::$semaphoreID);
    }

    private function CalcDuration()
    {
        $duration = 15;

        $this->SendDebug(__FUNCTION__, 'duration=' . $duration, 0);
        return $duration;
    }
}
