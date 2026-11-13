<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * Ready-made content a new broadcast or automation email can start from ("Start from" in the
 * template picker): ordinary blocks, in whichever template was chosen, to edit or delete. Text in
 * [brackets] is a prompt to replace; the editor's checks flag any left before sending.
 *
 * Register one from a plugin or theme on the mailoptin_email_starters action:
 *
 *     add_action('mailoptin_email_starters', function (Starters $starters) {
 *         $starters->register([
 *             'id'          => 'acme-launch',
 *             'name'        => 'Acme launch',
 *             'description' => 'A product, its picture and a buy button.',
 *             'contexts'    => ['broadcast'],
 *             'content'     => function ($email_campaign_id) { return [Starters::text('<p>Hello</p>')]; },
 *         ]);
 *     });
 */
class Starters
{
    /** @var array id => starter */
    protected $starters = [];

    protected $loaded = false;

    /**
     * @param array $args id, name, description, blocks (a short list for the picker), contexts (email
     *                    editor context ids; default broadcast), needs (what an automation's trigger must
     *                    provide: "cart", "order"), content (callable: email id => blocks; text to
     *                    replace written with Starters::prompt())
     *
     * @return true|\WP_Error
     */
    public function register($args)
    {
        $args = wp_parse_args($args, ['id' => '', 'name' => '', 'description' => '', 'blocks' => '', 'contexts' => ['broadcast'], 'needs' => '', 'content' => null]);
        $id   = sanitize_key((string)$args['id']);

        if ('' === $id || ! is_callable($args['content'])) {
            return new \WP_Error('mo_email_starter', __('A starter layout needs an id and a content callback.', 'mailoptin'));
        }

        $this->starters[$id] = [
            'id'          => $id,
            'name'        => '' !== (string)$args['name'] ? (string)$args['name'] : $id,
            'description' => (string)$args['description'],
            'blocks'      => (string)$args['blocks'],
            'contexts'    => array_values(array_map('strval', (array)$args['contexts'])),
            // What an automation's trigger must provide ("cart", "order"): offered only then.
            'needs'       => (string)$args['needs'],
            'content'     => $args['content'],
        ];

        return true;
    }

    public function get($id)
    {
        $this->load();

        return $this->starters[(string)$id] ?? null;
    }

    /**
     * Starters offered for a kind of email (an email editor context id), in order.
     *
     * @param array $args the context's (an automation's email: automation_id, to keep to starters
     *                    its trigger can fill)
     */
    public function for_context($context_id, $args = [])
    {
        $this->load();

        $provides = null;

        return array_filter($this->starters, function ($starter) use ($context_id, $args, &$provides) {
            if ( ! in_array((string)$context_id, $starter['contexts'], true)) return false;
            if ('' === $starter['needs']) return true;

            if (null === $provides) $provides = self::trigger_provides($args);

            return in_array($starter['needs'], $provides, true);
        });
    }

    /** What the automation's trigger provides, when it comes from a running shop. */
    protected static function trigger_provides($args)
    {
        $automation = absint($args['automation_id'] ?? 0) ? \MailOptin\Core\Repositories\AutomationsRepository::get(absint($args['automation_id'])) : null;
        $trigger    = $automation ? \MailOptin\Core\Automations\Triggers::get_instance()->get($automation['trigger_id'] ?? '') : null;

        if ( ! $trigger || ! \MailOptin\Core\Automations\Stores\Stores::for_trigger($trigger)) return [];

        return (array)$trigger->provides();
    }

    /**
     * A starter's blocks for an email, each with its block's defaults for that email, and only blocks
     * the email can have (Cart contents only in a shop automation's email).
     *
     * @return array blocks as saved
     */
    public function content($id, $email_campaign_id)
    {
        $starter = $this->get($id);
        if ( ! $starter) return [];

        return $this->with_defaults((array)call_user_func($starter['content'], $email_campaign_id), $email_campaign_id);
    }

    /** Blocks with the email's defaults; blocks the email can't have are left out. */
    protected function with_defaults($elements, $email_campaign_id)
    {
        $out = [];

        foreach ($elements as $element) {
            $block = is_array($element) ? Blocks::get_instance()->get($element['type'] ?? '') : null;
            if ( ! $block) continue;

            if ( ! $block->available_for($email_campaign_id)) continue;

            $element['settings'] = array_replace($block->defaults($email_campaign_id), (array)($element['settings'] ?? []));

            if ('columns' === $element['type']) {
                $element['columns'] = array_map(function ($children) use ($email_campaign_id) {
                    return $this->with_defaults((array)$children, $email_campaign_id);
                }, (array)($element['columns'] ?? []));
            }

            $out[] = $element;
        }

        return $out;
    }

    protected function load()
    {
        if ($this->loaded) return;

        $this->loaded = true;
        $early        = $this->starters;

        foreach (self::builtin() as $starter) $this->register($starter);

        // Registered before this ran: kept, built-ins don't replace them.
        $this->starters = $early + $this->starters;

        do_action('mailoptin_email_starters', $this);
    }

    /* ---------------------------------------------------------------------------------------------
     * Blocks, for starter content
     * ------------------------------------------------------------------------------------------ */

    /** @var string[] text to replace, as written by prompt() */
    protected static $prompts = [];

    /**
     * Text for the person to replace, "[Your headline]": the editor's checks flag it while it's left.
     * Escaped for HTML.
     */
    public static function prompt($text)
    {
        $prompt                   = '[' . (string)$text . ']';
        self::$prompts[$prompt] = true;

        return esc_html($prompt);
    }

    /**
     * Every starter's prompts (each starter's content made once to collect them).
     *
     * @return string[]
     */
    public function prompts()
    {
        static $done = false;

        if ( ! $done) {
            $done = true;
            $this->load();
            foreach ($this->starters as $starter) {
                try {
                    call_user_func($starter['content'], 0);
                } catch (\Throwable $e) {
                }
            }
        }

        return array_keys(self::$prompts);
    }

    /** A prompt not yet escaped (for a setting the block escapes: a button's text, an image's alt). */
    public static function prompt_text($text)
    {
        $prompt                   = '[' . (string)$text . ']';
        self::$prompts[$prompt] = true;

        return $prompt;
    }

    protected static function id($prefix)
    {
        return $prefix . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    public static function text($html, $settings = [])
    {
        return ['id' => self::id('text'), 'type' => 'text', 'settings' => ['text_content' => $html] + $settings];
    }

    public static function button($label, $link = '', $settings = [])
    {
        return ['id' => self::id('button'), 'type' => 'button', 'settings' => ['button_text' => $label, 'button_link' => '' !== $link ? $link : home_url('/')] + $settings];
    }

    public static function image($alt, $width = '', $settings = [])
    {
        $image = ['image_url' => MAILOPTIN_ASSETS_URL . 'images/email-templates/default-feature-img.jpg', 'image_alt_text' => $alt];
        if ('' !== (string)$width) $image['image_width'] = (string)$width;

        return ['id' => self::id('image'), 'type' => 'image', 'settings' => $image + $settings];
    }

    public static function divider()
    {
        return ['id' => self::id('divider'), 'type' => 'divider', 'settings' => []];
    }

    /** The site's latest posts, chosen when the email is made (the block holds chosen posts). */
    public static function latest_posts($count, $columns)
    {
        $ids = get_posts(['numberposts' => $count, 'post_status' => 'publish', 'fields' => 'ids', 'suppress_filters' => false]);

        return ['id' => self::id('posts'), 'type' => 'posts', 'settings' => ['post_list' => array_map('strval', $ids), 'post_column_count' => (string)$columns]];
    }

    /** @param array[] $columns each column's blocks */
    public static function columns($layout, $columns)
    {
        return ['id' => self::id('columns'), 'type' => 'columns', 'settings' => ['columns_layout' => $layout], 'columns' => $columns];
    }

    protected static function p(...$lines)
    {
        return '<p>' . implode('</p><p>', $lines) . '</p>';
    }

    /* ---------------------------------------------------------------------------------------------
     * The built-in starters
     * ------------------------------------------------------------------------------------------ */

    protected static function builtin()
    {
        $hi   = esc_html__('Hi {{subscriber.first_name|there}},', 'mailoptin');
        $site = esc_html(get_bloginfo('name'));

        return [
            [
                'id'          => 'blank',
                'name'        => __('Blank', 'mailoptin'),
                'description' => __('Just the header and footer.', 'mailoptin'),
                'blocks'      => __('No blocks', 'mailoptin'),
                'contexts'    => ['broadcast', 'automation'],
                'content'     => function () { return []; },
            ],
            [
                'id'          => 'newsletter',
                'name'        => __('Newsletter', 'mailoptin'),
                'description' => __('An intro, your latest posts in two columns, and a sign-off.', 'mailoptin'),
                'blocks'      => __('Text, Posts (2 columns), Button, Text', 'mailoptin'),
                'content'     => function () use ($hi, $site) {
                    return [
                        self::text('<h2>' . self::prompt(__('Your headline for this issue', 'mailoptin')) . '</h2>' . self::p($hi, self::prompt(__('A few lines to open the issue: what\'s new and why it matters to your readers.', 'mailoptin')))),
                        self::latest_posts(4, 2),
                        self::button(__('Visit the blog', 'mailoptin')),
                        self::text(self::p(esc_html__('Thanks for reading,', 'mailoptin') . '<br>' . $site)),
                    ];
                },
            ],
            [
                'id'          => 'announcement',
                'name'        => __('Announcement', 'mailoptin'),
                'description' => __('One big image, a headline, a short message and a button.', 'mailoptin'),
                'blocks'      => __('Image, Text, Button', 'mailoptin'),
                'content'     => function () {
                    return [
                        self::image(self::prompt_text(__('Describe your image', 'mailoptin'))),
                        self::text('<h1 style="text-align:center;">' . self::prompt(__('The news in one line', 'mailoptin')) . '</h1><p style="text-align:center;">' . self::prompt(__('Two or three sentences: what\'s happening, when, and what it means for the reader.', 'mailoptin')) . '</p>'),
                        self::button(self::prompt_text(__('Button text', 'mailoptin')), '', ['button_alignment' => 'center', 'button_width' => '50']),
                    ];
                },
            ],
            [
                'id'          => 'welcome',
                'name'        => __('Welcome', 'mailoptin'),
                'description' => __('Thank new subscribers, say what to expect, point to your best posts.', 'mailoptin'),
                'blocks'      => __('Text, Columns (3 points), Posts', 'mailoptin'),
                'contexts'    => ['broadcast', 'automation'],
                'content'     => function () {
                    $point = function ($title, $text) { return [self::text('<h3>' . esc_html($title) . '</h3><p>' . esc_html($text) . '</p>')]; };

                    return [
                        self::text('<h2>' . esc_html__('Welcome, {{subscriber.first_name|there}}', 'mailoptin') . '</h2>' . self::p(self::prompt(__('Thanks for joining. Say who you are and why you write, in two sentences.', 'mailoptin')))),
                        self::columns('33-33-33', [
                            $point(self::prompt_text(__('What you send', 'mailoptin')), self::prompt_text(__('e.g. one email a week with new posts', 'mailoptin'))),
                            $point(self::prompt_text(__('What they get', 'mailoptin')), self::prompt_text(__('e.g. early access to sales', 'mailoptin'))),
                            $point(self::prompt_text(__('How to reply', 'mailoptin')), self::prompt_text(__('e.g. just hit reply, I read everything', 'mailoptin'))),
                        ]),
                        self::text('<h3>' . esc_html__('Start with these', 'mailoptin') . '</h3>'),
                        self::latest_posts(3, 1),
                    ];
                },
            ],
            [
                'id'          => 'event',
                'name'        => __('Event invite', 'mailoptin'),
                'description' => __('What, when and where, an RSVP button and a short note.', 'mailoptin'),
                'blocks'      => __('Image, Text, Columns (when, where), Text, Button', 'mailoptin'),
                'content'     => function () {
                    return [
                        self::image(self::prompt_text(__('Describe your event image', 'mailoptin'))),
                        self::text('<h1>' . self::prompt(__('Event name', 'mailoptin')) . '</h1>'),
                        self::columns('50-50', [
                            [self::text('<p><strong>' . esc_html__('When', 'mailoptin') . '</strong><br>' . self::prompt(__('Saturday 6 December, 10am–2pm', 'mailoptin')) . '</p>')],
                            [self::text('<p><strong>' . esc_html__('Where', 'mailoptin') . '</strong><br>' . self::prompt(__('Address, or "Online"', 'mailoptin')) . '</p>')],
                        ]),
                        self::text(self::p(self::prompt(__('What to expect, who it\'s for, anything to bring.', 'mailoptin')))),
                        self::button(self::prompt_text(__('RSVP', 'mailoptin'))),
                    ];
                },
            ],
            [
                'id'          => 'note',
                'name'        => __('Personal note', 'mailoptin'),
                'description' => __('Plain text from a person, a single link. No images.', 'mailoptin'),
                'blocks'      => __('Text', 'mailoptin'),
                'content'     => function () use ($hi) {
                    return [
                        self::text(self::p($hi, self::prompt(__('Write as you would to one reader. A short note works best: one idea, one link.', 'mailoptin')), '<a href="' . esc_url(home_url('/')) . '">' . self::prompt(__('The one link', 'mailoptin')) . ' &rarr;</a>', esc_html__('[Sign-off],', 'mailoptin') . '<br>' . self::prompt(__('Your name', 'mailoptin')))),
                    ];
                },
            ],
            [
                'id'          => 'thank-you',
                'needs'       => 'order',
                'name'        => __('Thank you for your order', 'mailoptin'),
                'description' => __('Thanks, what they bought, and what happens next.', 'mailoptin'),
                'blocks'      => __('Text, Products bought, Text', 'mailoptin'),
                'contexts'    => ['automation'],
                'content'     => function () use ($hi) {
                    return [
                        self::text(self::p($hi, self::prompt(__('Thank you for your order. Say what happens next: when it ships, how to reach you.', 'mailoptin')))),
                        ['id' => self::id('products'), 'type' => 'products_bought', 'settings' => []],
                        self::text(self::p(self::prompt(__('A tip for using what they bought, or a link to your guide.', 'mailoptin')))),
                    ];
                },
            ],
            [
                'id'          => 'abandoned-cart',
                'needs'       => 'cart',
                'name'        => __('Abandoned cart', 'mailoptin'),
                'description' => __('A reminder of what\'s in the cart, with a button back to it.', 'mailoptin'),
                'blocks'      => __('Text, Cart contents, Discount code', 'mailoptin'),
                'contexts'    => ['automation'],
                'content'     => function () use ($hi) {
                    return [
                        self::text(self::p($hi, self::prompt(__('You left something in your cart. It\'s saved for you below.', 'mailoptin')))),
                        ['id' => self::id('cart'), 'type' => 'cart_contents', 'settings' => []],
                        ['id' => self::id('discount'), 'type' => 'discount_code', 'settings' => []],
                    ];
                },
            ],
            [
                'id'          => 'we-miss-you',
                'name'        => __('We miss you', 'mailoptin'),
                'description' => __('A short, personal note to someone who hasn\'t opened in a while.', 'mailoptin'),
                'blocks'      => __('Text, Posts', 'mailoptin'),
                'contexts'    => ['automation'],
                'content'     => function () use ($hi) {
                    return [
                        self::text(self::p($hi, self::prompt(__('It\'s been a while. Here\'s what you missed, in case it\'s useful.', 'mailoptin')))),
                        self::latest_posts(3, 1),
                        self::text(self::p(self::prompt(__('Not for you any more? No hard feelings: the unsubscribe link is below.', 'mailoptin')))),
                    ];
                },
            ],
        ];
    }

    /**
     * @return Starters
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
