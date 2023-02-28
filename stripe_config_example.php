<?php
require 'vendor/autoload.php';

// STRIPE KEY CONFIG VARS START
$secretKey = "";
$clientId = "";
// STRIPE KEY CONFIG VARS END

// CONFIGURE STRIPE CLIENT START
\Stripe\Stripe::setApiVersion("2020-08-27");
$stripe = new \Stripe\StripeClient($secretKey);
\Stripe\Stripe::setApiKey($secretKey);
// CONFIGURE STRIPE CLIENT START

?>