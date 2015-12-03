<?php

#
# Config
# Trebuie adaugat campul NGAlerid in JIRA si adaugat in ecranul (screen) creare issue
define('JIRA_BASE', '_jira_server_name_');
define('JIRA_PORT', '_jira_server_port_');
define('JIRA_USERNAME', '_jira_server_user');
define('JIRA_PASSWORD', '_jira_server_password_');

define('JIRA_PROJECT_ID', '_jira_server_prject_id_');
define('JIRA_ISSUE_TYPE', '_jira_server_issue_type_');
define('JIRA_FIELD_NGALERTID', '_jira_server_custom_field_');
############################################

function call_jira($url, $type = "GET", $json_call = "") {
    $ch = curl_init();
    $headers = array('Accept: application/json', 'Content-Type: application/json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
    curl_setopt($ch, CURLOPT_PORT, JIRA_PORT);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, JIRA_USERNAME . ":" . JIRA_PASSWORD);

    if ($json_call != "") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_call));
    }
    $result = curl_exec($ch);
    $ch_error = curl_error($ch);
    //echo $url,PHP_EOL;
    //echo json_encode($json_call),PHP_EOL;
    if ($ch_error) {
        echo "cURL Error: $ch_error";
        exit(1);
    } else {
        curl_close($ch);
        //echo $result,PHP_EOL ;
        return $result;
    }
}

function generate_description($type) {

    switch (getenv("NAGIOS_NOTIFICATIONTYPE")) {
        case "PROBLEM":
            $description = "{color:#3b0b0b}*Nagios Problem Alert*{color}\n\n";
            break;
        case "RECOVERY":
            $description = "{color:#0b3b0b}*Nagios Recovery Alert*{color}\n\n";
            break;
        case "ACKNOWLEDGEMENT":
            $description = "{color:#0f5d94}*Nagios Acknowledgement*{color}\n\n";
            break;
        default:
            $description = "{color:#585858}*Unknown Alert*{color}\n\n";
            break;
    }
    $description .= "The following information was provided by Nagios:\n";
    $description .= "* Date & Time: " . getenv("NAGIOS_SHORTDATETIME") . "\n";
    if (getenv("NAGIOS_SERVICEDESC")) {
        // If there is a service description environment variable present, display service-related information.
        $description .= "* Status Information: " . getenv("NAGIOS_SERVICEOUTPUT") . "\n";
        $description .= "* Current Host State: " . getenv("NAGIOS_HOSTSTATE") . "\n";
        $description .= "* Current Service State: " . getenv("NAGIOS_SERVICESTATE") . "\n";
    } else {
        // If no service description environment variable is present, display host-related information.
        $description .= "* Status Information: " . getenv("NAGIOS_HOSTOUTPUT") . "\n";
        $description .= "* Current Host State: " . getenv("NAGIOS_HOSTSTATE") . "\n";
    }
    if (getenv("NAGIOS_NOTIFICATIONTYPE") == "ACKNOWLEDGEMENT") {
        // If this is an Acknowledgement notification, add details about the author and the acknowledgement comment.
        $description .= "* Notification Author: " . getenv("NAGIOS_NOTIFICATIONAUTHOR") . "\n";
        $description .= "* Notification Comment: " . getenv("NAGIOS_NOTIFICATIONCOMMENT") . "\n";
    }
    // All notifications should also have the host IP address details.
    $description .= "* Host Address: " . getenv("NAGIOS_HOSTADDRESS") . "\n";
    // Once the generation of the description is complete, resturn it.
    return $description;
}

function generate_summary() {
    // Local function that generates the issue summary when an issue is created in JIRA.
    if (getenv("NAGIOS_SERVICEDESC")) {
        // If the service description environment variable is present, include the details of the service that has the problem in the summary.
        $summary = "NAGIOS: " . getenv("NAGIOS_SERVICEDESC") . " on " . getenv("NAGIOS_HOSTNAME") . " is " . getenv("NAGIOS_SERVICESTATE");
    } else {
        // If the service description environment variable is not present, details about the host is all that is required in the summary.
        $summary = "NAGIOS: " . getenv("NAGIOS_HOSTNAME") . " is " . getenv("NAGIOS_HOSTSTATE");
    }
    return $summary;
}

function issue_comment($issue_id, $recovery) {
    global $svcissue;
    
    $url = JIRA_BASE . "/rest/api/2/issue/" . $issue_id ."/comment";
    $json_data["body"]= generate_description("comment");


    $issue_comment = call_jira($url, $type = "POST", $json_data);
    if ($issue_comment === FALSE) {
        $errstr = "Unable to initialise cURL session when attempting to create an issue in JIRA! Aborting.";
        trigger_error($errstr, E_USER_ERROR);
        exit(1);
    }

    // If the $recovery variable is true, then the problem has been cleared in Nagios and the problem & issue ID pair can be removed from the history.
    if ($recovery) {
        $url = JIRA_BASE . "/rest/api/2/issue/" . $issue_id . "/transitions?expand=transitions.fields";
        $issue_JSON["transition"]["id"] = 21;
        $issue_comment = call_jira($url, $type = "POST", $issue_JSON);

        if ($issue_comment === FALSE) {
            $errstr = "Unable to initialise cURL session when attempting to create an issue in JIRA! Aborting.";
            trigger_error($errstr, E_USER_ERROR);
            exit(1);
        }

        exit(0);
    }
    exit(0);
}

function issue_create() {
    global $svcissue;
    if ($svcissue) {
        $ngid = getenv("NAGIOS_SERVICEPROBLEMID");
    } else {
        $ngid = getenv("NAGIOS_HOSTPROBLEMID");
    }

    $url = JIRA_BASE . "/rest/api/2/issue/";
    $issue_JSON["fields"]["issuetype"]["id"] = JIRA_ISSUE_TYPE;
    $issue_JSON["fields"]["project"]["id"] = JIRA_PROJECT_ID;
    $issue_JSON["fields"]["priority"]["id"] = "2";
    $issue_JSON["fields"]["summary"] = generate_summary();
    $issue_JSON["fields"]["description"] = generate_description("create");
    $issue_JSON["fields"]["customfield_".JIRA_FIELD_NGALERTID] = $ngid;

    $issue_create = call_jira($url, $type = "POST", $issue_JSON);

    // Ensure that the cURL request was successful.
    if ($issue_create === FALSE) {
        $errstr = "Unable to initialise cURL session when attempting to create an issue in JIRA! Aborting.";
        trigger_error($errstr, E_USER_ERROR);
        exit(1);
    } else {
        $result_json = json_decode($issue_create, TRUE);
        $issue_id = $result_json["id"];
    }

    exit(0);
}

/*
 * 
 * 
 */

if (getenv("NAGIOS_SERVICEDESC")) {
    // We have a service issue.
    $summary = getenv("NAGIOS_SERVICEDESC") . " on " . getenv("NAGIOS_HOSTNAME") . " is " . getenv("NAGIOS_SERVICESTATE");
    $summary = "NG: " . $summary;
    $svcissue = TRUE;
} else if (getenv("NAGIOS_HOSTNAME")) {
    // We have a host issue.
    $summary = getenv("NAGIOS_HOSTNAME") . " is " . getenv("NAGIOS_HOSTSTATE");
    $summary = "NG: " . $summary;
} else {
    // Neither the NAGIOS_SERVICEDESC or NAGIOS_HOSTNAME environment variables were set - which indicates that this scripty wasn't called from Nagios
    $errstr = "Script was called, but there were no Nagios environment variables present. Script was most likely not called from Nagios. Aborting.";
    trigger_error($errstr, E_USER_ERROR);
    exit(1);
}

if ($svcissue) {
    $ngalert_id = getenv("NAGIOS_SERVICEPROBLEMID");
} else {
    $ngalert_id = getenv("NAGIOS_HOSTPROBLEMID");
}

if ($ngalert_id){
    
    $url=JIRA_BASE."/rest/api/2/search/?jql=project=".JIRA_PROJECT_ID."%20and%20cf[".JIRA_FIELD_NGALERTID."]~".$ngalert_id."&fields=id";
    $jira_response = call_jira($url, $type = "GET");
    $result_json = json_decode($jira_response, TRUE);
    
    if ($result_json["total"] >0) {
        $issue_id = $result_json["issues"][0]["id"];
    } else {
        $issue_id = FALSE;
    }
    
}

switch (getenv("NAGIOS_NOTIFICATIONTYPE")) {
    case "PROBLEM":
        if ($issue_id === FALSE) {
            issue_create();
        } else {
            issue_comment($issue_id, FALSE);
        }
        exit(0);
        break;
    case "RECOVERY":
        if ($issue_id === FALSE) {
            exit(0);
        } else {
            issue_comment($issue_id, TRUE);
        }
        exit(0);
    case "ACKNOWLEDGEMENT":

        exit(0);
    default:
        // If any other notification is received (flapping or downtime related), we log the event and ignore the notification.
        $errstr = "Received notification of type " . getenv("NAGIOS_NOTIFICATIONTYPE") . ". Aborting.";
        trigger_error($errstr, E_USER_NOTICE);
        exit(0);
}

$errstr = "Unknown error!";
trigger_error($errstr, E_USER_ERROR);
exit(1);
?>
