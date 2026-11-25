<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * A block drawn for each person (see Dynamic): render() leaves a Dynamic::marker(), and
 * render_for() draws it when the person's email is filled in.
 */
interface DynamicBlock
{
    /**
     * @param array $data what render() put in the marker
     * @param array $args trigger, run, mode ("send", "test", "sample"), email_campaign_id
     * @param Style $style the email's style at the block's width
     *
     * @return string <tr> rows
     */
    public function render_for($data, $args, Style $style);
}
