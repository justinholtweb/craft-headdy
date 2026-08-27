<?php

/**
 * Headdy's English strings.
 *
 * Listed so a translator has one file to work from, and so a typo'd `Craft::t()` key shows up as a
 * missing entry here rather than silently rendering itself.
 */

return [
    // Errors the API returns
    'The storefront API is turned off.' => 'The storefront API is turned off.',
    'Craft Commerce is not available.' => 'Craft Commerce is not available.',
    'An API key is required. Send it in the X-Headdy-Key header.' => 'An API key is required. Send it in the X-Headdy-Key header.',
    'That API key is not valid.' => 'That API key is not valid.',
    'That API key secret is not valid.' => 'That API key secret is not valid.',
    'That API key may not be used from this origin.' => 'That API key may not be used from this origin.',
    'This API key does not have the “{scope}” scope.' => 'This API key does not have the “{scope}” scope.',
    'Too many requests. Try again shortly.' => 'Too many requests. Try again shortly.',
    'This endpoint requires Headdy Pro.' => 'This endpoint requires Headdy Pro.',
    'The request body is not valid JSON: {error}' => 'The request body is not valid JSON: {error}',
    'Something went wrong.' => 'Something went wrong.',
    'Not found.' => 'Not found.',

    // Carts
    'Unknown store.' => 'Unknown store.',
    'Could not create a cart.' => 'Could not create a cart.',
    'Could not update the cart.' => 'Could not update the cart.',
    'This order has already been completed and can no longer be changed.' => 'This order has already been completed and can no longer be changed.',
    'This cart is busy with another request. Try again.' => 'This cart is busy with another request. Try again.',
    'No cart. Send a valid cart token, or create one first.' => 'No cart. Send a valid cart token, or create one first.',
    'That cart token has expired. Start a new cart.' => 'That cart token has expired. Start a new cart.',
    'That cart token is not valid or has expired.' => 'That cart token is not valid or has expired.',
    'No line item “{ref}” in this cart.' => 'No line item “{ref}” in this cart.',
    'No purchasable with the ID “{id}”.' => 'No purchasable with the ID “{id}”.',
    '“{description}” is not available.' => '“{description}” is not available.',
    'That email address is not valid.' => 'That email address is not valid.',
    'That email address could not be used.' => 'That email address could not be used.',
    'This cart belongs to a registered customer and its email address cannot be changed.' => 'This cart belongs to a registered customer and its email address cannot be changed.',
    '“{handle}” is not an available shipping method for this cart.' => '“{handle}” is not an available shipping method for this cart.',

    // Checkout and payment
    'This cart is not ready to be completed.' => 'This cart is not ready to be completed.',
    'This cart is not ready to be paid for.' => 'This cart is not ready to be paid for.',
    'This order has an outstanding balance and must be paid for.' => 'This order has an outstanding balance and must be paid for.',
    'Could not complete the order.' => 'Could not complete the order.',
    'That payment gateway is not available.' => 'That payment gateway is not available.',
    'There is no payment gateway available for this order.' => 'There is no payment gateway available for this order.',
    'That payment source cannot be used with this order.' => 'That payment source cannot be used with this order.',
    'The payment details are not valid.' => 'The payment details are not valid.',
    'The order changed before payment. Review it and submit again.' => 'The order changed before payment. Review it and submit again.',
    'Partial payment is not allowed on this store.' => 'Partial payment is not allowed on this store.',
    'The payment could not be completed.' => 'The payment could not be completed.',
    'No payment transaction matches that hash.' => 'No payment transaction matches that hash.',
    'The order behind that payment is gone.' => 'The order behind that payment is gone.',
    'That return URL is not allowed.' => 'That return URL is not allowed.',
    'The return URL “{origin}” is not an allowed redirect origin. Add it in Headdy’s settings.' => 'The return URL “{origin}” is not an allowed redirect origin. Add it in Headdy’s settings.',

    // Catalog
    'The catalog endpoints are turned off.' => 'The catalog endpoints are turned off.',
    'No product found.' => 'No product found.',
    'No variant found.' => 'No variant found.',
    'No order found.' => 'No order found.',
    'No address found.' => 'No address found.',

    // Customers
    'Customer login is turned off.' => 'Customer login is turned off.',
    'Customer registration is turned off.' => 'Customer registration is turned off.',
    'Invalid credentials.' => 'Invalid credentials.',
    'Control panel accounts cannot sign in through the storefront API.' => 'Control panel accounts cannot sign in through the storefront API.',
    'A valid email address is required.' => 'A valid email address is required.',
    'An account already exists for that email address.' => 'An account already exists for that email address.',
    'Could not create that account.' => 'Could not create that account.',
    'Could not save that address.' => 'Could not save that address.',
    'A customer token is required for this endpoint.' => 'A customer token is required for this endpoint.',
    'That refresh token is not valid.' => 'That refresh token is not valid.',

    // Control panel
    'Headdy' => 'Headdy',
    'Overview' => 'Overview',
    'API keys' => 'API keys',
    'Webhooks' => 'Webhooks',
    'Request log' => 'Request log',
    'Settings' => 'Settings',
    'Manage API keys' => 'Manage API keys',
    'Manage webhooks' => 'Manage webhooks',
    'View the request log' => 'View the request log',
    'API key saved.' => 'API key saved.',
    'Could not save the API key.' => 'Could not save the API key.',
    'Webhook saved.' => 'Webhook saved.',
    'Could not save the webhook.' => 'Could not save the webhook.',
    'Webhooks require Headdy Pro.' => 'Webhooks require Headdy Pro.',
    'The request log requires Headdy Pro.' => 'The request log requires Headdy Pro.',
    'Delivered ({status}).' => 'Delivered ({status}).',
    'Failed: {error}' => 'Failed: {error}',
    'Sending the “{topic}” webhook' => 'Sending the “{topic}” webhook',
];
