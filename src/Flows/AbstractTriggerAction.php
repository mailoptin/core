<?php

namespace MailOptin\Core\Flows;

abstract class AbstractTriggerAction
{
    const SELECT_FIELD = 'select';

    const SELECT2_FIELD = 'select2';

    const FIELD_MAP = 'field_map';

    const TEXT_FIELD = 'text';

    const WOOCOMMERCE_CATEGORY = 'woocommerce';

    const MAILCHIMP_CATEGORY = 'mailchimp';
}