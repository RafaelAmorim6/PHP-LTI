<?php

namespace Lti\Instances;

use Lti\LTI_Tool_Provider;

class Video_Interativo_LTI_Tool_Provider extends LTI_Tool_Provider {

function __construct($data_connector = '', $callbackHandler = NULL) {

  parent::__construct($data_connector, $callbackHandler);
  // $this->baseURL = getAppUrl();
}

function onLaunch() {

  global $db;

  // Check the user has an appropriate role
  if ($this->user->isLearner() || $this->user->isStaff()) {
    // Initialise the user session
    session(['consumer_key' =>  $this->consumer->getKey()]);
    session(['resource_id' =>  $this->resource_link->getId()]);
    session(['user_consumer_key' =>  $this->user->getResourceLink()->getConsumer()->getKey()]);
    session(['user_id' =>  $this->user->getId()]);
    session(['isStudent' =>  $this->user->isLearner()]);
    session(['isContentItem' =>  FALSE]);

  } else {

    $this->reason = 'Invalid role.';
    $this->isOK = FALSE;

  }

}
}
