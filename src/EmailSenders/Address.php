<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * An email address with an optional display name.
 */
class Address
{
    /** @var string */
    public $email;

    /** @var string */
    public $name;

    public function __construct($email, $name = '')
    {
        $email = trim((string)$email);

        // No whitespace or control characters in an address, so it can't break a header.
        $this->email = preg_match('/[\s\x00-\x1f\x7f]/', $email) ? '' : $email;
        $this->name  = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)$name));
    }

    /**
     * @param Address|string|array $value an Address, "email", "Name <email>" or ['email' => ..., 'name' => ...]
     */
    public static function from($value)
    {
        if ($value instanceof self) return $value;

        if (is_array($value)) return new self($value['email'] ?? '', $value['name'] ?? '');

        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', (string)$value, $m)) {
            return new self($m[2], trim($m[1], " \t\"'"));
        }

        return new self($value);
    }

    /**
     * RFC 5322 form, "Name <email>", quoting the name when it needs it.
     */
    public function format()
    {
        if ('' === $this->name) return $this->email;

        $name = str_replace(["\r", "\n"], '', $this->name);

        if (preg_match('/[^\w \-.!#$%&\'*+\/=?^`{|}~]/u', $name)) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return $name . ' <' . $this->email . '>';
    }

    /**
     * Like format(), with a name that isn't plain ASCII written as an RFC 2047 encoded word, for APIs
     * and MIME headers that require it (Amazon SES).
     */
    public function format_encoded()
    {
        if ('' === $this->name || ! preg_match('/[^\x20-\x7e]/', $this->name)) return $this->format();

        return '=?UTF-8?B?' . base64_encode($this->name) . '?= <' . $this->email . '>';
    }
}
