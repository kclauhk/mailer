# Wrapper for Symfony's Mailer Component

## Installation

```bash
composer require kclauhk/mailer
```

## Quick Start

```php
use MailerWrapper\Mailer;

$mailer = new Mailer('smtp://user:pass@smtp.example.com:port');

$mailer->send([
    'from' => 'from@example.com',
    'to' => 'to@example.com',
    'subject' => 'Example',
    'text' => "Heading\n\nParagraph",
]);
```
Note: If you provide html without text, a plain text version will be automatically generated via [html2text/html2text](https://github.com/mtibben/html2text).


## API Reference

```Mailer::send(array|Email $mail, ?Envelope $envelope = null): void```

Sends an email. Accepts either an array of message data or a Symfony `Email` object:
- `Email` object - sent as-is
- array - see the full example below for all available keys

<br>

```Mailer::mailer(): SymfonyMailer```

Returns the underlying Symfony Mailer instance for advanced usage.

<br>

```Mailer::sender(string $address = ''): ?string```

Sets or gets the default From address. When set, it serves as the fallback `From` if none is provided in the mail array. Returns the current sender address or null.
```php
$mailer->sender('default@example.com');

// If 'from' is omitted, falls back to default
$mailer->send(['subject' => 'Hi', 'to' => 'user@example.com']);
```

## Full Example

```php
use MailerWrapper\Mailer;

$mailer = new Mailer('smtp://user:pass@smtp.example.com:port');

$mailer->send([
    'from' => 'from@example.com',
    'to' => 'to@example.com',
    'cc' => ['cc@example.com', '"cc, 2" <cc2@example.com>'],  // array
    'bcc' => '"bcc@example.com","Cc, B <b_cc@example.com>"',  // CSV string
    'replyTo' => 'replyto@example.com,no-reply@example.com',  // CSV string
    'priority' => Mailer::PRIORITY_HIGH,
    'subject' => 'Example',
    'text' => "Heading\n\nParagraph",
    'html' => '<h1>Heading</h1><img src="cid:img1_id"><p>Paragraph</p><img src="cid:img2_id">',
    // plain text will be auto-generated if only html is given

    // attachments
    'attach' => [
        '/path/to/attachment1',
        [
            'file' => '/path/to/attachment2',
            //'name' => 'filename (optional)',
            //'type' => 'content type (optional)',
            //'encoding' => 'encoding (optional)'
        ],
        [
            'data' => $content_or_resource_of_attachment3,
            'name' => 'filename',
            //'type' => 'content type (optional)',
            //'encoding' => 'encoding (optional)'
        ],
    ],
    // embedding images
    'embed' => [
        [
            'file' => '/path/to/embeded_image1',
            'name' => 'img1_id',  // (required) referenced by cid:img1_id in HTML content
            //'type' => 'content type (optional)'
        ],
        [
            'data' => $content_or_resource_of_image2,
            'name' => 'img2_id',  // (required) referenced by cid:img2_id in HTML content
            //'type' => 'content type (see explanation below)'
        ],
    ],
    // S/MIME signing
    'sign' => [
        'certificate' => '/path/to/certificate.crt',
        'privateKey' => '/path/to/private.key',
        //'passphrase' => 'the passphrase',
        //'intermediate' => '/path/to/intermediate.crt',
        //'flags' => \PKCS7_DETACHED
    ],
    // encryption
    'encrypt' => [
        'certificate' => $certificate,
        //'cipher' => \OPENSSL_CIPHER_AES_256_CBC
    ],
    // SMTP envelope
    'envelope' => [
        'from' => 'sender@example.com',
        'to' => 'recipient@example.com'
    ],
], $envelope);     // $envelope - Symfony Envelope object, see explanation below
```

### Email Addresses

All email address fields (from, to, etc.) accept:
- a single email address: `'user@example.com'`
- an address with name: `'Name <user@example.com>'`
- a group addresses: `'user group:user1@example.com,"User 2" <user2@example.com>;'`
- a CSV string: `'user1@example.com,"User 2" <user2@example.com>'`  
  \* *comma ( , ) or semicolon ( ; ) separated*
- an array of the above

### Priority

5 levels available: 
- Mailer::PRIORITY_HIGHEST
- Mailer::PRIORITY_HIGH
- Mailer::PRIORITY_NORMAL
- Mailer::PRIORITY_LOW
- Mailer::PRIORITY_LOWEST

### File Attachments

Attach files by providing:

|Method|Key|Description |
|:---|:---|:---|
|File path|`'attach' => '/path/to/file'`|path of a file on your file system|
|File path (detailed)|`'attach' => ['file' => '/path/to/file']`|optional `name` and `type` overrides|
|Content (string)|`'attach' => ['data' => '...']`|must provide `name` or `type`|
|Content (stream)|`'attach' => ['data' => fopen(...)]`|must provide `name` or `type`|

- `name` - filename
- `type` - content type

Custom encoding:

Use `encoding` for non-standard content transfer encodings, such as 8bit for iCalendar (.ics):  
(some calendar clients fail to process iCalendar data that are base64-encoded or that don't define a charset)
```php
[
    'data' => $ics,
    'name' => 'event.ics',
    'type' => 'text/calendar; charset=utf-8; method=REQUEST; component=VEVENT',
    'encoding' => '8bit'
],
```

### Embedded Images

Embed images referenced in HTML content via `cid:` URIs:
```php
<img src="cid:logo_id">
```

by providing:

|Method|Key|Description|
|:---|:---|:---|
|File path|`'embed' => ['file' => '/path/to/image.png', ...]`|`name` required|
|Content (string)|`'embed' => ['data' => '...', ...]`|`name` required|
|Content (stream)|`'embed' => ['data' => fopen(...), ...]`|`name` required|

*Important: The `name` for embeds is the Content-ID reference used in the HTML content. It must match the `cid:` value exactly, or the image will not be rendered:*
```php
'embed' => [
    [
        'file' => '/path/to/logo.png',
        'name' => 'logo_id',    // must match src="cid:logo_id" in HTML
        'type' => 'image/png',  // optional
    ],
]
```

If the content is provided via a non-seekable stream (such as `fopen('https://...', 'r')`), you can specify a filename with a valid extension for `name` to ensure the content type is correctly detected:
```php
'embed' => [
    [
        'data' => fopen('https://.../logo.jpg', 'r'),
        'name' => 'logo.jpg',   // must match src="cid:logo.jpg" in HTML
    ],
]
```

### S/MIME Signing & Encryption

S/MIME Signing:
```php
'sign' => [
    'certificate' => '/path/to/certificate.crt',
    'privateKey' => '/path/to/private.key',
    'passphrase' => 'the passphrase',               // if needed
    'intermediate' => '/path/to/intermediate.crt',  // if needed
    'flags' => \PKCS7_DETACHED,                     // optional
]
```
(see [PHP Manual](https://secure.php.net/manual/en/openssl.pkcs7.flags.php) for available flags)

Encryption:
```php
'encrypt' => [
    'certificate' => $certificate,              // string or array
    'cipher' => \OPENSSL_CIPHER_AES_256_CBC,    // optional
]
```
(see [Symfony docs](https://symfony.com/doc/current/mailer.html#encrypting-messages) for details on certificate configuration and [PHP Manual](https://php.net/openssl.ciphers) for available ciphers)

### SMTP Envelope

An SMTP envelope controls how mail servers route your message. It is separate from message headers.

By default, the envelope is auto-generated from the from, to, cc, and bcc fields. Override it with the `envelope` key:
```php
$mail = [
    // ...
    'envelope' => [
        'from' => 'sender@example.com',
        'to' => 'recipient@example.com',
    ],
];
```
An envelope requires a sender and at least one recipient. Partial or invalid data will throw an exception.

This wrapper also accepts a Symfony `Envelope` object as the 2nd argument of `send()`:
```php
$mailer->send($mail, new Envelope($sender, $recipients));
```
However, if a valid `envelope` key is present in the `$mail` array, it overrides the `Envelope` object entirely.

You can also pass an empty array to disable the provided envelope:
```php
$mail = [
    // ...
    'envelope' => [],
];
```

## Dependency

- [Symfony's Mailer Component](https://github.com/symfony/mailer)
- [html2text/html2text](https://github.com/mtibben/html2text)
