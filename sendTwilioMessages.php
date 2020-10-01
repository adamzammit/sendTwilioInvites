<?php
/*
 *    This class implements a plugin that extends Limesurvey v.3 and v.4
 *    The sendTwilioMessages Plugin adds the feature of sending survey invitations to mobile/cell phones via SMS or MMS using the Twilio API
 *    @author: Adam Zammit <adam.zammit@acspri.org.au>
 *    @author: Mira Zeit
 *    @license: MIT
 */

// Require the bundled autoload file - the path may need to change
// based on where you downloaded and unzipped the SDK
require_once __DIR__ . '/twilio-php/src/Twilio/autoload.php';

// Use the REST API Client to make requests to the Twilio REST API
use Twilio\Rest\Client;

class sendTwilioMessages extends \LimeSurvey\PluginManager\PluginBase
{
    // Extension Info
    protected $storage = 'DbStorage';
    static protected $description = "Send invitations/reminders via Twilio";
    static protected $name = 'sendTwilioMessages';


    protected $settings =array(
        'EnableSendTwilio' => array(
            'type' => 'select',
            'options'=>array(
                0=>'No',
                1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Enable sending Twilio invitations and reminders?',
            'help'=>'Overwritable in each Survey setting',
        ),
        'bDebugMode' => array(
            'type' => 'select',
            'options'=>array(
                0=>'No',
                1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Enable debugging',
            'help'=>'Choose Yes to have errors / messages displayed',
        ),
        'authsid' => array (
            'type' => 'string',
            'default' => '',
            'label' => 'REQUIRED: The Account SID for your Twilio project',
            'help' => 'Check on your Twilio Dashboard for ACCOUNT SID'
        ),

        'authtoken' => array (
            'type' => 'string',
            'default' => '',
            'label' => 'REQUIRED: The Auth Token for your Twilio project',
            'help' => 'Check on your Twilio Dashboard for AUTH TOKEN - you may have to click on Show to see it'
        ),
        'twilionumber' => array (
            'type' => 'string',
            'default' => '',
            'label' => 'REQUIRED: The number you own that has capabilities to send SMS/MMS',
            'help' => 'Check on your Twilio Dashboard for this number - it will look like: +15558675310'
        ),


        'SMSInvitationText'=>array(
            'type'=>'text',
            'label'=>'SMS Invitation Text: Enter the default message body to be sent to survey participant when sending an SMS Invitation :',
            'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
            'default'=>"Dear {FIRSTNAME} {LASTNAME}, \n We invite you to participate in the survey: \n {SURVEYURL}",
        ),

        'SMSReminderText'=>array(
            'type'=>'text',
            'label'=>'SMS Reminder Text: Enter the default message body to be sent to survey participant when sending an SMS Reminder :',
            'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
            'default'=>"Dear {FIRSTNAME} {LASTNAME}, \n A friendly reminder to please participate in the survey: \n {SURVEYURL}",
        ),

        'MMSInvitationText'=>array(
            'type'=>'text',
            'label'=>'MMS Invitation Text: Enter the default message body to be sent to survey participant when sending an MMS Invitation :',
            'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
            'default'=>"Dear {FIRSTNAME} {LASTNAME}, \n We invite you to participate in the survey: \n {SURVEYURL} \n Survey Team",
        ),

        'MMSInvitationImage'=>array(
            'type'=>'string',
            'label'=>'MMS Invitation Image: A publicly accessible full URL to an image to include in the MMS invitation (leave blank for no image):',
            'help' =>'You can load images as a Resource in LimeSurvey then enter the URL here',
            'default'=>"",
        ),

        'MMSReminderText'=>array(
            'type'=>'text',
            'label'=>'MMS Reminder Text: Enter the default message body to be sent to survey participant when sending an MMS Reminder :',
            'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
            'default'=>"Dear {FIRSTNAME} {LASTNAME}, \n A friendly reminder to please participate in the survey: \n {SURVEYURL}",
        ),

        'MMSReminderImage'=>array(
            'type'=>'string',
            'label'=>'MMS Reminder Image: A publicly accessible full URL to an image to include in the MMS reminder (leave blank for no image):',
            'help' =>'You can load images as a Resource in LimeSurvey then enter the URL here',
            'default'=>"",
        ),



    );

    // Register custom function/s
    public function init()
    {
        if($this->get('bDebugMode',null,null,$this->settings['bDebugMode'])==1) {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        }

        $this->subscribe('beforeTokenEmail');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    /**
     * This function handles sending SMS messages
     * If it's an email invite, it doesn't interfere and keeps the settings as they are 
     */
    public function beforeTokenEmail()
    {

        Yii::import('application.helpers.replacements_helper', true);

        $oEvent = $this->getEvent();
        $iSurveyId = (string)$oEvent->get('survey');
        $typeOfEmail = $oEvent->get("type");

        // Before changing any settings we need to check that:
        // 1. the sendSMSService is enabled by the admin OVERALL and for this specific survey
        $pluginEnabled = strcmp($this->get('EnableSendTwilio','survey',$iSurveyId),'1')==0 && strcmp($this->get('EnableSendTwilio',null,null,$this->settings['EnableSendTwilio']['default']),'1')==0;
        // 2. Check the type of email, invitaiton or reminder => send, confirmation just ignore (not included in my plans :/).
        $vaildEmailType = (((strcmp($typeOfEmail,'invitation')==0) or (strcmp($typeOfEmail,'reminder')==0)));
        $ourTokenData = $oEvent->get("token");
        $oToken= $this->api->getTokenById($iSurveyId, $ourTokenData['tid']);
        if($pluginEnabled and $vaildEmailType){
            // Check for SMS attribute enabled
            $smsattribute = intval($this->get('SMSAttribute','survey',$iSurveyId));
            $mmsattribute = intval($this->get('MMSAttribute','survey',$iSurveyId));

            //Note the type of message
            $mtype = "Invitation";
            $ltype = "invite";
            if (strcmp($typeOfEmail,'reminder')==0) {
                $mtype = "Reminder";
                $ltype = "remind";
            }

            $fieldsarray = array();

            $fieldsarray["{SID}"] = $iSurveyId;
            /* mantis #14288 */
            LimeExpressionManager::singleton()->loadTokenInformation($iSurveyId, $ourTokenData['token']);
            foreach ($ourTokenData as $attribute => $value) {
                $fieldsarray['{' . strtoupper($attribute) . '}'] = $value;
            }

            $fieldsarray["{OPTOUTURL}"] = Yii::app()                                                                            ->createAbsoluteUrl("/optout/tokens", array("surveyid" => $iSurveyId, "langcode" => trim($ourTokenData['language']), "token" => $ourTokenData['token']));
            $fieldsarray["{OPTINURL}"] = Yii::app()
                ->createAbsoluteUrl("/optin/tokens", array("surveyid" => $iSurveyId, "langcode" => trim($ourTokenData['language']), "token" => $ourTokenData['token']));
            $fieldsarray["{SURVEYURL}"] = Yii::app()
                ->createAbsoluteUrl("/survey/index", array("sid" => $iSurveyId, "token" => $ourTokenData['token'], "lang" => trim($ourTokenData['language'])));



            if($smsattribute > 0) {
                $message = $this->get('SMS' . $mtype . 'Text');
                $attrib = "attribute_" . $smsattribute;
                $to = $oToken->$attrib;

                if (!empty($message) && !empty($to)) {
                    $smessage = Replacefields($message, $fieldsarray);

                    $this->callTwilio($to,$smessage,$media = false);
                }



            }

            if($mmsattribute > 0) {

                $message = $this->get('MMS' . $mtype . 'Text');
                $attrib = "attribute_" . $mmsattribute;
                $to = $oToken->$attrib;

                if (!empty($message) && !empty($to)) {

                    $smessage = Replacefields($message, $fieldsarray);

                    $this->callTwilio($to,$smessage,$this->get('MMS' . $mtype . 'Image'));
                }


            }

            if(intval($this->get('EmailAlso','survey',$iSurveyId) == 1)) {
                // disable sending email for this token
                $this->event->set("send",false);
            }


        }       
    }

    /**
     *  This function handles sending the http request. 
     *  Proxy settings should be configured. 
     *  The third argument (request_header) is optional
     *  returns the response from the external page/API
     */
    private function callTwilio($to,$body,$media = false)
    {



        $starttime = microtime(true);

        $client = new Client($this->get('authsid'), $this->get('authtoken'));

        $message = [
            // A Twilio phone number you purchased at twilio.com/console
            'from' => $this->get('twilionumber'),
            // the body of the text message you'd like to send
            'body' => $body
        ];

        if ($media !== false && !empty($media)) {
            $message['mediaUrl'] = array($media);
        }

        // Use the client to do fun stuff like send text messages!
        $response = $client->messages->create(
            // the number you'd like to send the message to
            $to,
            $message
        );        

        if ($response === FALSE) {
            die("Error connecting to Twilio to send message");
        }

        $this->debug("Twilio response",$response,$starttime);

        return $response;
    }

    /**
     * This event is fired by the administration panel to gather extra settings
     * available for a survey. These settings override the global settings.
     * The plugin should return setting meta data.
     * @param PluginEvent $event
     */
    public function beforeSurveySettings()
    {
        $event = $this->event;

        $enabledglobal = $this->get('EnableSendTwilio',null,null,$this->settings['EnableSendTwilio']['default']);

        $survey = Survey::model()->findByPk($event->get('survey'));

        $attributesenabled = isset($survey->tokenAttributes['attribute_1']);



        if ($enabledglobal != "1") {
            //disabled globally
            $settings = array(
                'Info' => array(
                    'type' => 'info',
                    'label' => 'Plugin is disabled by the system Administrator',
                    'help' => 'Please request the plugin is enabled at the system level to allow it to be used at the survey level',
                ),
            );
        } else {

            $aoptions = array();
            for($attcount = 1; isset($survey->tokenAttributes['attribute_'.$attcount]); $attcount++) {
                $aoptions[$attcount] = "Atttribute " . $attcount;                
            }

            $settings = array(
                'EnableSendTwilio' => array(
                    'type' => 'select',
                    'options'=>array(
                        0=>'No',
                        1=>'Yes'
                    ),
                    'default'=>0,
                    'label' => 'Enable sending Twilio invitations and reminders?',
                    'help' => $attributesenabled?"Select which attribute contains the numbers to message below":"You must have at least one attribute enabled for this to work",
                    'current' => $this->get('EnableSendTwilio', 'Survey', $event->get('survey'), $this->get('EnableSendTwilio',null,null,$this->settings['EnableSendTwilio']['default'])),
                ),

                'EmailAlso' => array(
                    'type' => 'select',
                    'options'=>array(
                        1=>'No',
                        2=>'Yes'
                    ),
                    'default'=>2,
                    'label' => 'Should an email be sent also?',
                    'help' => 'Set this to No to send Twilio messages only and skip the emails',
                    'current' => $this->get('EmailAlso', 'Survey', $event->get('survey')),
                ),

                'SMSAttribute' => array(
                    'type' => 'select',
                    'options'=>array_merge(array(
                        0=>'Do not send an SMS',
                    ) , $aoptions),
                    'default'=>0,
                    'label' => 'Please select which attribute contains the phone number to send an SMS',
                    'help' => '',
                    'current' => $this->get('SMSAttribute', 'Survey', $event->get('survey')),
                ),

                'MMSAttribute' => array(
                    'type' => 'select',
                    'options'=>array_merge(array(
                        0=>'Do not send an MMS',
                    ) , $aoptions),
                    'default'=>0,
                    'label' => 'Please select which attribute contains the phone number to send an MMS',
                    'help' => '',
                    'current' => $this->get('MMSAttribute', 'Survey', $event->get('survey')),
                ),


                'SMSInvitationText'=>array(
                    'type'=>'text',
                    'label'=>'SMS Invitation Text: Enter the default message body to be sent to survey participant when sending an SMS Invitation :',
                    'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
                    'current' => $this->get('SMSInvitationText', 'Survey', $event->get('survey'), $this->get('SMSInvitationText',null,null,$this->settings['SMSInvitationText']['default'])),
                ),

                'SMSReminderText'=>array(
                    'type'=>'text',
                    'label'=>'SMS Reminder Text: Enter the default message body to be sent to survey participant when sending an SMS Reminder :',
                    'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
                    'current' => $this->get('SMSReminderText', 'Survey', $event->get('survey'), $this->get('SMSReminderText',null,null,$this->settings['SMSReminderText']['default'])),
                ),

                'MMSInvitationText'=>array(
                    'type'=>'text',
                    'label'=>'MMS Invitation Text: Enter the default message body to be sent to survey participant when sending an MMS Invitation :',
                    'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
                    'current' => $this->get('MMSInvitationText', 'Survey', $event->get('survey'), $this->get('MMSInvitationText',null,null,$this->settings['MMSInvitationText']['default'])),
                ),

                'MMSInvitationImage'=>array(
                    'type'=>'string',
                    'label'=>'MMS Invitation Image: A publicly accessible full URL to an image to include in the MMS invitation (leave blank for no image):',
                    'help' =>'You can load images as a Resource in LimeSurvey then enter the URL here',
                    'current' => $this->get('MMSInvitationImage', 'Survey', $event->get('survey'), $this->get('MMSInvitationImage',null,null,$this->settings['MMSInvitationImage']['default'])),
                ),

                'MMSReminderText'=>array(
                    'type'=>'text',
                    'label'=>'MMS Reminder Text: Enter the default message body to be sent to survey participant when sending an MMS Reminder :',
                    'help' =>'You may use the placeholders {FIRSTNAME}, {LASTNAME} and {SURVEYURL}. Overwritable in each Survey setting',
                    'current' => $this->get('MMSReminderText', 'Survey', $event->get('survey'), $this->get('MMSReminderText',null,null,$this->settings['MMSReminderText']['default'])),
                ),

                'MMSReminderImage'=>array(
                    'type'=>'string',
                    'label'=>'MMS Reminder Image: A publicly accessible full URL to an image to include in the MMS reminder (leave blank for no image):',
                    'help' =>'You can load images as a Resource in LimeSurvey then enter the URL here',
                    'current' => $this->get('MMSReminderImage', 'Survey', $event->get('survey'), $this->get('MMSReminderImage',null,null,$this->settings['MMSReminderImage']['default'])),
                ),


            );


        }

        $event->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $settings, 
        ));
    }

    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value)
        {
            /* In order use survey setting, if not set, use global, if not set use default */
            $default=$event->get($name,null,null,isset($this->settings[$name]['default'])?$this->settings[$name]['default']:NULL);
            $this->set($name, $value, 'Survey', $event->get('survey'),$default);
        }
    }        


    private function debug($parameters, $hookSent, $time_start)
    {
        if($this->get('bDebugMode',null,null,$this->settings['bDebugMode'])==1) {
            echo '<pre>';
            var_dump($parameters);
            echo "<br><br> ----------------------------- <br><br>";
            var_dump($hookSent);
            echo "<br><br> ----------------------------- <br><br>";
            echo 'Total execution time in seconds: ' . (microtime(true) - $time_start);
            echo '</pre>';
        }
    }
}
?>
