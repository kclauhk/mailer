<?php

namespace MailerWrapper;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\SMimeEncrypter;
use Symfony\Component\Mime\Crypto\SMimeSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\LogicException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\DataPart;

class Mailer
{
    public const PRIORITY_HIGHEST = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_NORMAL = 3;
    public const PRIORITY_LOW = 4;
    public const PRIORITY_LOWEST = 5;

    private SymfonyMailer $mailer;
    private ?Address $sender = null;
    private array $tempFile = [];

    public function __construct(string $dsn)
    {
        $this->mailer = new SymfonyMailer(Transport::fromDsn($dsn));
    }

    public function mailer(): SymfonyMailer
    {
        return $this->mailer;
    }

    /**
     * Set default "From" address
     */
    public function sender(string $address = ''): ?string
    {
        if ($addrs = $this->parseAddress($address)) {
            $this->sender = $addrs[0];
        }
        return $this->sender ? $this->sender->getAddress() : null;
    }

    /**
     * @param array|Email   $mail   Array for message, or Email object (passthrough skips sign/encrypt)
     */
    public function send(array|Email $mail, ?Envelope $envelope = null): void
    {
        if ($mail instanceof Email) {
            $this->mailer->send($mail, $envelope);
            return;
        }

        if (empty($mail['from']) && empty($this->sender)) {
            throw new LogicException('An email must have a "From" or "Sender" address');
        } else {
            $from = $this->parseAddress($mail['from'] ?? '');
        }

        $email = (new Email())->from($from[0] ?? $this->sender);

        // address fields
        foreach (['to', 'cc', 'bcc', 'replyTo'] as $field) {
            if (
                !empty($mail[$field])
                && $addrs = $this->parseAddress($mail[$field])
            ) {
                $email->$field(...$addrs);
            }
        }

        // envelope
        if ($env = $mail['envelope'] ?? null) {
            if (
                ($from = $this->parseAddress($env['from'] ?? ''))
                && ($rcpt = $this->parseAddress($env['to'] ?? ''))
            ) {
                $envelope = new Envelope($from[0], $rcpt);
            }
        }

        // priority
        if ($priority = $mail['priority'] ?? null) {
            if (is_numeric($priority) && ($priority = (int)$priority) >= 1 && $priority <= 5) {
                $email->priority($priority);
            }
        }

        // contents
        foreach (['subject', 'text', 'html'] as $field) {
            if (!empty($mail[$field])) {
                $email->$field($mail[$field]);
            }
        }
        if (
            !isset($mail['text'])
            && !empty($mail['html'])
            && class_exists(\Html2Text\Html2Text::class)
        ) {
            $email->text($this->htmlToText($mail['html']));
        }

        try {
            // attachments
            if (!empty($mail['attach'])) {
                $attachments = is_array($mail['attach']) && !array_key_exists(0, $mail['attach'])
                    ? [$mail['attach']]
                    : (array)$mail['attach'];
                $items = $this->normalizeItems($attachments, false);
                foreach ($items as $item) {
                    if (!empty($item['encoding'])) {
                        if (empty($item['data']) && !empty($item['file'])) {
                            $item['data'] = fopen($item['file'], 'r');
                        }
                        $email->addPart(new DataPart(
                            $item['data'],
                            $item['name'],
                            $item['type'],
                            $item['encoding']
                        ));
                    } elseif (!empty($item['data'])) {
                        $email->attach(
                            $item['data'],
                            $item['name'],
                            $item['type']
                        );
                    } elseif (!empty($item['file'])) {
                        $email->attachFromPath(
                            $item['file'],
                            $item['name'],
                            $item['type']
                        );
                    }
                }
            }

            // embedded images
            if (!empty($mail['embed'])) {
                $embeds = is_array($mail['embed']) && !array_key_exists(0, $mail['embed'])
                    ? [$mail['embed']]
                    : (array)$mail['embed'];
                $items = $this->normalizeItems($embeds, true);
                foreach ($items as $item) {
                    if (!empty($item['data'])) {
                        $email->embed(
                            $item['data'],
                            $item['name'],
                            $item['type']
                        );
                    } elseif (!empty($item['file'])) {
                        $email->embedFromPath(
                            $item['file'],
                            $item['name'],
                            $item['type']
                        );
                    }
                }
            }

            // S/MIME sign
            if ($sign = $mail['sign'] ?? null) {
                if (empty($sign['certificate']) || empty($sign['privateKey'])) {
                    throw new InvalidArgumentException(
                        'Invalid "sign" element: must contain "certificate" and "privateKey"'
                    );
                }
                $signer = new SMimeSigner(
                    $sign['certificate'],
                    $sign['privateKey'],
                    $sign['passphrase'] ?? null,
                    $sign['intermediate'] ?? null,
                    $sign['flags'] ?? null
                );
                $email = $signer->sign($email);
            }
            // encrypt
            if ($encrypt = $mail['encrypt'] ?? null) {
                if (empty($encrypt['certificate'])) {
                    throw new InvalidArgumentException(
                        'Invalid "encrypt" element: must contain "certificate"'
                    );
                }
                $encrypter = new SMimeEncrypter(
                    $encrypt['certificate'],
                    $encrypt['cipher'] ?? null
                );
                $email = $encrypter->encrypt($email);
            }

            // send
            $this->mailer->send($email, $envelope);
        } finally {
            foreach ($this->tempFile as $tmp) {
                @unlink($tmp);
            }
            $this->tempFile = [];
        }
    }

    /**
     * Parse strings, CSV strings, or arrays cleanly into Symfony Address objects
     */
    private function parseAddress(mixed $input): array
    {
        if (!is_array($input)) {
            $split = preg_split(
                '/(?:@[^,\s]*)\K\s*,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/',
                $input
            );
            $input = array_map(
                fn($item) => preg_match('/^"(.*)"$/', $item, $m) ? $m[1] : $item,
                $split
            );
        }
        $addresses = [];

        foreach ($input as $v) {
            $trimmed = trim($v ?? '');
            if (!empty($trimmed)) {
                $addresses[] = Address::create($trimmed);
            }
        }

        return $addresses;
    }

    /**
     * Normalize attach/embed items into a consistent structure
     *
     * @param bool  $is_embed   true for embed, false for attach
     */
    private function normalizeItems(mixed $input, bool $is_embed): array
    {
        $items = is_array($input) && !array_key_exists(0, $input) ? [$input] : (array)$input;
        $normalized = [];

        $mimeTypes = new MimeTypes();
        if (extension_loaded('fileinfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
        }

        foreach ($items as $item) {
            // validate the provided MIME type
            if (!empty($item['type'])) {
                if (!$ext = $mimeTypes->getExtensions($item['type'])) {
                    $item['type'] = null;
                }
            }

            if (!$is_embed && is_string($item)) {
                $item = ['file' => $item];
            }
            if (is_array($item)) {
                // validate item array
                if (empty($item['data']) && empty($item['file'])) {
                    throw new InvalidArgumentException(
                        $is_embed
                            ? 'Invalid "embed" element: must contain "data" and "name", or "file" and "name"'
                            : 'Invalid "attach" element: must contain "data" and "name", or "file"'
                    );
                }
                if ($is_embed && empty($item['name'])) {
                    throw new InvalidArgumentException(
                        'Invalid "embed" element: must contain "name"'
                    );
                }

                // attach/embed a file
                if (!empty($item['file'])) {
                    if (!is_file($item['file'])) {
                        throw new InvalidArgumentException(\sprintf(
                            'The "%s" file does not exist or is not readable',
                            $item['file']
                        ));
                    }
                    $item['name'] = ($item['name'] ?? '') ?: basename($item['file']);
                    $item['type'] = ($item['type'] ?? '') ?: (
                        isset($finfo)
                            ? $finfo->file($item['file'])
                            : null
                    );
                }

                // attach/embed data
                if (!empty($item['data'])) {
                    if (is_string($item['data'])) {
                        // store large string data to a temp file
                        if (strlen($item['data']) > 5 * 1048576) {
                            $item = $this->dataToFile(
                                $item['data'],
                                $item['name'] ?? '',
                                $item['type'] ?? '',
                                $item['encoding'] ?? ''
                            );
                        } else {
                            $item['type'] = ($item['type'] ?? '') ?: (
                                isset($finfo)
                                    ? $finfo->buffer($item['data'])
                                    : null
                            );
                        }
                    } elseif (is_resource($item['data'])) {
                        if (empty($item['type'])) {
                            $meta = stream_get_meta_data($item['data']);
                            if ($meta['seekable'] && isset($finfo)) {
                                $item['type'] = $finfo->buffer(stream_get_contents($item['data'], 1024));
                                rewind($item['data']);
                            } else {
                                $item = $this->dataToFile(
                                    $item['data'],
                                    $item['name'] ?? '',
                                    $item['type'] ?? '',
                                    $item['encoding'] ?? ''
                                );
                            }
                        }
                    }
                    if (empty($item['name'])) {
                        $ext = $mimeTypes->getExtensions($item['type'] ?? '');
                        $item['name'] = uniqid() . '.' . ($ext[0] ?? 'dat');
                    }
                }

                $normalized[] = $item;
            } else {
                throw new InvalidArgumentException(
                    $is_embed
                        ? 'Invalid "embed" element: must contain "data" and "name", or "file" and "name"'
                        : 'Invalid "attach" element'
                );
            }
        }

        return $normalized;
    }

    /**
     * Store data (string/resource) to a temp file
     */
    private function dataToFile($data, string $name, string $type, string $encoding): array
    {
        $file = tempnam(sys_get_temp_dir(), 'php');
        $this->tempFile[] = $file;
        file_put_contents($file, $data);
        unset($data);

        if (empty($type) && extension_loaded('fileinfo')) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        }

        return [
            'file' => $file,
            'name' => $name ?: null,
            'type' => $type,
            'encoding' => $encoding ?: null
        ];
    }

    /**
     * Convert HTML to formatted plain text
     */
    public function htmlToText(string $html): string
    {
        return (new \Html2Text\Html2Text($html))->getText();
    }
}
