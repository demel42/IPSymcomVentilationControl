<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VentilationMonitoring extends IPSModule
{
    use VentilationMonitoring\StubsCommonLib;
    use VentilationMonitoringLocalLib;

    public static $TIMEUNIT_SECONDS = 0;
    public static $TIMEUNIT_MINUTES = 1;
    public static $TIMEUNIT_HOURS = 2;
    public static $TIMEUNIT_DAYS = 3;

    private $ModuleDir;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    /*
        RequestAction-Zielvariable („WINDOW_STATE“ bei HmIP)

        Variable(n) für Fenster-Status („STATE“ bei HmIP) / Angabe „zu“ = <Wert> => Bedingungen?

        Variable Aussentemperatur

        Lüftungsdauer für 3 Temperatur-Stufen (hoch/Sommer, normal/Heizperiode, Winter)

		variable Raumtemperatur, Luftfeuchte innen & aussen
		-> abs. Feuchte berechnen
		-> Lüften sinnvoll?
		-> Schimmelgefahr

     */

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('conditions', json_encode([]));

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
        }

        if ($message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            /*
            senderID = varID
            data[0] = value
            data[1] = changed
            data[2] = old value
            data[3] =
            data[4] =
            data[5] =
             */
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['delay_varID', 'outside_temp_varID'];
        $this->MaintainReferences($propertyNames);
        $conditions = json_decode($this->ReadPropertyString('conditions'), true);
        if ($conditions != false) {
            foreach ($conditions as $condition) {
                $vars = $condition['rules']['variable'];
                foreach ($vars as $var) {
                    $variableID = $var['variableID'];
                    if (IPS_VariableExists($variableID)) {
                        $this->RegisterReference($variableID);
                        $this->RegisterMessage($variableID, VM_UPDATE);
                    }
                    if ($this->GetArrayElem($var, 'type', 0) == 1 /* compare with variable */) {
                        $oid = $var['value'];
                        if (IPS_VariableExists($oid)) {
                            $this->RegisterReference($oid);
                            $this->RegisterMessage($oid, VM_UPDATE);
                        }
                    }
                }
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

        $vops = 0;

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
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
                    'name'    => 'conditions',
                    'type'    => 'SelectCondition',
                    'multi'   => true,
                ],
            ],
            'caption' => 'Condition for open window detection',
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

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
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

    private function GetTimeunitAsOptions()
    {
        return [
            [
                'value'   => self::$TIMEUNIT_SECONDS,
                'caption' => $this->Translate('Seconds'),
            ],
            [
                'value'   => self::$TIMEUNIT_MINUTES,
                'caption' => $this->Translate('Minutes'),
            ],
            [
                'value'   => self::$TIMEUNIT_HOURS,
                'caption' => $this->Translate('Hours'),
            ],
            [
                'value'   => self::$TIMEUNIT_DAYS,
                'caption' => $this->Translate('Days'),
            ],
        ];
    }
}
