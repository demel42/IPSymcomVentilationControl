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

    public static $LOWERING_MODE_TEMP = 0;
    public static $LOWERING_MODE_TRIGGER = 1;
    public static $LOWERING_MODE_SCRIPT = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('open_conditions', json_encode([]));
        $this->RegisterPropertyString('tilt_conditions', json_encode([]));

        $this->RegisterPropertyBoolean('monitoring_control', false);

        $this->RegisterPropertyInteger('delay_value', 30);
        $this->RegisterPropertyInteger('delay_varID', 0);
        $this->RegisterPropertyInteger('delay_timeunit', self::$TIMEUNIT_SECONDS);

        $this->RegisterPropertyInteger('lowering_mode', self::$LOWERING_MODE_TEMP);
        $this->RegisterPropertyFloat('lowering_temp_value', 12);
        $this->RegisterPropertyInteger('lowering_temp_varID', 0);
        $this->RegisterPropertyInteger('lowering_trigger', 1);
        $this->RegisterPropertyInteger('lowering_scriptID', 0);
        $this->RegisterPropertyString('lowering_targets', json_encode([]));

        $this->RegisterPropertyString('durations', json_encode([]));

        $this->RegisterPropertyString('notice_script', '');

        $this->RegisterPropertyInteger('pause_value', 0);
        $this->RegisterPropertyInteger('pause_varID', 0);
        $this->RegisterPropertyInteger('pause_timeunit', self::$TIMEUNIT_MINUTES);

        $this->RegisterPropertyBoolean('with_calculations', false);
        $this->RegisterPropertyBoolean('with_reduce_humidity', false);
        $this->RegisterPropertyBoolean('with_risk_of_mold', false);

        $this->RegisterPropertyInteger('outside_temp_varID', 0);
        $this->RegisterPropertyInteger('outside_hum_varID', 0);
        $this->RegisterPropertyInteger('indoor_temp_varID', 0);
        $this->RegisterPropertyInteger('indoor_hum_varID', 0);
        $this->RegisterPropertyInteger('air_pressure_varID', 0);

        $this->RegisterPropertyFloat('thermal_resistance', 0);

        $this->RegisterPropertyFloat('mold_hum_min', 60);

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
            $this->SendDebug(__FUNCTION__, '"open_conditions" and "tilt_conditions" must be defined', 0);
            $r[] = $this->Translate('Minimum one condition (open/tiled) must be defined');
        }

        $with_reduce_humidity = $this->ReadPropertyBoolean('with_reduce_humidity');
        if ($with_reduce_humidity) {
            $propertyNames = [
                'outside_temp_varID' => 'Outside temperature',
                'outside_hum_varID'  => 'Indoor humidity',
                'indoor_temp_varID'  => 'Indoor temperature',
                'indoor_hum_varID'   => 'Indoor humidity',
                'air_pressure_varID' => 'Air pressure',
            ];
            foreach ($propertyNames as $propertyName => $desc) {
                $varID = $this->ReadPropertyInteger($propertyName);
                if ($this->IsValidID($varID) == false || IPS_VariableExists($varID) == false) {
                    $this->SendDebug(__FUNCTION__, '"' . $propertyName . '" is undefined or invalid', 0);
                    $field = $this->Translate($desc);
                    $r[] = $this->TranslateFormat('To calculate "Reduce humidity possible", Field "{$field}" must be configured', ['{$field}' => $field]);
                }
            }
        }

        $with_risk_of_mold = $this->ReadPropertyBoolean('with_risk_of_mold');
        if ($with_risk_of_mold) {
            $propertyNames = [
                'outside_temp_varID' => 'Outside temperature',
                'indoor_temp_varID'  => 'Indoor temperature',
                'indoor_hum_varID'   => 'Indoor humidity',
            ];
            foreach ($propertyNames as $propertyName => $desc) {
                $varID = $this->ReadPropertyInteger($propertyName);
                if ($this->IsValidID($varID) == false || IPS_VariableExists($varID) == false) {
                    $this->SendDebug(__FUNCTION__, '"' . $propertyName . '" is undefined or invalid', 0);
                    $field = $this->Translate($desc);
                    $r[] = $this->TranslateFormat('To calculate "Risk of mold", Field "{$field}" must be configured', ['{$field}' => $field]);
                }
            }
            $thermal_resistance = $this->ReadPropertyFloat('thermal_resistance');
            if ($thermal_resistance == 0) {
                $this->SendDebug(__FUNCTION__, '"thermal_resistance" is undefined or invalid', 0);
                $field = $this->Translate('Total thermal resistance of outer wall');
                $r[] = $this->TranslateFormat('To calculate "Risk of mold", Field "{$field}" must be configured', ['{$field}' => $field]);
            }
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [
            'delay_varID',
            'pause_varID',
            'lowering_temp_varID',
            'lowering_scriptID',
            'outside_temp_varID',
            'outside_hum_varID',
            'indoor_temp_varID',
            'indoor_hum_varID',
            'air_pressure_varID',
        ];
        $this->MaintainReferences($propertyNames);

        $propertyNames = ['notice_script'];
        foreach ($propertyNames as $name) {
            $text = $this->ReadPropertyString($name);
            $this->MaintainReferences4Script($text);
        }

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

        $durations = json_decode($this->ReadPropertyString('durations'), true);
        if ($durations != false) {
            foreach ($durations as $duration) {
                if (isset($duration['condition']['rules']['variable'])) {
                    $vars = $duration['condition']['rules']['variable'];
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
        }

        foreach ($varIDs as $varID) {
            if (IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
            }
        }

        $lowering_targets = json_decode($this->ReadPropertyString('lowering_targets'), true);
        if ($lowering_targets != false) {
            foreach ($lowering_targets as $target) {
                $varID = $target['varID'];
                if (IPS_VariableExists($varID)) {
                    $this->RegisterReference($varID);
                }
            }
        }

        $propertyNames = [
            'outside_temp_varID',
            'outside_hum_varID',
            'indoor_temp_varID',
            'indoor_hum_varID',
            'air_pressure_varID',
        ];
        foreach ($propertyNames as $propertyName) {
            $varIDs[] = $this->ReadPropertyInteger($propertyName);
        }

        $this->UnregisterMessages([VM_UPDATE]);

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

        $vpos = 1;

        $this->MaintainVariable('ClosureState', $this->Translate('Closure state'), VARIABLETYPE_INTEGER, 'VentilationMonitoring.ClosureState', $vpos++, true);

        $this->MaintainVariable('TriggerTime', $this->Translate('Triggering time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $monitoring_control = $this->ReadPropertyBoolean('monitoring_control');
        $this->MaintainVariable('MonitorVentilation', $this->Translate('Monitor ventilation'), VARIABLETYPE_BOOLEAN, '~Switch', $vpos++, $monitoring_control);
        if ($monitoring_control) {
            $this->MaintainAction('MonitorVentilation', true);
        }

        $with_calculations = $this->ReadPropertyBoolean('with_calculations');
        $with_reduce_humidity = $this->ReadPropertyBoolean('with_reduce_humidity');
        $with_risk_of_mold = $this->ReadPropertyBoolean('with_risk_of_mold');

        $vpos = 10;
        $this->MaintainVariable('ReduceHumidityPossible', $this->Translate('Reduce humidity possible'), VARIABLETYPE_BOOLEAN, 'VentilationMonitoring.ReduceHumidityPossible', $vpos++, $with_reduce_humidity);
        $this->MaintainVariable('RiskOfMold', $this->Translate('Risk of mold'), VARIABLETYPE_INTEGER, 'VentilationMonitoring.RiskOfMold', $vpos++, $with_risk_of_mold);

        $vpos = 20;
        $with_calculations = $this->ReadPropertyBoolean('with_calculations');
        $this->MaintainVariable('OutsideAbsoluteHumidity', $this->Translate('Outside absolute humidity'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.AbsoluteHumidity', $vpos++, $with_calculations);
        $this->MaintainVariable('OutsideSpecificHumidity', $this->Translate('Outside specific humidity'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.SpecificHumidity', $vpos++, $with_calculations);
        $this->MaintainVariable('OutsideDewpoint', $this->Translate('Outside dewpoint'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.Dewpoint', $vpos++, $with_calculations);

        $this->MaintainVariable('IndoorAbsoluteHumidity', $this->Translate('Indoor absolute humidity'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.AbsoluteHumidity', $vpos++, $with_calculations);
        $this->MaintainVariable('IndoorSpecificHumidity', $this->Translate('Indoor specific humidity'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.SpecificHumidity', $vpos++, $with_calculations);
        $this->MaintainVariable('IndoorDewpoint', $this->Translate('Indoor dewpoint'), VARIABLETYPE_FLOAT, 'VentilationMonitoring.Dewpoint', $vpos++, $with_calculations);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        foreach ($varIDs as $varID) {
            if (IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
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
            'name'    => 'module_disable',
            'type'    => 'CheckBox',
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

        $lowering_mode = $this->ReadPropertyInteger('lowering_mode');
        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'    => 'monitoring_control',
                    'type'    => 'CheckBox',
                    'caption' => 'Variable to control the ventilation monitoring',
                ],
                [
                    'type'      => 'Label',
                    'caption'   => 'Initial delay',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'name'               => 'delay_timeunit',
                            'type'               => 'Select',
                            'options'            => $this->GetTimeunitAsOptions(),
                            'width'              => '100px',
                            'caption'            => 'Time unit',
                        ],
                        [
                            'name'               => 'delay_value',
                            'type'               => 'NumberSpinner',
                            'minimum'            => 0,
                            'width'              => '100px',
                            'caption'            => 'Fix value',
                        ],
                        [
                            'type'               => 'Label',
                            'bold'               => true,
                            'width'              => '50px',
                            'caption'            => 'or',
                        ],
                        [
                            'name'               => 'delay_varID',
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER],
                            'caption'            => 'Variable',
                            'width'              => '500px',
                        ],
                    ],
                ],
                [
                    'name'     => 'lowering_mode',
                    'type'     => 'Select',
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
                    'caption'  => 'Lowering mode',
                ],
                [
                    'name'    => 'lowering_temperature',
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'name'    => 'lowering_temp_value',
                            'type'    => 'NumberSpinner',
                            'digits'  => 1,
                            'minimum' => 0,
                            'maximum' => 30,
                            'caption' => 'Fix value'
                        ],
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'width'   => '50px',
                            'caption' => 'or'
                        ],
                        [
                            'name'               => 'lowering_temp_varID',
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_FLOAT],
                            'caption'            => 'Variable',
                        ],
                    ],
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_temperature'),
                    'caption' => 'Lowering temperatur',
                ],
                [
                    'name'    => 'lowering_trigger',
                    'type'    => 'NumberSpinner',
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_trigger'),
                    'caption' => 'Trigger value',
                ],
                [
                    'name'    => 'lowering_scriptID',
                    'type'    => 'SelectScript',
                    'visible' => $this->LoweringFieldsIsVisible($lowering_mode, 'lowering_scriptID'),
                    'caption' => 'Script to lower temperatur',
                ],
                [
                    'name'     => 'lowering_targets',
                    'type'     => 'List',
                    'rowCount' => 3,
                    'add'      => true,
                    'delete'   => true,
                    'columns'  => [
                        [
                            'name'    => 'varID',
                            'add'     => '',
                            'edit'    => [
                                'type'               => 'SelectVariable',
                                'validVariableTypes' => [VARIABLETYPE_FLOAT],
                            ],
                            'width'   => '500px',
                            'caption' => 'Variable',
                        ],
                    ],
                    'caption'  => 'Target variables',
                ],
                [
                    'name'        => 'durations',
                    'type'        => 'List',
                    'rowCount'    => 3,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => true,
                    'columns'     => [
                        [
                            'name'    => 'max_temp',
                            'add'     => 18,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'digits'  => 1,
                                'minimum' => 0,
                                'maximum' => 30,
                                'suffix'  => '°C',
                            ],
                            'width'   => '200px',
                            'caption' => 'Upper temperature limit',
                        ],
                        [
                            'name'    => 'condition',
                            'add'     => '',
                            'edit'    => [
                                'type'    => 'SelectCondition',
                                'multi'   => true,
                            ],
                            'width'   => 'auto',
                            'caption' => 'Complex condition',
                        ],
                        [
                            'name'    => 'open',
                            'add'     => 30,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'minimum' => 0,
                            ],
                            'width'   => '200px',
                            'caption' => 'Duration at "open"',
                        ],
                        [
                            'name'    => 'tilt',
                            'add'     => 30,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'minimum' => 0,
                            ],
                            'width'   => '200px',
                            'caption' => 'Duration at "tilt"',
                        ],
                        [
                            'name'    => 'duration_timeunit',
                            'add'     => self::$TIMEUNIT_MINUTES,
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => $this->GetTimeunitAsOptions(),
                            ],
                            'width'   => '200px',
                            'caption' => 'Time unit',
                        ],
                    ],
                    'sort'     => [
                        'column'    => 'max_temp',
                        'direction' => 'ascending'
                    ],
                    'caption'  => 'Duration of ventilation until messaging',
                ],
            ],
            'caption' => 'Lower temperatur',
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'    => 'with_reduce_humidity',
                    'type'    => 'CheckBox',
                    'caption' => 'Provide information for "Reduce humidity possible"',
                ],
                [
                    'type'    => 'Label',
                ],
                [
                    'name'    => 'with_risk_of_mold',
                    'type'    => 'CheckBox',
                    'caption' => 'Provide information for "Risk of mold"',
                ],
                [
                    'name'    => 'thermal_resistance',
                    'type'    => 'NumberSpinner',
                    'digits'  => 3,
                    'minimum' => 0,
                    'suffix'  => 'm²*K/W',
                    'caption' => 'Total thermal resistance of outer wall',
                ],
                [
                    'name'    => 'mold_hum_min',
                    'type'    => 'NumberSpinner',
                    'digits'  => 0,
                    'minimum' => 0,
                    'maximum' => 100,
                    'suffix'  => '%',
                    'caption' => 'Minimum of air humidity for mold warning',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'See also in the expert area',
                ],
            ],
            'caption' => 'Humidity',
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'               => 'outside_temp_varID',
                    'type'               => 'SelectVariable',
                    'validVariableTypes' => [VARIABLETYPE_FLOAT],
                    'width'              => '500px',
                    'caption'            => 'Outside temperature',
                ],
                [
                    'name'               => 'outside_hum_varID',
                    'type'               => 'SelectVariable',
                    'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                    'width'              => '500px',
                    'caption'            => 'Outside humidity',
                ],
                [
                    'name'               => 'indoor_temp_varID',
                    'type'               => 'SelectVariable',
                    'validVariableTypes' => [VARIABLETYPE_FLOAT],
                    'width'              => '500px',
                    'caption'            => 'Indoor temperature',
                ],
                [
                    'name'               => 'indoor_hum_varID',
                    'type'               => 'SelectVariable',
                    'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                    'width'              => '500px',
                    'caption'            => 'Indoor humidity',
                ],
                [
                    'name'               => 'air_pressure_varID',
                    'type'               => 'SelectVariable',
                    'validVariableTypes' => [VARIABLETYPE_FLOAT],
                    'width'              => '500px',
                    'caption'            => 'Air pressure',
                ],
                [
                    'type'    => 'Label',
                ],
                [
                    'name'    => 'with_calculations',
                    'type'    => 'CheckBox',
                    'caption' => 'Create variables for calculated values'
                ],
            ],
            'caption' => 'Measured values',
        ];

        $formElements[] = [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'items'    => [
                [
                    'name'      => 'notice_script',
                    'type'      => 'ScriptEditor',
                    'rowCount'  => 10,
                    'caption'   => 'Script to call after the specified duration has elapsed',
                ],
                [
                    'type'      => 'Label',
                    'caption'   => 'Pause until repeated notification',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'name'               => 'pause_timeunit',
                            'type'               => 'Select',
                            'options'            => $this->GetTimeunitAsOptions(),
                            'width'              => '100px',
                            'caption'            => 'Time unit',
                        ],
                        [
                            'name'               => 'pause_value',
                            'type'               => 'NumberSpinner',
                            'minimum'            => 0,
                            'width'              => '100px',
                            'caption'            => 'Fix value',
                        ],
                        [
                            'type'               => 'Label',
                            'bold'               => true,
                            'width'              => '50px',
                            'caption'            => 'or',
                        ],
                        [
                            'name'               => 'pause_varID',
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER],
                            'caption'            => 'Variable',
                            'width'              => '500px',
                        ],
                    ],
                ],
            ],
            'caption' => 'Notification',
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

        $values = $this->GetAllValues();
        $varnames = [
            'OutsideTemperature'      => ['Outside temperature', ' °C'],
            'OutsideHumidity'         => ['Outside humidity', ' %'],
            'OutsideAbsoluteHumidity' => ['Outside absolute humidity', ' g/m³'],
            'OutsideSpecificHumidity' => ['Outside specific humidity', ' g/kg'],
            'OutsideDewpoint'         => ['Outside dewpoint', ' °C'],
            'IndoorTemperature'       => ['Indoor temperature', ' °C'],
            'IndoorHumidity'          => ['Indoor humidity', ' %'],
            'IndoorAbsoluteHumidity'  => ['Indoor absolute humidity', ' g/m³'],
            'IndoorSpecificHumidity'  => ['Indoor specific humidity', ' g/kg'],
            'IndoorDewpoint'          => ['Indoor temperature', ' °C'],
            'WallTemperature'         => ['Wall temperature on the inner side', ' °C'],
            'AirPressure'             => ['Air pressure', ' mbar'],
        ];

        $vars_rows = [];
        foreach ($varnames as $varname => $opts) {
            if (isset($values[$varname]) == false) {
                continue;
            }
            $vars_rows[] = [
                'varname'  => $this->Translate($opts[0]),
                'varvalue' => $values[$varname] . $opts[1],
            ];
        }

        $vars_item = [
            'type'     => 'List',
            'columns'  => [
                [
                    'name'     => 'varname',
                    'width'    => '400px',
                    'caption'  => 'Name',
                ],
                [
                    'name'     => 'varvalue',
                    'width'    => 'auto',
                    'caption'  => 'Value',
                ],
            ],
            'add'      => false,
            'delete'   => false,
            'rowCount' => count($vars_rows),
            'values'   => $vars_rows,
            'caption'  => 'internal informations',
        ];

        $outside_temp = 0;
        $indoor_temp = 0;
        $outside_temp_varID = $this->ReadPropertyInteger('outside_temp_varID');
        if (IPS_VariableExists($outside_temp_varID)) {
            $outside_temp = GetValueFloat($outside_temp_varID);
        }
        $indoor_temp_varID = $this->ReadPropertyInteger('indoor_temp_varID');
        if (IPS_VariableExists($indoor_temp_varID)) {
            $indoor_temp = GetValueFloat($indoor_temp_varID);
        }

        $calc_item = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'    => 'NumberSpinner',
                    'digits'  => 1,
                    'minimum' => 0,
                    'maximum' => 30,
                    'value'   => $outside_temp,
                    'suffix'  => '°C',
                    'name'	   => 'outside_temp',
                    'caption' => 'Outside temperature',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'digits'  => 1,
                    'minimum' => 0,
                    'maximum' => 30,
                    'value'   => $indoor_temp,
                    'suffix'  => '°C',
                    'name'	   => 'indoor_temp',
                    'caption' => 'Indoor temperature',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'digits'  => 1,
                    'minimum' => 0,
                    'maximum' => 30,
                    'suffix'  => '°C',
                    'name'	   => 'wall_temp',
                    'caption' => 'Outer wall temperature on the inner side',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Calculate thermal resistance',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "CalcThermalResistance", json_encode(["outside_temp" => $outside_temp, "indoor_temp" => $indoor_temp, "wall_temp" => $wall_temp]));',
                ],
            ],
        ];
        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded ' => false,
            'items'     => [
                $calc_item,
                $this->GetInstallVarProfilesFormItem(),
                $vars_item,
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
                ];
                break;
            case self::$LOWERING_MODE_TRIGGER:
                $visible_flds = [
                    'lowering_trigger',
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
                $fields = [
                    'lowering_temperature',
                    'lowering_trigger',
                    'lowering_scriptID',
                ];
                foreach ($fields as $field) {
                    $b = $this->LoweringFieldsIsVisible($value, $field);
                    $this->UpdateFormField($field, 'visible', $b);
                }
                break;
            case 'CalcThermalResistance':
                $this->CalcThermalResistance($value);
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
            case 'MonitorVentilation':
                $this->SetValue($ident, $value);
                $this->CheckConditions();
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function AdjustTemperature($lower, &$jstate)
    {
        $lowering_mode = $this->ReadPropertyInteger('lowering_mode');

        if ($lower) {
            $save = [];
            $lowering_targets = json_decode($this->ReadPropertyString('lowering_targets'), true);
            if ($lowering_targets != false) {
                foreach ($lowering_targets as $target) {
                    $varID = $target['varID'];
                    if (IPS_VariableExists($varID)) {
                        $save[$varID] = GetValue($varID);
                    }
                }
                switch ($lowering_mode) {
                    case self::$LOWERING_MODE_TEMP:
                        $varID = $this->ReadPropertyInteger('lowering_temp_varID');
                        if (IPS_VariableExists($varID)) {
                            $val = GetValueFloat($varID);
                        } else {
                            $val = $this->ReadPropertyFloat('lowering_temp_value');
                        }
                        foreach ($lowering_targets as $target) {
                            $varID = $target['varID'];
                            if (IPS_VariableExists($varID)) {
                                RequestAction($varID, $val);
                                $this->SendDebug(__FUNCTION__, 'RequestAction(' . $varID . ' ' . IPS_GetLocation($varID) . ', ' . $val . ')', 0);
                            }
                        }
                        break;
                    case self::$LOWERING_MODE_TRIGGER:
                        $val = $this->ReadPropertyInteger('lowering_trigger');
                        foreach ($lowering_targets as $target) {
                            $varID = $target['varID'];
                            if (IPS_VariableExists($varID)) {
                                RequestAction($varID, $val);
                                $this->SendDebug(__FUNCTION__, 'RequestAction(' . $varID . ' ' . IPS_GetLocation($varID) . ', ' . $val . ')', 0);
                            }
                        }
                        break;
                    case self::$LOWERING_MODE_SCRIPT:
                        $lowering_scriptID = $this->ReadPropertyInteger('lowering_scriptID');
                        if (IPS_ScriptExists($lowering_scriptID)) {
                            $params = [
                                'lower'      => $lower,
                                'save'       => json_encode($jstate['save']),
                                'instanceID' => $this->InstanceID,
                            ];
                            @$r = IPS_RunScriptWaitEx($lowering_scriptID, $params);
                            $this->SendDebug(__FUNCTION__, 'IPS_RunScriptWaitEx(' . $lowering_scriptID . ', ' . print_r($params, true) . ') ' . ($r ? 'succeed' : 'failed'), 0);
                            if ($r != false) {
                                @$j = json_decode($r, true);
                                if ($j != false) {
                                    $this->SendDebug(__FUNCTION__, 'result=' . print_r($j, true), 0);
                                    if (isset($j['save'])) {
                                        $save = $j['save'];
                                    }
                                }
                            }
                        }
                        break;
                }
            }
            $jstate['save'] = $save;
            $jstate['step'] = 'lowered';
            $this->SendDebug(__FUNCTION__, 'saved=' . print_r($save, true), 0);
        } else {
            $save = isset($jstate['save']) ? $jstate['save'] : [];
            switch ($lowering_mode) {
                case self::$LOWERING_MODE_TEMP:
                    foreach ($save as $varID => $val) {
                        if (IPS_VariableExists($varID)) {
                            RequestAction($varID, $val);
                            $this->SendDebug(__FUNCTION__, 'RequestAction(' . $varID . ' ' . IPS_GetLocation($varID) . ', ' . $val . ')', 0);
                        }
                    }
                    break;
                case self::$LOWERING_MODE_TRIGGER:
                    foreach ($save as $varID => $val) {
                        if (IPS_VariableExists($varID)) {
                            RequestAction($varID, $val);
                            $this->SendDebug(__FUNCTION__, 'RequestAction(' . $varID . ' ' . IPS_GetLocation($varID) . ', ' . $val . ')', 0);
                        }
                    }
                    break;
                case self::$LOWERING_MODE_SCRIPT:
                    $lowering_scriptID = $this->ReadPropertyInteger('lowering_scriptID');
                    if (IPS_ScriptExists($lowering_scriptID)) {
                        $params = [
                            'lower'      => $lower,
                            'save'       => json_encode($jstate['save']),
                            'instanceID' => $this->InstanceID,
                        ];
                        @$r = IPS_RunScriptWaitEx($lowering_scriptID, $params);
                        $this->SendDebug(__FUNCTION__, 'IPS_RunScriptWaitEx(' . $lowering_scriptID . ', ' . print_r($params, true) . ') ' . ($r ? 'succeed' : 'failed'), 0);
                        if ($r != false) {
                            @$j = json_decode($r, true);
                            if ($j != false) {
                                $this->SendDebug(__FUNCTION__, 'result=' . print_r($j, true), 0);
                                if (isset($j['save'])) {
                                    $save = $j['save'];
                                }
                            }
                        }
                    }
                    break;
            }
            $this->SendDebug(__FUNCTION__, 'resumed=' . print_r($save, true), 0);
            $jstate['save'] = [];
            $jstate['step'] = 'inactive';
        }
    }

    private function CheckConditions()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->CalcDuration();
        if (IPS_SemaphoreEnter(self::$semaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . self::$semaphoreID . ' is not accessable', 0);
            return;
        }

        $is_open = false;
        $is_tilt = false;
        $conditionS = '';

        $open_conditions = $this->ReadPropertyString('open_conditions');
        if (json_decode($open_conditions, true) != false) {
            $is_open = IPS_IsConditionPassing($open_conditions);
            $conditionS .= 'open-conditions ' . ($is_open ? 'passed' : 'blocked');
        } else {
            $conditionS .= 'no open-conditions';
        }

        $conditionS .= ', ';

        $tilt_conditions = $this->ReadPropertyString('tilt_conditions');
        if (json_decode($tilt_conditions, true) != false) {
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

        $oldClosureState = $this->GetValue('ClosureState');
        $oldTriggerTime = $this->GetValue('TriggerTime');

        $monitoring_control = $this->ReadPropertyBoolean('monitoring_control');
        $loweringEnabled = $monitoring_control ? $this->GetValue('MonitorVentilation') : true;

        $this->SendDebug(__FUNCTION__, $conditionS . ' => closureState=' . $closureState . ', enabled=' . $this->bool2str($loweringEnabled), 0);

        $jstate = json_decode($this->ReadAttributeString('state'), true);
        $this->SendDebug(__FUNCTION__, 'old state=' . print_r($jstate, true), 0);

        if ($loweringEnabled) {
            if (($closureState != $oldClosureState) || ($oldTriggerTime == 0 && $closureState != self::$CLOSURE_STATE_CLOSE)) {
                $oldClosureStateS = $this->GetValueFormatted('ClosureState');

                $this->SetValue('ClosureState', $closureState);
                $this->SetValue('TriggerTime', $closureState == self::$CLOSURE_STATE_CLOSE ? 0 : time());

                $closureStateS = $this->GetValueFormatted('ClosureState');
                $conditionS .= ': closureState=' . $closureStateS . ' (old=' . $oldClosureStateS . ')';

                if ($closureState != self::$CLOSURE_STATE_CLOSE) {
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
                        $jstate['step'] = 'delay';
                        $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                        $this->WriteAttributeString('state', json_encode($jstate));
                        $this->MaintainTimer('LoopTimer', $sec * 1000);
                    } else {
                        $duration = $this->CalcDuration();
                        if ($duration > 0) {
                            $msg = $conditionS . ', started ventilation phase of ' . $duration . 's';
                        } else {
                            $msg = $conditionS . ', started ventilation phase with no duration';
                        }
                        $this->AdjustTemperature(true, $jstate);
                        $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                        $this->WriteAttributeString('state', json_encode($jstate));
                        $this->MaintainTimer('LoopTimer', $duration * 1000);
                    }
                } else {
                    if (isset($jstate['step']) == false || $jstate['step'] != 'inactive') {
                        $msg = $conditionS . ' => stop timer';
                        $this->AdjustTemperature(false, $jstate);
                        $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                        $this->WriteAttributeString('state', json_encode($jstate));
                    } else {
                        $msg = $conditionS . ' => no timer';
                    }
                    $this->MaintainTimer('LoopTimer', 0);
                }

                $this->SendDebug(__FUNCTION__, $msg, 0);
                $this->AddModuleActivity($msg);
            }
        } else {
            $this->SetValue('ClosureState', $closureState);
            if ($oldTriggerTime) {
                $this->SetValue('TriggerTime', 0);
                $msg = $conditionS . ' => stop timer';
                $this->AdjustTemperature(false, $jstate);
                $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                $this->WriteAttributeString('state', json_encode($jstate));
                $this->MaintainTimer('LoopTimer', 0);
            }
        }

        IPS_SemaphoreLeave(self::$semaphoreID);

        $values = $this->GetAllValues();

        $with_calculations = $this->ReadPropertyBoolean('with_calculations');
        if ($with_calculations) {
            $varnames = [
                'OutsideAbsoluteHumidity',
                'OutsideDewpoint',
                'OutsideSpecificHumidity',
                'IndoorAbsoluteHumidity',
                'IndoorSpecificHumidity',
                'IndoorDewpoint',
            ];
            foreach ($varnames as $varname) {
                if (isset($values[$varname]) == false) {
                    continue;
                }
                $this->SetValue($varname, $values[$varname]);
            }
        }

        $with_reduce_humidity = $this->ReadPropertyBoolean('with_reduce_humidity');
        if ($with_reduce_humidity) {
            $varnames = [
                'ReduceHumidityPossible',
            ];
            foreach ($varnames as $varname) {
                if (isset($values[$varname]) == false) {
                    continue;
                }
                $this->SetValue($varname, $values[$varname]);
            }
        }

        $with_risk_of_mold = $this->ReadPropertyBoolean('with_risk_of_mold');
        if ($with_risk_of_mold) {
            $varnames = [
                'RiskOfMold',
            ];
            foreach ($varnames as $varname) {
                if (isset($values[$varname]) == false) {
                    continue;
                }
                $this->SetValue($varname, $values[$varname]);
            }
        }
    }

    private function CheckTimer()
    {
        if (IPS_SemaphoreEnter(self::$semaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . self::$semaphoreID . ' is not accessable', 0);
            return;
        }

        $jstate = json_decode($this->ReadAttributeString('state'), true);
        $this->SendDebug(__FUNCTION__, 'old state=' . print_r($jstate, true), 0);

        $closureState = $this->GetValue('ClosureState');
        $msg = 'closureState=' . $this->GetValueFormatted('ClosureState');
        if ($closureState != self::$CLOSURE_STATE_CLOSE) {
            if (isset($jstate['step']) == false || $jstate['step'] == 'delay') {
                $duration = $this->CalcDuration();
                if ($duration > 0) {
                    $msg .= ', started ventilation phase of ' . $duration . 's';
                } else {
                    $msg .= ', started ventilation phase with no duration';
                }
                $this->AdjustTemperature(true, $jstate);
                $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                $this->WriteAttributeString('state', json_encode($jstate));
                $this->MaintainTimer('LoopTimer', $duration * 1000);
            } else {
                $duration = $this->CalcDuration();
                if ($duration > 0) {
                    $notice_script = $this->ReadPropertyString('notice_script');
                    if ($notice_script != false) {
                        $msg .= ', make notification';
                        $params = [
                            'instanceID' => $this->InstanceID,
                        ];
                        $lowering_targets = json_decode($this->ReadPropertyString('lowering_targets'), true);
                        if ($lowering_targets != false) {
                            $targets = [];
                            foreach ($lowering_targets as $target) {
                                $varID = $target['varID'];
                                if (IPS_VariableExists($varID)) {
                                    $targets[] = $varID;
                                }
                            }
                            $params['targets'] = implode(',', $targets);
                        }
                        @$r = IPS_RunScriptTextWaitEx($notice_script, $params);
                        $this->SendDebug(__FUNCTION__, 'script("...", ' . print_r($params, true) . ' => ' . $r, 0);
                        $jstate['step'] = 'notified';

                        $sec = 0;
                        $varID = $this->ReadPropertyInteger('pause_varID');
                        if (IPS_VariableExists($varID)) {
                            $tval = GetValueInteger($varID);
                        } else {
                            $tval = $this->ReadPropertyInteger('pause_value');
                        }
                        if ($tval > 0) {
                            if (isset($jstate['repetition']) == false) {
                                $jstate['repetition'] = 0;
                            } else {
                                $jstate['repetition']++;
                                $unit = $this->ReadPropertyInteger('pause_timeunit');
                                $sec = $this->CalcByTimeunit($unit, $tval);
                                $tvS = $tval . $this->Timeunit2Suffix($unit);
                                $msg .= ', repeat notification after ' . $tvS;
                            }
                        }

                        $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                        $this->WriteAttributeString('state', json_encode($jstate));
                        $this->MaintainTimer('LoopTimer', $sec * 1000);
                    } else {
                        $this->SendDebug(__FUNCTION__, 'no notice-script', 0);
                        $this->MaintainTimer('LoopTimer', 0);
                    }
                }
            }
        } else {
            if (isset($jstate['step']) == false || $jstate['step'] != 'inactive') {
                $msg .= ' => stop timer';
                $this->AdjustTemperature(false, $jstate);
                $this->SendDebug(__FUNCTION__, 'new state=' . print_r($jstate, true), 0);
                $this->WriteAttributeString('state', json_encode($jstate));
            } else {
                $msg .= ' => clear timer';
            }
            $this->MaintainTimer('LoopTimer', 0);
        }

        $this->SendDebug(__FUNCTION__, $msg, 0);
        $this->AddModuleActivity($msg);

        IPS_SemaphoreLeave(self::$semaphoreID);
    }

    private function CalcThermalResistance($params)
    {
        $this->SendDebug(__FUNCTION__, 'params=' . print_r($params, true), 0);
        $jparams = json_decode($params, true);

        if (isset($jparams['outside_temp']) == false) {
            $msg = $this->Translate('missing outside temperature');
            $this->PopupMessage($msg);
            return;
        }
        $outside_temp = (float) $jparams['outside_temp'];

        if (isset($jparams['indoor_temp']) == false) {
            $msg = $this->Translate('missing indoor temperature');
            $this->PopupMessage($msg);
            return;
        }
        $indoor_temp = (float) $jparams['indoor_temp'];

        if (isset($jparams['wall_temp']) == false) {
            $msg = $this->Translate('missing wall temperature');
            $this->PopupMessage($msg);
            return;
        }
        $wall_temp = (float) $jparams['wall_temp'];

        @$Rges = 0.13 * (($outside_temp - $indoor_temp) / ($wall_temp - $indoor_temp));
        if (is_float($Rges) == false || is_infinite($Rges) || is_nan($Rges)) {
            $msg = $this->Translate('The value is not calculable');
            $this->PopupMessage($msg);
            return;
        }

        $Rges = round($Rges * 1000) / 1000;

        $msg = $this->Translate('The total thermal resistance of the wall is') . ' ' . $Rges . ' m²*K/W';
        $this->PopupMessage($msg);
    }

    private function CalcDuration()
    {
        $sec = 0;

        $state = $this->GetValue('ClosureState') == self::$CLOSURE_STATE_TILT ? 'tilt' : 'open';

        $durations = json_decode($this->ReadPropertyString('durations'), true);
        if ($durations != false) {
            $varID = $this->ReadPropertyInteger('outside_temp_varID');
            $match = false;
            if (IPS_VariableExists($varID)) {
                $temp = GetValueFloat($varID);
            }
            foreach ($durations as $duration) {
                $passed = true;
                if ($passed) {
                    $condition = $duration['condition'];
                    $this->SendDebug(__FUNCTION__, 'condition=' . $condition, 0);
                    if (json_decode($condition, true) != false) {
                        $passed = IPS_IsConditionPassing($condition);
                        $this->SendDebug(__FUNCTION__, 'condition passed=' . $this->bool2str($passed), 0);
                    }
                }
                if ($passed) {
                    if (IPS_VariableExists($varID)) {
                        $passed = $temp <= $duration['max_temp'];
                        $this->SendDebug(__FUNCTION__, 'max_temp=' . $duration['max_temp'] . ', passed=' . $this->bool2str($passed), 0);
                    }
                }
                if ($passed) {
                    $sec = $this->CalcByTimeunit($duration['duration_timeunit'], $duration[$state]);
                    $match = true;
                    break;
                }
            }
            if ($match == false) {
                if (count($durations) > 0) {
                    $duration = $durations[0];
                    $sec = $this->CalcByTimeunit($duration['duration_timeunit'], $duration[$state]);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'duration=' . $sec . 's', 0);
        return $sec;
    }

    private function GetAllValues()
    {
        $values = [];

        $outside_temp_varID = $this->ReadPropertyInteger('outside_temp_varID');
        $outside_hum_varID = $this->ReadPropertyInteger('outside_hum_varID');
        $indoor_temp_varID = $this->ReadPropertyInteger('indoor_temp_varID');
        $indoor_hum_varID = $this->ReadPropertyInteger('indoor_hum_varID');
        $air_pressure_varID = $this->ReadPropertyInteger('air_pressure_varID');
        $thermal_resistance = $this->ReadPropertyFloat('thermal_resistance');
        $mold_hum_min = $this->ReadPropertyFloat('mold_hum_min');

        if (IPS_VariableExists($outside_temp_varID) && IPS_VariableExists($outside_hum_varID)) {
            $outside_temp = GetValueFloat($outside_temp_varID);
            $values['OutsideTemperature'] = $outside_temp;

            $outside_hum = (float) GetValue($outside_hum_varID);
            $values['OutsideHumidity'] = $outside_hum;

            $outside_absolute_hum = $this->CalcAbsoluteHumidity($outside_temp, $outside_hum);
            $values['OutsideAbsoluteHumidity'] = $outside_absolute_hum;

            $outside_dewpoint = $this->CalcDewpoint($outside_temp, $outside_hum);
            $values['OutsideDewpoint'] = $outside_dewpoint;

            if (IPS_VariableExists($air_pressure_varID)) {
                $air_pressure = GetValueFloat($air_pressure_varID);
                $values['AirPressure'] = $air_pressure;

                $outside_specific_hum = $this->CalcSpecificHumidity($outside_temp, $outside_hum, $air_pressure);
                $values['OutsideSpecificHumidity'] = $outside_specific_hum;
            }

            if (IPS_VariableExists($indoor_temp_varID) && IPS_VariableExists($indoor_hum_varID)) {
                $indoor_temp = GetValueFloat($indoor_temp_varID);
                $values['IndoorTemperature'] = $indoor_temp;

                $indoor_hum = (float) GetValue($indoor_hum_varID);
                $values['IndoorHumidity'] = $indoor_hum;

                $indoor_absolute_hum = $this->CalcAbsoluteHumidity($indoor_temp, $indoor_hum);
                $values['IndoorAbsoluteHumidity'] = $indoor_absolute_hum;

                $indoor_dewpoint = $this->CalcDewpoint($indoor_temp, $indoor_hum);
                $values['IndoorDewpoint'] = $indoor_dewpoint;

                if (IPS_VariableExists($air_pressure_varID)) {
                    $indoor_specific_hum = $this->CalcSpecificHumidity($indoor_temp, $indoor_hum, $air_pressure);
                    $values['IndoorSpecificHumidity'] = $indoor_specific_hum;

                    $reduce_possible = $outside_specific_hum <= ($indoor_specific_hum - 0.8 /* Hysterese */);
                    $values['ReduceHumidityPossible'] = $reduce_possible;
                }

                if ($thermal_resistance > 0) {
                    $wall_temp = $indoor_temp + ((0.13 / $thermal_resistance) * ($outside_temp - $indoor_temp));
                    $values['WallTemperature'] = $wall_temp;

                    if ($mold_hum_min == 0 || $indoor_hum > $mold_hum_min) {
                        $tdif = $wall_temp - $indoor_dewpoint;
                        if ($tdif > 2) {
                            $mold_risk = self::$RISK_OF_MOLD_NONE;
                        } elseif ($tdif > 1) {
                            $mold_risk = self::$RISK_OF_MOLD_WARN;
                        } else {
                            $mold_risk = self::$RISK_OF_MOLD_ALARM;
                        }
                    } else {
                        $mold_risk = self::$RISK_OF_MOLD_NONE;
                    }
                    $values['RiskOfMold'] = $mold_risk;
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'values=' . print_r($values, true), 0);
        return $values;
    }

    // Taupunkt berechnen
    //   Quelle: https://www.wetterochs.de/wetter/feuchte.html
    private function CalcDewpoint(float $temp, float $humidity)
    {
        if ($temp > 0) {
            $k2 = 17.62;
            $k3 = 243.12;
        } else {
            $k2 = 22.46;
            $k3 = 272.62;
        }
        $dewpoint = $k3 * (($k2 * $temp) / ($k3 + $temp) + log($humidity / 100));
        $dewpoint = $dewpoint / (($k2 * $k3) / ($k3 + $temp) - log($humidity / 100));
        $dewpoint = round($dewpoint, 0);
        return $dewpoint;
    }

    // relative Luffeuchtigkeit in absolute Feuchte umrechnen
    //   Quelle: https://www.wetterochs.de/wetter/feuchte.html
    private function CalcAbsoluteHumidity(float $temp, float $humidity)
    {
        if ($temp >= 0) {
            $a = 7.5;
            $b = 237.3;
        } else {
            $a = 7.6;
            $b = 240.7;
        }

        $R = 8314.3; // universelle Gaskonstante in J/(kmol*K)
        $mw = 18.016; // Molekulargewicht des Wasserdampfes in kg/kmol

        // Sättigungsdamphdruck in hPa
        $SDD = 6.1078 * pow(10, (($a * $temp) / ($b + $temp)));

        // Dampfdruck in hPa
        $DD = $humidity / 100 * $SDD;

        $v = log10($DD / 6.1078);

        // Taupunkttemperatur in °C
        $TD = $b * $v / ($a - $v);

        // Temperatur in Kelvin
        $TK = $temp + 273.15;

        // absolute Feuchte in g Wasserdampf pro m³ Luft
        $AF = pow(10, 5) * $mw / $R * $DD / $TK;
        $AF = round($AF * 10) / 10; // auf eine NK runden

        return $AF;
    }

    // relative Luffeuchtigkeit in spezifische Feuchte umrechnen
    //   Quelle: https://www.geo.fu-berlin.de/met/service/wetterdaten/luftfeuchtigkeit.html
    //           https://www.cactus2000.de/de/unit/masshum.shtml
    private function CalcSpecificHumidity(float $temp, float $humidity, float $pressure)
    {
        if ($temp >= 0) {
            $a = 7.5;
            $b = 237.3;
        } else {
            $a = 7.6;
            $b = 240.7;
        }

        $R = 8314.3; // universelle Gaskonstante in J/(kmol*K)
        $mw = 18.016; // Molekulargewicht des Wasserdampfes in kg/kmol

        // Sättigungsdamphdruck in hPa
        $SDD = 6.1078 * pow(10, (($a * $temp) / ($b + $temp)));

        // Dampfdruck in hPa
        $DD = $humidity / 100 * $SDD;

        // Spezifische Feuchte in g/kg feuchte Luft
        // Gewicht des Wasserdampfes, der in 1kg feuchter Luft enthalten ist.
        $SF = 0.622 * $DD / ($pressure - 0.378 * $DD) * 1000;
        $SF = round($SF * 100) / 100; // auf zwei NK runden
        return $SF;
    }
}
