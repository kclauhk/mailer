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
     * @param array|Email   $mail       Array for message, or Email object (passthrough)
     * @param Envelope|null $envelope   Envelope object
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
        if (isset($mail['envelope'])) {
            $env = $mail['envelope'];
            if (empty($env)) {
                // clear $envelope
                $envelope = null;
            } elseif (
                ($sndr = $this->parseAddress($env['from'] ?? ''))
                && ($rcpt = $this->parseAddress($env['to'] ?? ''))
            ) {
                // overwrite $envelope
                $envelope = new Envelope($sndr[0], $rcpt);
            } else {
                throw new InvalidArgumentException(
                    'An envelope must have "from" (a sender) and "to" (at least one recipient)'
                );
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

        // attachments
        if (!empty($mail['attach'])) {
            $attachments = is_array($mail['attach']) && !array_key_exists(0, $mail['attach'])
                ? [$mail['attach']]
                : (array)$mail['attach'];
            $items = $this->normalizeItems($attachments, false);
            foreach ($items as $item) {
                if (!empty($item['encoding'])) {
                    if (empty($item['data']) && !empty($item['file'])) {
                        $item['data'] = file_get_contents($item['file']);
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
                // embed doesn't support custom encoding, $item['encoding'] will be dropped
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
    }

    /**
     * Parse strings, CSV strings, or arrays into Symfony Address objects
     */
    private function parseAddress(mixed $input): array
    {
        $array = [];
        if (is_array($input)) {
            $array = $input;
        } elseif (is_string($input)) {
            // split by semicolon
            $array = preg_split(
                '/[@:](?:[^,;\s]*)\K\s*;\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/',
                $input
            );
        }

        $addresses = [];
        foreach ($array as $elm) {
            if (!empty($elm) && is_string($elm)) {
                // strip "group-name:" prefix
                $list = preg_replace('/^[^:]+:\s*|;\s*$/', '', $elm);
                // split by comma
                $list = preg_split(
                    '/(?:@[^,\s]*)\K\s*,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/',
                    $list
                );
                $elm = array_map(
                    fn($v) => preg_match('/^"(.*)"$/', $v, $m) ? $m[1] : $v,
                    $list
                );

                foreach ($elm as $v) {
                    $trimmed = trim($v ?? '');
                    if (!empty($trimmed)) {
                        $addresses[] = Address::create($trimmed);
                    }
                }
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

        foreach ($items as $k => $item) {
            // validate the provided MIME type
            if (!empty($item['type'])) {
                $type = explode(';', $item['type']);
                if (!$ext = $mimeTypes->getExtensions($type[0])) {
                    $item['type'] = null;
                }
            }

            $part = $is_embed ? 'embed' : 'attach';

            if (!$is_embed && is_string($item)) {
                $item = ['file' => $item];
            }
            if (is_array($item)) {
                // validate item array
                if (empty($item['data']) && empty($item['file'])) {
                    throw new InvalidArgumentException(\sprintf(
                        $is_embed
                            ? 'Invalid "embed" element: item #%s must contain "data" and "name", or "file" and "name"'
                            : 'Invalid "attach" element: item #%s must contain "data" and "name", or "file"',
                        $k
                    ));
                } elseif (!empty($item['data']) && !empty($item['file'])) {
                    throw new InvalidArgumentException(\sprintf(
                        'Invalid "%s" element: item #%s must contain either "data" or "file", not both',
                        $part,
                        $k
                    ));
                }

                if ($is_embed && empty($item['name'])) {
                    throw new InvalidArgumentException(\sprintf(
                        'Invalid "embed" element: item #%s must contain "name"',
                        $k
                    ));
                }

                if (!empty($item['file'])) {
                    // attach/embed a file
                    if (!is_file($item['file']) || !is_readable($item['file'])) {
                        throw new InvalidArgumentException(\sprintf(
                            'Invalid "%s" element: file "%s" in item #%s does not exist or is not readable',
                            $part,
                            $item['file'],
                            $k
                        ));
                    }
                    $item['name'] = $item['name'] ?? null;
                    $item['type'] = ($item['type'] ?? '') ?: (
                        isset($finfo)
                            ? $finfo->file($item['file'])
                            : null
                    );
                } elseif (!empty($item['data'])) {
                    // attach/embed data
                    if (empty($item['type']) && isset($finfo)) {
                        if (is_string($item['data'])) {
                            $item['type'] = $finfo->buffer($item['data']);
                        } elseif (is_resource($item['data'])) {
                            $meta = stream_get_meta_data($item['data']);
                            if ($meta['seekable']) {
                                $item['type'] = $finfo->buffer(stream_get_contents($item['data'], 1024));
                                rewind($item['data']);
                            }
                        }
                    }
                    if (empty($item['type']) && !empty($item['name'])) {
                        $ext = pathinfo($item['name'], PATHINFO_EXTENSION);
                        $item['type'] = $mimeTypes->getMimeTypes($ext)[0] ?? null;
                    }
                    if (empty($item['type'])) {
                        throw new InvalidArgumentException(\sprintf(
                            'Invalid "%s" element: item #%s must contain "name" (filename) or "type" (content type)',
                            $part,
                            $k
                        ));
                    }
                    if (empty($item['name'])) {
                        $ext = $mimeTypes->getExtensions($item['type'] ?? '');
                        $item['name'] = uniqid() . '.' . ($ext[0] ?? 'dat');
                    }
                }

                $normalized[] = $item;
            } else {
                throw new InvalidArgumentException(\sprintf(
                    $is_embed
                        ? 'Invalid "embed" element: item #%s must contain "data" and "name", or "file" and "name"'
                        : 'Invalid "attach" element: item #%s',
                    $k
                ));
            }
        }

        return $normalized;
    }

    /**
     * Convert HTML to formatted plain text
     */
    private function htmlToText(string $html): string
    {
        return (new \Html2Text\Html2Text($html))->getText();
    }
}
