<?php
date_default_timezone_set('Asia/Manila');

const APP_NAME = 'TechRepair';

// DEV_MODE = true  -> the password reset link is shown on screen (no SMS service is connected yet).
// DEV_MODE = false -> the link is texted to the customer by send_sms() in functions.php,
//                     which needs an SMS provider. Set this to false before going live.
const DEV_MODE = true;

// Full site address used in reset links, e.g. 'https://yourdomain.com/TechRepair'.
// Leave empty on localhost; it is detected automatically.
const APP_URL = '';

const MAX_LOGIN_ATTEMPTS = 5;   // wrong passwords before a temporary lock
const LOCK_MINUTES       = 15;  // how long the account stays locked
const RESET_MINUTES      = 60;  // how long a reset link works

// Password rules (checked on the server, and shown live on the sign-up and reset pages)
const PASSWORD_MIN_LENGTH  = 8;   // total characters
const PASSWORD_MIN_LETTERS = 1;   // at least this many letters (a to z)
const PASSWORD_MIN_NUMBERS = 1;   // at least this many numbers (0 to 9)