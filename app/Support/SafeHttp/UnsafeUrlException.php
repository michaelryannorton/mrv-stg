<?php

namespace App\Support\SafeHttp;

/** The address is not one the server may fetch on a visitor's behalf. The message never contains the address. */
class UnsafeUrlException extends \RuntimeException {}
