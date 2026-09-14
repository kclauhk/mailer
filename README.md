# Wrapper for Symfony's Mailer Component

Getting Started
---------------

```bash
composer require kclauhk/mailer
```

```php
use MailerWrapper\Mailer;

$mailer = new Mailer('smtp://user:pass@smtp.example.com:port');
$mailer->send([
    'from' => 'from@example.com',
    'to' => 'to@example.com',
    //'cc' => ['cc@example.com', '"cc, 2" <cc2@example.com>'],  // array
    //'bcc' => '"bcc@example.com","Cc, B <b_cc@example.com>"',  // CSV string
    //'replyTo' => 'replyto@example.com,no-reply@example.com',  // CSV string
    //'priority' => Mailer::PRIORITY_HIGH,
    'subject' => 'Example',
    'text' => "Heading\n\nParagraph",
    'html' => '<h1>Heading</h1><img src="cid:img1_id"><p>Paragraph</p><img src="cid:img2_id">',
    // this wrapper will automatically generate plain text version if only html is given
/*
    'attach' => [
        '/path/attachment1',
        [
            'file' => '/path/to/attachment2',
            //'name' => 'filename (optional)',
            //'type' => 'content type (optional)',
            //'encoding' => 'encoding (optional)'
        ],
        [
            'data' => $content_or_resource_of_attachment3,
            'name' => 'filename of attachment3',
            //'type' => 'content type (optional)',
            //'encoding' => 'encoding (optional)'
        ],
    ],
    // embedding images for HTML contents
    'embed' => [
        [
            'file' => '/path/to/embeded_image1',
            'name' => 'img1_id',
            //'type' => 'content type (optional)'
        ],
        [
            'data' => $content_or_resource_of_image2,
            'name' => 'img2_id',
            //'type' => 'content type (optional)'
        ],
    ],
    // signing email
    'sign' => [
        'certificate' => '/path/to/certificate.crt',
        'privateKey' => '/path/to/private.key',
        //'passphrase' => 'the passphrase',
        //'intermediate' => '/path/to/intermediate.crt',
        //'flags' => \PKCS7_DETACHED
            // 'flags' (optional), see https://secure.php.net/manual/en/openssl.pkcs7.flags.php
    ],
    // encrypting email
    'encrypt' => [
        'certificate' => $certificate,
        //'cipher' => \OPENSSL_CIPHER_AES_256_CBC
            // 'certificate' can be a string ('/path/to/certificate.crt') or array, see
            //   https://symfony.com/doc/current/mailer.html#encrypting-messages
            // 'cipher' (optional) must be one of these PHP constants: https://php.net/openssl.ciphers
    ],
    // SMTP envelope
    'envelope' => [
        'from' => 'sender@example.com',
        'to' => 'rcpt1@example.com,rcpt1@example.com'
    ],
*/
]);
```
