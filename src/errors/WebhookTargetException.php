<?php

namespace justinholtweb\headdy\errors;

/**
 * A webhook URL that resolves somewhere a delivery must not go. Its message is safe to show in
 * the control panel.
 */
class WebhookTargetException extends \RuntimeException
{
}
