<?php
/**
 * LTI_Tool_Provider - PHP class to include in an external tool to handle connections with an LTI 1 compliant tool consumer
 * Copyright (C) 2015  Stephen P Vickers
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * Contact: stephen@spvsoftwareproducts.com
 *
 * Version history:
 *   2.0.00  30-Jun-12  Initial release (replacing version 1.1.01 of BasicLTI_Tool_Provider)
 *   2.1.00   3-Jul-12  Added option to restrict use of consumer key based on tool consumer GUID value
 *                      Added field to record day of last access for each consumer key
 *   2.2.00  16-Oct-12  Added option to return parameters sent in last extension request
 *                      Released under GNU Lesser General Public License, version 3
 *   2.3.00   2-Jan-13  Removed autoEnable property from LTI_Tool_Provider class (including constructor parameter)
 *                      Added LTI_Tool_Provider->setParameterConstraint() method
 *                      Changed references to $_REQUEST to $_POST
 *                      Added LTI_Tool_Consumer->getIsAvailable() method
 *                      Deprecated LTI_Context (use LTI_Resource_Link instead), other references to Context deprecated in favour of Resource_Link
 *   2.3.01   2-Feb-13  Added error callback option to LTI_Tool_Provider class
 *                      Fixed typo in setParameterConstraint function
 *                      Updated to use latest release of OAuth dependent library
 *                      Added message property to LTI_Tool_Provider class to override default message returned on error
 *   2.3.02  18-Apr-13  Tightened up checking of roles - now case sensitive and checks fully qualified URN
 *                      Fixed bug with not updating a resource link before redirecting to a shared resource link
 *   2.3.03   5-Jun-13  Altered order of checks in authenticate
 *                      Fixed bug with LTI_Resource_Link->doOutcomesService when a resource link is shared with a different tool consumer
 *                      Separated LTI_User from LTI_Outcome object
 *                      Fixed bug with returned outcome values of zero
 *   2.3.04  13-Aug-13  Ensure nonce values are no longer than 32 characters
 *   2.3.05  29-Jul-14  Added support for ContentItemSelectionRequest message
 *                      Accepts messages with an lti_version of LTI-2p0
 *                      Added data connector for Oracle
 *   2.3.06   5-Aug-14  Fixed bug with OCI data connector
 *   2.4.00  10-Apr-15  Added class methods as alternatives to callbacks
 *                      Added methods for generating signed auto-submit forms for LTI messages
 *                      Added classes for Content-item objects
 *                      Added support for unofficial ConfigureLaunchRequest and DashboardRequest messages
 *   2.5.00  20-May-15  Added LTI_HTTP_Message class to handle the sending of HTTP requests
 *                      Added workflow for automatically assigning resource link ID on first launch of a content-item message created link
 *                      Enhanced checking of parameter values
 *                      Added mediaTypes and documentTargets properties to LTI_Tool_Provider class for ContentItemSelectionRequest messages
 */

namespace Lti;

/**
 * OAuth library file
 */
use Lti\OAuthDataStore;
use Lti\LTI_Data_Connector;
use Lti\LTI_Data_Connector_PDO;
use Lti\LTI_Data_Connector_MySQL;
use Lti\LTI_Tool_Consumer;
use Lti\LTI_OAuthDataStore;
use Lti\OAuthServer;
use Lti\OAuthSignatureMethod_HMAC_SHA1;
use Lti\OAuthRequest;
use Lti\OAuthConsumer;
use Lti\LTI_Resource_Link;
use Lti\LTI_User;
use Lti\LTI_Resource_Link_Share_Key;

/**
 * Class to represent an LTI Tool Provider
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @version 2.5.00
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class LTI_Tool_Provider {

/**
 * Default connection error message.
 */
  const CONNECTION_ERROR_MESSAGE = 'Sorry, there was an error connecting you to the application.';

/**
 * LTI version 1 for messages.
 *
 * @deprecated Use LTI_VERSION1 instead
 * @see LTI_Tool_Provider::LTI_VERSION1
 */
  const LTI_VERSION = 'LTI-1p0';
/**
 * LTI version 1 for messages.
 */
  const LTI_VERSION1 = 'LTI-1p0';
/**
 * LTI version 2 for messages.
 */
  const LTI_VERSION2 = 'LTI-2p0';
/**
 * Use ID value only.
 */
  const ID_SCOPE_ID_ONLY = 0;
/**
 * Prefix an ID with the consumer key.
 */
  const ID_SCOPE_GLOBAL = 1;
/**
 * Prefix the ID with the consumer key and context ID.
 */
  const ID_SCOPE_CONTEXT = 2;
/**
 * Prefix the ID with the consumer key and resource ID.
 */
  const ID_SCOPE_RESOURCE = 3;
/**
 * Character used to separate each element of an ID.
 */
  const ID_SCOPE_SEPARATOR = ':';

/**
 *  @var boolean True if the last request was successful.
 */
  public $isOK = TRUE;
/**
 *  @var LTI_Tool_Consumer Tool Consumer object.
 */
  public $consumer = NULL;
/**
 *  @var string Return URL provided by tool consumer.
 */
  public $return_url = NULL;
/**
 *  @var LTI_User User object.
 */
  public $user = NULL;
/**
 *  @var LTI_Resource_Link Resource link object.
 */
  public $resource_link = NULL;
/**
 *  @var LTI_Context Resource link object.
 *
 *  @deprecated Use resource_link instead
 *  @see LTI_Tool_Provider::$resource_link
 */
  public $context = NULL;
/**
 *  @var LTI_Data_Connector Data connector object.
 */
  public $data_connector = NULL;
/**
 *  @var string Default email domain.
 */
  public $defaultEmail = '';
/**
 *  @var int Scope to use for user IDs.
 */
  public $id_scope = self::ID_SCOPE_ID_ONLY;
/**
 *  @var boolean Whether shared resource link arrangements are permitted.
 */
  public $allowSharing = FALSE;
/**
 *  @var string Message for last request processed
 */
  public $message = self::CONNECTION_ERROR_MESSAGE;
/**
 *  @var string Error message for last request processed.
 */
  public $reason = NULL;
/**
 *  @var array Details for error message relating to last request processed.
 */
  public $details = array();

/**
 *  @var string URL to redirect user to on successful completion of the request.
 */
  protected $redirectURL = NULL;
/**
 *  @var string URL to redirect user to on successful completion of the request.
 */
  protected $mediaTypes = NULL;
/**
 *  @var string URL to redirect user to on successful completion of the request.
 */
  protected $documentTargets = NULL;
/**
 *  @var string HTML to be displayed on a successful completion of the request.
 */
  protected $output = NULL;
/**
 *  @var string HTML to be displayed on an unsuccessful completion of the request and no return URL is available.
 */
  protected $error_output = NULL;
/**
 *  @var boolean Whether debug messages explaining the cause of errors are to be returned to the tool consumer.
 */
  protected $debugMode = FALSE;

/**
 *  @var array Callback functions for handling requests.
 */
  private $callbackHandler = NULL;
/**
 *  @var array LTI parameter constraints for auto validation checks.
 */
  private $constraints = NULL;
/**
 *  @var array List of supported message types and associated callback type names
 */
  private $messageTypes = array('basic-lti-launch-request' => 'launch',
                                'ConfigureLaunchRequest' => 'configure',
                                'DashboardRequest' => 'dashboard',
                                'ContentItemSelectionRequest' => 'content-item');
/**
 *  @var array List of supported message types and associated class methods
 */
  private $methodNames = array('basic-lti-launch-request' => 'onLaunch',
                               'ConfigureLaunchRequest' => 'onConfigure',
                               'DashboardRequest' => 'onDashboard',
                               'ContentItemSelectionRequest' => 'onContentItem');
/**
 *  @var array Names of LTI parameters to be retained in the settings property.
 */
  private $lti_settings_names = array('ext_resource_link_content', 'ext_resource_link_content_signature',
                                      'lis_result_sourcedid', 'lis_outcome_service_url',
                                      'ext_ims_lis_basic_outcome_url', 'ext_ims_lis_resultvalue_sourcedids',
                                      'ext_ims_lis_memberships_id', 'ext_ims_lis_memberships_url',
                                      'ext_ims_lti_tool_setting', 'ext_ims_lti_tool_setting_id', 'ext_ims_lti_tool_setting_url');

/**
 * @var array Permitted LTI versions for messages.
 */
  private $LTI_VERSIONS = array(self::LTI_VERSION1, self::LTI_VERSION2);

/**
 * Class constructor
 *
 * @param mixed   $data_connector  Object containing a database connection object (optional, default is a blank prefix and MySQL)
 * @param mixed   $callbackHandler String containing name of callback function for launch request, or associative array of callback functions for each request type
 */
  function __construct($data_connector = '', $callbackHandler = NULL) {

// For backward compatibility the parameters may be in the opposite order, but the recommended practice is to just pass a data connector object and
// override the callback class methods instead of using callback method names.

    $reverse = FALSE;
    if (!is_string($data_connector) || (!is_null($callbackHandler) && !is_string($callbackHandler))) {
      if (is_object($callbackHandler)) {
        $reverse = TRUE;
      } else if (is_array($data_connector) && array_diff_key($data_connector ,array_keys(array_keys($data_connector)))) {
        $reverse = TRUE;
      } else if (!is_array($data_connector) && is_array($callbackHandler)) {
        $reverse = TRUE;
      }
    } else if (!is_null($callbackHandler) && empty($callbackHandler)) {
      $reverse = TRUE;
    }
    if ($reverse) {
      $temp = $callbackHandler;
      $callbackHandler = $data_connector;
      $data_connector = $temp;
    }
    $this->constraints = array();
    $this->context = &$this->resource_link;
    $this->callbackHandler = array();
    if (is_array($callbackHandler)) {
      $this->callbackHandler = $callbackHandler;
      if (isset($this->callbackHandler['connect']) && !isset($this->callbackHandler['launch'])) {  // for backward compatibility
        $this->callbackHandler['launch'] = $this->callbackHandler['connect'];
        unset($this->callbackHandler['connect']);
      }
    } else if (!empty($callbackHandler)) {
      $this->callbackHandler['launch'] = $callbackHandler;
    }
    $this->data_connector = LTI_Data_Connector::getDataConnector($data_connector);
    $this->isOK = !is_null($this->data_connector);
#
### Set debug mode
#
    $this->debugMode = isset($_POST['custom_debug']) && (strtolower($_POST['custom_debug']) == 'true');
#
### Set return URL if available
#
    if (isset($_POST['launch_presentation_return_url'])) {
      $this->return_url = $_POST['launch_presentation_return_url'];
    } else if (isset($_POST['content_item_return_url'])) {
      $this->return_url = $_POST['content_item_return_url'];
    }

  }

/**
 * Process an incoming request
 *
 * @deprecated Use handle_request instead
 * @see LTI_Tool_Provider::$handle_request
 *
 * @return mixed Returns TRUE or FALSE, a redirection URL or HTML
 */
  public function execute() {

    $this->handle_request();

  }

/**
 * Process an incoming request
 *
 * @return mixed Returns TRUE or FALSE, a redirection URL or HTML
 */
  public function handle_request() {

#
### Perform action
#
    if ($this->isOK) {
      if ($this->authenticate()) {
        $this->doCallback();
      }
    }
    $this->result();

  }

/**
 * Add a parameter constraint to be checked on launch
 *
 * @param string $name          Name of parameter to be checked
 * @param boolean $required     True if parameter is required (optional, default is TRUE)
 * @param int $max_length       Maximum permitted length of parameter value (optional, default is NULL)
 * @param array $message_types  Array of message types to which the constraint applies (default is all)
 */
  public function setParameterConstraint($name, $required = TRUE, $max_length = NULL, $message_types = NULL) {

    $name = trim($name);
    if (strlen($name) > 0) {
      $this->constraints[$name] = array('required' => $required, 'max_length' => $max_length, 'messages' => $message_types);
    }

  }

/**
 * Get an array of defined tool consumers
 *
 * @return array Array of LTI_Tool_Consumer objects
 */
  public function getConsumers() {

#
### Initialise data connector
#
    $this->data_connector = LTI_Data_Connector::getDataConnector($this->data_connector);

    return $this->data_connector->Tool_Consumer_list();

  }

/**
 * Get an array of fully qualified user roles
 *
 * @param string Comma-separated list of roles
 *
 * @return array Array of roles
 */
  public static function parseRoles($rolesString) {

    $rolesArray = explode(',', $rolesString);
    $roles = array();
    foreach ($rolesArray as $role) {
      $role = trim($role);
      if (!empty($role)) {
        if (substr($role, 0, 4) != 'urn:') {
          $role = 'urn:lti:role:ims/lis/' . $role;
        }
        $roles[] = $role;
      }
    }

    return $roles;

  }

/**
 * Generate a web page containing an auto-submitted form of parameters.
 *
 * @param string $url     URL to which the form should be submitted
 * @param array  $params  Array of form parameters
 * @param string $target  Name of target (optional)
 */
  public static function sendForm($url, $params, $target = '') {

    $page = <<< EOD
<html>
<head>
<title>IMS LTI message</title>
<script type="text/javascript">
//<![CDATA[
function doOnLoad() {
  document.forms[0].submit();
}

window.onload=doOnLoad;
//]]>
</script>
</head>
<body>
<form action="{$url}" method="post" target="" encType="application/x-www-form-urlencoded">

EOD;

    foreach($params as $key => $value ) {
      $key = htmlentities($key, ENT_COMPAT | ENT_HTML401, 'UTF-8');
      $value = htmlentities($value, ENT_COMPAT | ENT_HTML401, 'UTF-8');
      $page .= <<< EOD
  <input type="hidden" name="{$key}" value="{$value}" />

EOD;

    }

    $page .= <<< EOD
</form>
</body>
</html>
EOD;

    return $page;

  }

###
###  PROTECTED METHODS
###

/**
 * Process a valid launch request
 *
 * @return boolean True if no error
 */
  protected function onLaunch() {

    $this->doCallbackMethod();

  }

/**
 * Process a valid configure request
 *
 * @return boolean True if no error
 */
  protected function onConfigure() {

    $this->doCallbackMethod();

  }

/**
 * Process a valid dashboard request
 *
 * @return boolean True if no error
 */
  protected function onDashboard() {

    $this->doCallbackMethod();

  }

/**
 * Process a valid content-item request
 *
 * @return boolean True if no error
 */
  protected function onContentItem() {

    $this->doCallbackMethod();

  }

/**
 * Process a response to an invalid request
 *
 * @return boolean True if no further error processing required
 */
  protected function onError() {

    $this->doCallbackMethod('error');

  }

###
###  PRIVATE METHODS
###

/**
 * Call any callback function for the requested action.
 *
 * This function may set the redirectURL and output properties.
 *
 * @return boolean True if no error reported
 */
  private function doCallback() {

    $method = $this->methodNames[$_POST['lti_message_type']];
    $this->$method();

  }

/**
 * Call any callback function for the requested action.
 *
 * This function may set the redirectURL and output properties.
 *
 * @param string  $type             Callback type
 *
 * @return boolean True if no error reported
 */
  private function doCallbackMethod($type = NULL) {

    $callback = $type;
    if (is_null($callback)) {
      $callback = $this->messageTypes[$_POST['lti_message_type']];
    }
    if (isset($this->callbackHandler[$callback])) {
      $result = call_user_func($this->callbackHandler[$callback], $this);

#
### Callback function may return HTML, a redirect URL, or a boolean value
#
      if (is_string($result)) {
        if ((substr($result, 0, 7) == 'http://') || (substr($result, 0, 8) == 'https://')) {
          $this->redirectURL = $result;
        } else {
          if (is_null($this->output)) {
            $this->output = '';
          }
          $this->output .= $result;
        }
      } else if (is_bool($result)) {
        $this->isOK = $result;
      }
    } else if (is_null($type) && $this->isOK) {
      $this->isOK = FALSE;
      $this->reason = 'Message type not supported.';
    }

  }

/**
 * Perform the result of an action.
 *
 * This function may redirect the user to another URL rather than returning a value.
 *
 * @return string Output to be displayed (redirection, or display HTML or message)
 */
  private function result() {

    $ok = FALSE;
    if (!$this->isOK) {
      $ok = $this->onError();
    }
    if (!$ok) {
      if (!$this->isOK) {
#
### If not valid, return an error message to the tool consumer if a return URL is provided
#
        if (!empty($this->return_url)) {
          $error_url = $this->return_url;
          if (strpos($error_url, '?') === FALSE) {
            $error_url .= '?';
          } else {
            $error_url .= '&';
          }
          if ($this->debugMode && !is_null($this->reason)) {
            $error_url .= 'lti_errormsg=' . urlencode("Debug error: $this->reason");
          } else {
            $error_url .= 'lti_errormsg=' . urlencode($this->message);
            if (!is_null($this->reason)) {
              $error_url .= '&lti_errorlog=' . urlencode("Debug error: $this->reason");
            }
          }
          if (!is_null($this->consumer) && isset($_POST['lti_message_type']) && ($_POST['lti_message_type'] === 'ContentItemSelectionRequest')) {
            $form_params = array();
            if (isset($_POST['data'])) {
              $form_params['data'] = $_POST['data'];
            }
            $version = (isset($_POST['lti_version'])) ? $_POST['lti_version'] : LTI_Tool_Provider::LTI_VERSION1;
            $form_params = $this->consumer->signParameters($error_url, 'ContentItemSelection', $version, $form_params);
            $page = LTI_Tool_Provider::sendForm($error_url, $form_params);
            echo $page;
          } else {
            header("Location: {$error_url}");
          }
          exit;
        } else {
          if (!is_null($this->error_output)) {
            echo $this->error_output;
          } else if ($this->debugMode && !empty($this->reason)) {
            echo "Debug error: {$this->reason}";
          } else {
            echo "Error: {$this->message}";
          }
        }
      } else if (!is_null($this->redirectURL)) {
        // header("Location: {$this->redirectURL}");
        exit;
      } else if (!is_null($this->output)) {
        echo $this->output;
      }
    }

  }

/**
 * Check the authenticity of the LTI launch request.
 *
 * The consumer, resource link and user objects will be initialised if the request is valid.
 *
 * @return boolean True if the request has been successfully validated.
 */
  private function authenticate() {

#
### Get the consumer
#
    $doSaveConsumer = FALSE;
// Check all required launch parameters
    $this->isOK = isset($_POST['lti_message_type']) && array_key_exists($_POST['lti_message_type'], $this->messageTypes);
    if (!$this->isOK) {
      $this->reason = 'Invalid or missing lti_message_type parameter.';
    }
    if ($this->isOK) {
      $this->isOK = isset($_POST['lti_version']) && in_array($_POST['lti_version'], $this->LTI_VERSIONS);
      if (!$this->isOK) {
        $this->reason = 'Invalid or missing lti_version parameter.';
      }
    }
    if ($this->isOK) {
      if (($_POST['lti_message_type'] == 'basic-lti-launch-request') || ($_POST['lti_message_type'] == 'DashboardRequest')) {
        $this->isOK = isset($_POST['resource_link_id']) && (strlen(trim($_POST['resource_link_id'])) > 0);
        if (!$this->isOK) {
          $this->reason = 'Missing resource link ID.';
        }
      } else if ($_POST['lti_message_type'] == 'ContentItemSelectionRequest') {
        if (isset($_POST['accept_media_types']) && (strlen(trim($_POST['accept_media_types'])) > 0)) {
          $mediaTypes = array_filter(explode(',', str_replace(' ', '', $_POST['accept_media_types'])), 'strlen');
          $mediaTypes = array_unique($mediaTypes);
          $this->isOK = count($mediaTypes) > 0;
          if (!$this->isOK) {
            $this->reason = 'No accept_media_types found.';
          } else {
            $this->mediaTypes = $mediaTypes;
          }
        } else {
          $this->isOK = FALSE;
        }
        if ($this->isOK && isset($_POST['accept_presentation_document_targets']) && (strlen(trim($_POST['accept_presentation_document_targets'])) > 0)) {
          $documentTargets = array_filter(explode(',', str_replace(' ', '', $_POST['accept_presentation_document_targets'])), 'strlen');
          $documentTargets = array_unique($documentTargets);
          $this->isOK = count($documentTargets) > 0;
          if (!$this->isOK) {
            $this->reason = 'Missing or empty accept_presentation_document_targets parameter.';
          } else {
            foreach ($documentTargets as $documentTarget) {
              $this->isOK = $this->checkValue($documentTarget, array('embed', 'frame', 'iframe', 'window', 'popup', 'overlay', 'none'),
                 'Invalid value in accept_presentation_document_targets parameter: %s.');
              if (!$this->isOK) {
                break;
              }
            }
            if ($this->isOK) {
              $this->documentTargets = $documentTargets;
            }
          }
        } else {
          $this->isOK = FALSE;
        }
        if ($this->isOK) {
          $this->isOK = isset($_POST['content_item_return_url']) && (strlen(trim($_POST['content_item_return_url'])) > 0);
          if (!$this->isOK) {
            $this->reason = 'Missing content_item_return_url parameter.';
          }
        }
      }
    }
// Check consumer key
    if ($this->isOK) {
      $this->isOK = isset($_POST['oauth_consumer_key']);
      if (!$this->isOK) {
        $this->reason = 'Missing consumer key.';
      }
    }
    if ($this->isOK) {
      $this->consumer = new LTI_Tool_Consumer($_POST['oauth_consumer_key'], $this->data_connector);
      $this->isOK = !is_null($this->consumer->created);
      if (!$this->isOK) {
        $this->reason = 'Invalid consumer key.';
      }
    }
    $now = time();
    if ($this->isOK) {
      $today = date('Y-m-d', $now);
      if (is_null($this->consumer->last_access)) {
        $doSaveConsumer = TRUE;
      } else {
        $last = date('Y-m-d', $this->consumer->last_access);
        $doSaveConsumer = $doSaveConsumer || ($last != $today);
      }
      $this->consumer->last_access = $now;
      try {
        $store = new LTI_OAuthDataStore($this);
        $server = new OAuthServer($store);
        $method = new OAuthSignatureMethod_HMAC_SHA1();
        $server->add_signature_method($method);
        $request = OAuthRequest::from_request();
        $res = $server->verify_request($request);
      } catch (Exception $e) {
        $this->isOK = FALSE;
        if (empty($this->reason)) {
          if ($this->debugMode) {
            $consumer = new OAuthConsumer($this->consumer->getKey(), $this->consumer->secret);
            $signature = $request->build_signature($method, $consumer, FALSE);
            $this->reason = $e->getMessage();
            if (empty($this->reason)) {
              $this->reason = 'OAuth exception';
            }
            $this->details[] = 'Timestamp: ' . time();
            $this->details[] = "Signature: {$signature}";
            $this->details[] = "Base string: {$request->base_string}]";
          } else {
            $this->reason = 'OAuth signature check failed - perhaps an incorrect secret or timestamp.';
          }
        }
      }
    }
    if ($this->isOK && $this->consumer->protected) {
      if (!is_null($this->consumer->consumer_guid)) {
        $this->isOK = isset($_POST['tool_consumer_instance_guid']) && !empty($_POST['tool_consumer_instance_guid']) &&
           ($this->consumer->consumer_guid == $_POST['tool_consumer_instance_guid']);
        if (!$this->isOK) {
          $this->reason = 'Request is from an invalid tool consumer.';
        }
      } else {
        $this->isOK = isset($_POST['tool_consumer_instance_guid']);
        if (!$this->isOK) {
          $this->reason = 'A tool consumer GUID must be included in the launch request.';
        }
      }
    }
    if ($this->isOK) {
      $this->isOK = $this->consumer->enabled;
      if (!$this->isOK) {
        $this->reason = 'Tool consumer has not been enabled by the tool provider.';
      }
    }
    if ($this->isOK) {
      $this->isOK = is_null($this->consumer->enable_from) || ($this->consumer->enable_from <= $now);
      if ($this->isOK) {
        $this->isOK = is_null($this->consumer->enable_until) || ($this->consumer->enable_until > $now);
        if (!$this->isOK) {
          $this->reason = 'Tool consumer access has expired.';
        }
      } else {
        $this->reason = 'Tool consumer access is not yet available.';
      }
    }

#
### Validate other message parameter values
#
    if ($this->isOK) {
      if ($_POST['lti_message_type'] != 'ContentItemSelectionRequest') {
        if (isset($_POST['launch_presentation_document_target'])) {
          $this->isOK = $this->checkValue($_POST['launch_presentation_document_target'], array('embed', 'frame', 'iframe', 'window', 'popup', 'overlay'),
             'Invalid value for launch_presentation_document_target parameter: %s.');
        }
      } else {
        if (isset($_POST['accept_unsigned'])) {
          $this->isOK = $this->checkValue($_POST['accept_unsigned'], array('true', 'false'), 'Invalid value for accept_unsigned parameter: %s.');
        }
        if ($this->isOK && isset($_POST['accept_multiple'])) {
          $this->isOK = $this->checkValue($_POST['accept_multiple'], array('true', 'false'), 'Invalid value for accept_multiple parameter: %s.');
        }
        if ($this->isOK && isset($_POST['accept_copy_advice'])) {
          $this->isOK = $this->checkValue($_POST['accept_copy_advice'], array('true', 'false'), 'Invalid value for accept_copy_advice parameter: %s.');
        }
        if ($this->isOK && isset($_POST['auto_create'])) {
          $this->isOK = $this->checkValue($_POST['auto_create'], array('true', 'false'), 'Invalid value for auto_create parameter: %s.');
        }
        if ($this->isOK && isset($_POST['can_confirm'])) {
          $this->isOK = $this->checkValue($_POST['can_confirm'], array('true', 'false'), 'Invalid value for can_confirm parameter: %s.');
        }
      }
    }

#
### Validate message parameter constraints
#
    if ($this->isOK) {
      $invalid_parameters = array();
      foreach ($this->constraints as $name => $constraint) {
        if (empty($constraint['messages']) || in_array($_POST['lti_message_type'], $constraint['messages'])) {
          $ok = TRUE;
          if ($constraint['required']) {
            if (!isset($_POST[$name]) || (strlen(trim($_POST[$name])) <= 0)) {
              $invalid_parameters[] = "{$name} (missing)";
              $ok = FALSE;
            }
          }
          if ($ok && !is_null($constraint['max_length']) && isset($_POST[$name])) {
            if (strlen(trim($_POST[$name])) > $constraint['max_length']) {
              $invalid_parameters[] = "{$name} (too long)";
            }
          }
        }
      }
      if (count($invalid_parameters) > 0) {
        $this->isOK = FALSE;
        if (empty($this->reason)) {
          $this->reason = 'Invalid parameter(s): ' . implode(', ', $invalid_parameters) . '.';
        }
      }
    }

    if ($this->isOK) {
#
### Set the request context/resource link
#
      if (isset($_POST['resource_link_id'])) {
        $content_item_id = '';
        if (isset($_POST['custom_content_item_id'])) {
          $content_item_id = $_POST['custom_content_item_id'];
        }
        $this->resource_link = new LTI_Resource_Link($this->consumer, trim($_POST['resource_link_id']), $content_item_id);
        if (isset($_POST['context_id'])) {
          $this->resource_link->lti_context_id = trim($_POST['context_id']);
        }
        $this->resource_link->lti_resource_id = trim($_POST['resource_link_id']);
        $title = '';
        if (isset($_POST['context_title'])) {
          $title = trim($_POST['context_title']);
        }
        if (isset($_POST['resource_link_title']) && (strlen(trim($_POST['resource_link_title'])) > 0)) {
          if (!empty($title)) {
            $title .= ': ';
          }
          $title .= trim($_POST['resource_link_title']);
        }
        if (empty($title)) {
          $title = "Course {$this->resource_link->getId()}";
        }
        $this->resource_link->title = $title;
// Save LTI parameters
        foreach ($this->lti_settings_names as $name) {
          if (isset($_POST[$name])) {
            $this->resource_link->setSetting($name, $_POST[$name]);
          } else {
            $this->resource_link->setSetting($name, NULL);
          }
        }
// Delete any existing custom parameters
        foreach ($this->resource_link->getSettings() as $name => $value) {
          if (strpos($name, 'custom_') === 0) {
            $this->resource_link->setSetting($name);
          }
        }
// Save custom parameters
        foreach ($_POST as $name => $value) {
          if (strpos($name, 'custom_') === 0) {
            $this->resource_link->setSetting($name, $value);
          }
        }
      }
#
### Set the user instance
#
      $user_id = '';
      if (isset($_POST['user_id'])) {
        $user_id = trim($_POST['user_id']);
      }
      $this->user = new LTI_User($this->resource_link, $user_id);
#
### Set the user name
#
      $firstname = (isset($_POST['lis_person_name_given'])) ? $_POST['lis_person_name_given'] : '';
      $lastname = (isset($_POST['lis_person_name_family'])) ? $_POST['lis_person_name_family'] : '';
      $fullname = (isset($_POST['lis_person_name_full'])) ? $_POST['lis_person_name_full'] : '';
      $this->user->setNames($firstname, $lastname, $fullname);
#
### Set the user email
#
      $email = (isset($_POST['lis_person_contact_email_primary'])) ? $_POST['lis_person_contact_email_primary'] : '';
      $this->user->setEmail($email, $this->defaultEmail);
#
### Set the user roles
#
      if (isset($_POST['roles'])) {
        $this->user->roles = LTI_Tool_Provider::parseRoles($_POST['roles']);
      }
#
### Save the user instance
#
      if (isset($_POST['lis_result_sourcedid'])) {
        if ($this->user->lti_result_sourcedid != $_POST['lis_result_sourcedid']) {
          $this->user->lti_result_sourcedid = $_POST['lis_result_sourcedid'];
          $this->user->save();
        }
      } else if (!empty($this->user->lti_result_sourcedid)) {
        $this->user->delete();
      }
#
### Initialise the consumer and check for changes
#
      $this->consumer->defaultEmail = $this->defaultEmail;
      if ($this->consumer->lti_version != $_POST['lti_version']) {
        $this->consumer->lti_version = $_POST['lti_version'];
        $doSaveConsumer = TRUE;
      }
      if (isset($_POST['tool_consumer_instance_name'])) {
        if ($this->consumer->consumer_name != $_POST['tool_consumer_instance_name']) {
          $this->consumer->consumer_name = $_POST['tool_consumer_instance_name'];
          $doSaveConsumer = TRUE;
        }
      }
      if (isset($_POST['tool_consumer_info_product_family_code'])) {
        $version = $_POST['tool_consumer_info_product_family_code'];
        if (isset($_POST['tool_consumer_info_version'])) {
          $version .= "-{$_POST['tool_consumer_info_version']}";
        }
// do not delete any existing consumer version if none is passed
        if ($this->consumer->consumer_version != $version) {
          $this->consumer->consumer_version = $version;
          $doSaveConsumer = TRUE;
        }
      } else if (isset($_POST['ext_lms']) && ($this->consumer->consumer_name != $_POST['ext_lms'])) {
        $this->consumer->consumer_version = $_POST['ext_lms'];
        $doSaveConsumer = TRUE;
      }
      if (isset($_POST['tool_consumer_instance_guid'])) {
        if (is_null($this->consumer->consumer_guid)) {
          $this->consumer->consumer_guid = $_POST['tool_consumer_instance_guid'];
          $doSaveConsumer = TRUE;
        } else if (!$this->consumer->protected) {
          $doSaveConsumer = ($this->consumer->consumer_guid != $_POST['tool_consumer_instance_guid']);
          if ($doSaveConsumer) {
            $this->consumer->consumer_guid = $_POST['tool_consumer_instance_guid'];
          }
        }
      }
      if (isset($_POST['launch_presentation_css_url'])) {
        if ($this->consumer->css_path != $_POST['launch_presentation_css_url']) {
          $this->consumer->css_path = $_POST['launch_presentation_css_url'];
          $doSaveConsumer = TRUE;
        }
      } else if (isset($_POST['ext_launch_presentation_css_url']) &&
         ($this->consumer->css_path != $_POST['ext_launch_presentation_css_url'])) {
        $this->consumer->css_path = $_POST['ext_launch_presentation_css_url'];
        $doSaveConsumer = TRUE;
      } else if (!empty($this->consumer->css_path)) {
        $this->consumer->css_path = NULL;
        $doSaveConsumer = TRUE;
      }
    }
#
### Persist changes to consumer
#
    if ($doSaveConsumer) {
      $this->consumer->save();
    }

    if ($this->isOK && isset($this->resource_link)) {
#
### Check if a share arrangement is in place for this resource link
#
      $this->isOK = $this->checkForShare();
#
### Persist changes to resource link
#
      $this->resource_link->save();
    }

    return $this->isOK;

  }

/**
 * Check if a share arrangement is in place.
 *
 * @return boolean True if no error is reported
 */
  private function checkForShare() {

    $ok = TRUE;
    $doSaveResourceLink = TRUE;

    $key = $this->resource_link->primary_consumer_key;
    $id = $this->resource_link->primary_resource_link_id;

    $shareRequest = isset($_POST['custom_share_key']) && !empty($_POST['custom_share_key']);
    if ($shareRequest) {
      if (!$this->allowSharing) {
        $ok = FALSE;
        $this->reason = 'Your sharing request has been refused because sharing is not being permitted.';
      } else {
// Check if this is a new share key
        $share_key = new LTI_Resource_Link_Share_Key($this->resource_link, $_POST['custom_share_key']);
        if (!is_null($share_key->primary_consumer_key) && !is_null($share_key->primary_resource_link_id)) {
// Update resource link with sharing primary resource link details
          $key = $share_key->primary_consumer_key;
          $id = $share_key->primary_resource_link_id;
          $ok = ($key != $this->consumer->getKey()) || ($id != $this->resource_link->getId());
          if ($ok) {
            $this->resource_link->primary_consumer_key = $key;
            $this->resource_link->primary_resource_link_id = $id;
            $this->resource_link->share_approved = $share_key->auto_approve;
            $ok = $this->resource_link->save();
            if ($ok) {
              $doSaveResourceLink = FALSE;
              $this->user->getResourceLink()->primary_consumer_key = $key;
              $this->user->getResourceLink()->primary_resource_link_id = $id;
              $this->user->getResourceLink()->share_approved = $share_key->auto_approve;
              $this->user->getResourceLink()->updated = time();
// Remove share key
              $share_key->delete();
            } else {
              $this->reason = 'An error occurred initialising your share arrangement.';
            }
          } else {
            $this->reason = 'It is not possible to share your resource link with yourself.';
          }
        }
        if ($ok) {
          $ok = !is_null($key);
          if (!$ok) {
            $this->reason = 'You have requested to share a resource link but none is available.';
          } else {
            $ok = (!is_null($this->user->getResourceLink()->share_approved) && $this->user->getResourceLink()->share_approved);
            if (!$ok) {
              $this->reason = 'Your share request is waiting to be approved.';
            }
          }
        }
      }
    } else {
// Check no share is in place
      $ok = is_null($key);
      if (!$ok) {
        $this->reason = 'You have not requested to share a resource link but an arrangement is currently in place.';
      }
    }

// Look up primary resource link
    if ($ok && !is_null($key)) {
      $consumer = new LTI_Tool_Consumer($key, $this->data_connector);
      $ok = !is_null($consumer->created);
      if ($ok) {
        $resource_link = new LTI_Resource_Link($consumer, $id);
        $ok = !is_null($resource_link->created);
      }
      if ($ok) {
        if ($doSaveResourceLink) {
          $this->resource_link->save();
        }
        $this->resource_link = $resource_link;
      } else {
        $this->reason = 'Unable to load resource link being shared.';
      }
    }

    return $ok;

  }

/**
 * Validate a parameter value from an array of permitted values.
 *
 * @return boolean True if value is valid
 */
  private function checkValue($value, $values, $reason) {

    $ok = in_array($value, $values);
    if (!$ok && !empty($reason)) {
      $this->reason = sprintf($reason, $value);
    }

    return $ok;

  }

}
