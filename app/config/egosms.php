<?php
declare(strict_types=1);

/**
 * EgoSMS config
 * Put secrets in environment variables (recommended).
 * NEVER hardcode API passwords in source code.
 */
return [
  'base_url' => getenv('EGOSMS_BASE_URL') ?: '',     // e.g. https://comms.egosms.co/api/... (you paste actual)
  'username' => getenv('EGOSMS_USERNAME') ?: '',     // API Username
  'password' => getenv('EGOSMS_PASSWORD') ?: '',     // API Password / Key
  'sender'   => getenv('EGOSMS_SENDER') ?: 'UBTEB',  // Sender ID if your account supports it
  'timeout'  => 20,
];
