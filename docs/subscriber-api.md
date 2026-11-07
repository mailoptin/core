# MailOptin Subscriber API

Add, update and read built-in list subscribers from your shop, CRM, Zapier, Make or your own code.

- Base URL: `https://example.com/wp-json/mailoptin/v1`
- Requests and responses are JSON. Times are UTC, ISO 8601 (`2026-11-07T08:00:00Z`).
- The API follows the same rules as the admin screens:
  - Someone who unsubscribed, bounced or complained can't be added to lists again (`409`). That includes a list they unsubscribed from while staying on others. Only they can sign up again, through a form.
  - Without consent, a subscriber added to a list is asked to confirm by email (double opt-in).

## Authentication

Create a key under **MailOptin › Settings › API**. Each tool should have its own key, with either:

- **Read only**: the `GET` endpoints.
- **Read and write**: everything.

Send the key in the `Authorization` header:

```
Authorization: Bearer YOUR_API_KEY
```

If a tool can't set that header (some hosts strip it), use `X-MailOptin-Key: <key>` instead.

- Keys are refused over plain HTTP, and in the URL, where they would end up in server logs.
- The full key is shown once, when it's created. MailOptin stores only a hash of it.
- A key acts as the admin who created it, and stops working if that person loses access to MailOptin.
- Each key may make 120 requests a minute. Beyond that the answer is `429` with a `Retry-After` header. Without a persistent object cache the count is approximate under many simultaneous requests.

WordPress Application Passwords also work (Basic auth), for users who can manage MailOptin.

## Errors

Errors use WordPress's REST format:

```json
{ "code": "mo_unknown_lists", "message": "Unknown lists: VIP. Send \"create_missing\": true to create lists by name.", "data": { "status": 400, "unknown": ["VIP"] } }
```

| Status | When |
|---|---|
| 400 | Invalid input: bad email, unknown list, tag or custom field, an invalid field value, or the key sent in the URL |
| 401 | No key, a wrong key or a revoked key |
| 403 | A read-only key tried to change something, or the key was sent over plain HTTP |
| 404 | No such subscriber, list or tag |
| 409 | The address unsubscribed, bounced or complained |
| 429 | Too many requests; wait `Retry-After` seconds |

A request that fails changes nothing: everything is checked first, and lists and tags asked for with `create_missing` are only created once the request is known to be valid.

## The subscriber object

```json
{
  "id": 4182,
  "email": "maya@example.org",
  "first_name": "Maya",
  "last_name": "",
  "status": "subscribed",
  "lists": [{ "id": 1, "name": "Newsletter", "status": "subscribed" }],
  "tags": ["customer", "bulbs"],
  "fields": { "company": "Okafor Gardens", "orders": "3" },
  "consent": { "at": "2026-11-07T08:00:00Z", "method": "api", "text": "Ticked 'Email me offers' at checkout" },
  "confirmed_at": null,
  "created_at": "2026-11-07T08:00:00Z",
  "updated_at": "2026-11-07T08:00:00Z"
}
```

The subscriber's `status` is one of `subscribed`, `pending`, `unsubscribed`, `bounced` or `complained`. A list's `status` is `subscribed`, `pending` or `unsubscribed`.

## Endpoints

Wherever a list or tag is expected, you can send its ID or its name. A number is read as an ID when a list or tag with that ID exists, otherwise as a name.

### `GET /subscribers`

Lists and searches subscribers. Every parameter is optional.

| Parameter | |
|---|---|
| `list` | List ID or name |
| `tag` | Tag ID or name; repeat it (`tag[]=a&tag[]=b`) to match any of several |
| `status` | `subscribed`, `pending`, `unsubscribed`, `bounced` or `complained` |
| `search` | Email, name or any custom field value |
| `created_since`, `updated_since` | Only subscribers created or changed at or after this time. Use these to poll for changes. |
| `page`, `per_page` | Paging; `per_page` is at most 100 (default 20) |
| `orderby`, `order` | `created_at` (default), `updated_at`, `id` or `email`; `asc` or `desc` (default) |

The response is an array of subscribers. The `X-WP-Total` and `X-WP-TotalPages` headers give the totals.

### `GET /subscribers/{id or email}`

Returns one subscriber.

### `POST /subscribers`

Adds a subscriber, or updates the one with this email.

```json
{
  "email": "maya@example.org",
  "first_name": "Maya",
  "last_name": "Okafor",
  "lists": ["Newsletter"],
  "tags": ["customer"],
  "fields": { "company": "Okafor Gardens" },
  "consent": { "given": true, "text": "Ticked 'Email me offers' at checkout" },
  "create_missing": false
}
```

- **Required:** `email`. A new subscriber also needs at least one entry in `lists`.
- **Consent:**
  - With `consent.given`, the person is subscribed straight away, and `consent.text` is kept as the record of what they agreed to.
  - Without it, they are pending and get a confirmation email. `"confirmation_sent": true` in the response says so. Sending the same request again doesn't send another email while they are still pending.
- **Unknown names:**
  - With `create_missing`, unknown lists and tags are created by name.
  - Custom fields are never created through the API. Create them under Subscribers › Custom fields first.
- **Response:** `201` when the subscriber was created, `200` when updated. The body is the subscriber plus `created` and `confirmation_sent`.

### `PATCH /subscribers/{id or email}`

Changes `first_name`, `last_name` and `fields`. The email can't be changed.

### `DELETE /subscribers/{id or email}`

Deletes the subscriber, with their list memberships, tags, custom field values and history. Their suppression record is kept, so a deleted unsubscriber still isn't emailed.

### `POST /subscribers/{id or email}/tags`

Adds tags: `{ "tags": ["customer", "gold"], "create_missing": true }`

### `DELETE /subscribers/{id or email}/tags/{tag}`

Removes one tag.

### `POST /subscribers/{id or email}/lists`

Adds the subscriber to lists: `{ "lists": ["Newsletter"], "consent": { "given": true, "text": "..." } }`. Consent works as for `POST /subscribers`.

### `DELETE /subscribers/{id or email}/lists/{list}`

Takes the subscriber off one list. This is not an unsubscribe: nothing is suppressed, and they can be added again.

### `POST /subscribers/{id or email}/unsubscribe`

A real unsubscribe:
- `{ "list": "Newsletter" }` unsubscribes from one list. A list that doesn't exist is a `404`, never "everything".
- `{}` unsubscribes from everything, and the address is then suppressed.

### `GET /lists`, `GET /tags`, `GET /fields`

What exists, so a tool can offer choices:

- `/lists`: `id`, `name`, `double_optin`
- `/tags`: `id`, `name`, `subscribers`
- `/fields`: `id`, `key`, `label`, `type`, `options`

## Examples

```bash
# Add a customer at checkout
curl -X POST https://example.com/wp-json/mailoptin/v1/subscribers \
  -H "Authorization: Bearer $MO_KEY" -H "Content-Type: application/json" \
  -d '{"email":"maya@example.org","lists":["Customers"],"tags":["bulbs"],"consent":{"given":true,"text":"Ticked Email me offers at checkout"}}'

# Everyone who joined or changed since the last check (how Zapier and Make poll)
curl "https://example.com/wp-json/mailoptin/v1/subscribers?updated_since=2026-11-07T08:00:00Z&per_page=100" \
  -H "Authorization: Bearer $MO_KEY"
```

## From PHP, on the same site

Other plugins can do the same without HTTP:

```php
$subscriber = mailoptin_add_subscriber([
    'email'   => 'maya@example.org',
    'lists'   => ['Customers'],
    'tags'    => ['bulbs'],
    'consent' => ['given' => true, 'text' => 'Ticked "Email me offers" at checkout'],
]);

if (is_wp_error($subscriber)) {
    // $subscriber->get_error_data()['status'] is the HTTP status the API would give, e.g. 409.
}

mailoptin_get_subscriber('maya@example.org');
mailoptin_tag_subscriber('maya@example.org', ['repeat-buyer']);   // creates missing tags
mailoptin_untag_subscriber('maya@example.org', 'bulbs');
mailoptin_unsubscribe_subscriber('maya@example.org');             // from everything
mailoptin_unsubscribe_subscriber('maya@example.org', 'Customers'); // from one list
```
