<?php

/**
 * PatientAccessOnsiteService handles the generation of patient portal access credentials and the sending of the portal
 * email credentials message.  The code originally came from the create_portallogin.php code and was abstracted into this
 * class.
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2023 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use MyMailer;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Common\Utils\RandomGenUtils;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Patient\Summary\PortalCredentialsTemplateDataFilterEvent;
use OpenEMR\Events\Patient\Summary\PortalCredentialsUpdatedEvent;
use OpenEMR\FHIR\Config\ServerConfig;
use Twig\Environment;

class PatientAccessOnsiteService
{
    private $authUser;
    private $authProvider;

    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var SystemLogger
     */
    private $logger;

    public function __construct()
    {
        $this->authUser = $_SESSION['authUser'];
        $this->authProvider = $_SESSION['authProvider'];
        $this->kernel = $GLOBALS['kernel'];
        $this->twig = (new TwigContainer(null, $this->kernel))->getTwig();
        $this->logger = new SystemLogger();
    }

    public function setTwigEnvironment(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function saveCredentials($pid, $pwd, $userName, $loginUsername)
    {
        $trustedEmail = $this->getTrustedEmailForPid($pid);
        $clear_pass = $pwd;

        $res = sqlStatement("SELECT * FROM patient_access_onsite WHERE pid=?", array($pid));
        // we let module writers know we are about to update the patient portal credentials in case any additional stuff needs to happen
        // such as MFA update, other data tied to the portal credentials etc.
        $preUpdateEvent = new PortalCredentialsUpdatedEvent($pid);
        $preUpdateEvent->setUsername($userName ?? '')
            ->setLoginUsername($loginUsername ?? '');

        $updatedEvent = $this->kernel->getEventDispatcher()->dispatch($preUpdateEvent, PortalCredentialsUpdatedEvent::EVENT_UPDATE_PRE) ?? $preUpdateEvent;
        $query_parameters = array($updatedEvent->getUsername(),$updatedEvent->getLoginUsername());
        $hash = (new AuthHash('auth'))->passwordHash($clear_pass);
        if (empty($hash)) {
            // Something is seriously wrong
            error_log('OpenEMR Error : OpenEMR is not working because unable to create a hash.');
            die("OpenEMR Error : OpenEMR is not working because unable to create a hash.");
        }

        array_push($query_parameters, $hash);
        array_push($query_parameters, $pid);

        EventAuditLogger::instance()->newEvent(
            "patient-access",
            $this->authUser,
            $this->authProvider,
            1,
            "updated credentials",
            $pid,
            'open-emr',
            'dashboard'
        );
        if (sqlNumRows($res)) {
            sqlStatementNoLog("UPDATE patient_access_onsite SET portal_username=?, portal_login_username=?, portal_pwd=?, portal_pwd_status=0 WHERE pid=?", $query_parameters);
        } else {
            sqlStatementNoLog("INSERT INTO patient_access_onsite SET portal_username=?,portal_login_username=?,portal_pwd=?,portal_pwd_status=0,pid=?", $query_parameters);
        }
        $postUpdateEvent = new PortalCredentialsUpdatedEvent($pid);
        $postUpdateEvent->setUsername($updatedEvent->getUsername())
            ->setLoginUsername($updatedEvent->getLoginUsername());

        // we let module writers know we have updated the patient portal credentials in case any additional stuff needs to happen
        // such as MFA update, other data tied to the portal credentials etc.
        $updatedEvent = $this->kernel->getEventDispatcher()->dispatch($preUpdateEvent, PortalCredentialsUpdatedEvent::EVENT_UPDATE_POST) ?? $preUpdateEvent;

        return [
            'uname' => $updatedEvent->getUsername()
            ,'login_uname' => $updatedEvent->getLoginUsername()
            ,'pwd' => $clear_pass
            ,'email_direct' => trim($trustedEmail['email_direct'])
        ];
    }

    public function sendCredentialsEmail($pid, $pwd, $username, $loginUsername, $emailDirect)
    {
        // Create the message
        $fhirServerConfig = new ServerConfig();
        $data = [
            'portal_onsite_two_enable' => $GLOBALS['portal_onsite_two_enable']
            ,'portal_onsite_two_address' => $GLOBALS['portal_onsite_two_address']
            ,'enforce_signin_email' => $GLOBALS['enforce_signin_email']
            ,'uname' => $username
            ,'login_uname' => $loginUsername
            ,'pwd' => $pwd
            ,'email_direct' => trim($emailDirect)
            ,'fhir_address' => $fhirServerConfig->getFhirUrl()
            ,'fhir_requirements_address' => $fhirServerConfig->getFhir3rdPartyAppRequirementsDocument()
        ];

        // we run the twigs through this filterTwigTemplateData function as we want module writers to be able to modify the passed
        // in $data value.  The rendered twig output ($twig->render) is returned from this function.
        $htmlMessage = $this->filterTwigTemplateData($pid, 'emails/patient/portal_login/message.html.twig', $data);
        $plainMessage = $this->filterTwigTemplateData($pid, 'emails/patient/portal_login/message.text.twig', $data);

        if ($this->emailLogin($pid, $htmlMessage, $plainMessage, $this->twig)) {
            return [
                'success' => true
                ,'plainMessage' => $plainMessage
            ];
        } else {
            return [
                'success' => false
                ,'plainMessage' => $plainMessage
            ];
        }
    }


    /**
     * Takes in a twig template and data for a given patient pid and notifies module listeners that we are about to
     * render a twig template and let them modify the data.   Note we do NOT allow the module writer to change the template
     * name here.
     * @param $pid
     * @param $templateName
     * @param $data
     * @return mixed The rendered twig output
     */
    public function filterTwigTemplateData($pid, $templateName, $data)
    {

        $filterEvent = new PortalCredentialsTemplateDataFilterEvent($this->twig, $data);
        $filterEvent->setPid($pid);
        $filterEvent->setData($data);
        $filterEvent->setTemplateName($templateName);
        /**
         * @var \OpenEMR\Core\Kernel
         */
        $kernel = $this->kernel ?? null;
        if (!empty($kernel)) {
            $filterEvent = $this->kernel->getEventDispatcher()->dispatch($filterEvent, PortalCredentialsTemplateDataFilterEvent::EVENT_HANDLE);
        }
        $paramData = $filterEvent->getData();
        if (!is_array($paramData)) {
            $paramData = []; // safety
        }

        return $this->twig->render($templateName, $paramData);
    }

    public function getUniqueTrustedUsernameForPid($pid)
    {

        $trustedEmail = $this->getTrustedEmailForPid($pid);
        $row = $this->getOnsiteCredentialsForPid($pid);
        $trustedUserName = $trustedEmail['email_direct'];
// check for duplicate username
        $dup_check = sqlQueryNoLog("SELECT * FROM patient_access_onsite WHERE pid != ? AND portal_login_username = ?", array($pid, $trustedUserName));
// make unique if needed
        if (!empty($dup_check)) {
            if (strpos($trustedUserName, '@')) {
                $trustedUserName = str_replace("@", "$pid@", $trustedUserName);
            } else {
                // account name will be used and is unique
                $trustedUserName = '';
            }
        }
        if (
            empty($GLOBALS['enforce_signin_email'])
            && empty($row['portal_username'])
        ) {
            $trustedUserName = $row['fname'] . $row['id'];
        }
        return $trustedUserName;
    }

    public function getOnsiteCredentialsForPid($pid)
    {
        $records = QueryUtils::fetchRecords("SELECT pd.*,pao.portal_username, pao.portal_login_username,pao.portal_pwd,pao.portal_pwd_status FROM patient_data AS pd LEFT OUTER JOIN patient_access_onsite AS pao ON pd.pid=pao.pid WHERE pd.pid=?", array($pid));
        return $records[0] ?? null;
    }

    public function getTrustedEmailForPid($pid)
    {
        $trustedEmail = sqlQueryNoLog("SELECT email_direct, email FROM `patient_data` WHERE `pid`=?", array($pid));
        $trustedEmail['email_direct'] = !empty(trim($trustedEmail['email_direct'])) ? text(trim($trustedEmail['email_direct'])) : text(trim($trustedEmail['email']));
        return $trustedEmail;
    }

    public function getRandomPortalPassword()
    {
        return RandomGenUtils::generatePortalPassword();
    }

    private function emailLogin($patient_id, $htmlMsg, $plainMsg, Environment $twig)
    {
        $patientData = sqlQuery("SELECT * FROM `patient_data` WHERE `pid`=?", array($patient_id));
        if ($patientData['hipaa_allowemail'] != "YES" || empty($patientData['email']) || empty($GLOBALS['patient_reminder_sender_email'])) {
            $this->logger->debug(
                "PatientAccessOnSiteService->emailLogin() Skipping email send",
                ['hipaa_allowemail' => $patientData['hipaa_allowemail']
                , 'email' => empty($patientData['email']) ? "email is empty" : "patient has email"
                , 'GLOBALS[patient_reminder_sender_email]' => $GLOBALS['patient_reminder_sender_email']]
            );
            return false;
        }

        if (!($this->validEmail($patientData['email']))) {
            $this->logger->debug("PatientAccessOnSiteService->emailLogin() Skipping email send, email is invalid");
            return false;
        }

        if (!($this->validEmail($GLOBALS['patient_reminder_sender_email']))) {
            $this->logger->debug(
                "PatientAccessOnSiteService->emailLogin() Skipping email send, GLOBALS[patient_reminder_sender_email] is invalid",
                ['GLOBALS[patient_reminder_sender_email]' => $GLOBALS['patient_reminder_sender_email']]
            );
            return false;
        }
        $mail = new MyMailer();
        $pt_name = $patientData['fname'] . ' ' . $patientData['lname'];
        $pt_email = $patientData['email'];
        $email_subject = xl('Access Your Patient Portal') . ' / ' . xl('3rd Party API Access');
        $email_sender = $GLOBALS['patient_reminder_sender_email'];
        $mail->AddReplyTo($email_sender, $email_sender);
        $mail->SetFrom($email_sender, $email_sender);
        $mail->AddAddress($pt_email, $pt_name);
        $mail->Subject = $email_subject;
        $mail->MsgHTML($htmlMsg);
        $mail->AltBody = $plainMsg;
        $mail->IsHTML(true);

        if ($mail->Send()) {
            return true;
        } else {
            $email_status = $mail->ErrorInfo;
            $this->logger->errorLogCaller("Failed to send email through Mymailer ", ['ErrorInfo' => $email_status]);
            return false;
        }
    }

    private function validEmail($email)
    {
        // TODO: OpenEMR has used this validator for 11+ years... should we switch to php's filter_var FILTER_VALIDATE_EMAIL?
        // on January 30th 2023 added the ability to support SMTP label addresses such as myname+label@gmail.com
        // Fixes #6159 (openemr/openemr/issues/6159)
        if (preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-\+]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/i", $email)) {
            return true;
        }

        return false;
    }
}
