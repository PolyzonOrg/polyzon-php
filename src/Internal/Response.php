<?php

declare(strict_types=1);

namespace Polyzon\Internal;

/**
 * One HTTP answer as libcurl delivers it: status line, headers and the body up to the cap.
 *
 * @internal
 */
final class Response
{
    /** HTTP status of the final response (0 until the status line arrives). */
    public int $status = 0;

    /**
     * Headers of the final response, lower-case names; repeated headers are joined with `, `.
     *
     * @var array<string, string>
     */
    public array $headers = [];

    /** The body read so far. */
    public string $body = '';

    /** The body exceeded {@see Transport::MAX_BODY_BYTES}: the transfer was aborted, the body dropped. */
    public bool $tooLarge = false;

    /** CURLOPT_HEADERFUNCTION: one header line per call. Returning a short count aborts. */
    public function onHeader(\CurlHandle $handle, string $line): int
    {
        if (preg_match('~^HTTP/[\d.]+\s+(\d{3})~', $line, $match) === 1) {
            // A new response begins (after a CONNECT reply or an interim 1xx): start over.
            $this->status = (int) $match[1];
            $this->headers = [];
        } elseif (($colon = strpos($line, ':')) !== false) {
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;
            if ($name === 'content-length' && (float) $value > Transport::MAX_BODY_BYTES) {
                $this->tooLarge = true;

                return 0;
            }
        }

        return \strlen($line);
    }

    /** CURLOPT_WRITEFUNCTION: appends a body chunk, or aborts the transfer past the cap. */
    public function onBody(\CurlHandle $handle, string $chunk): int
    {
        if (\strlen($this->body) + \strlen($chunk) > Transport::MAX_BODY_BYTES) {
            $this->tooLarge = true;
            $this->body = '';

            return 0;
        }
        $this->body .= $chunk;

        return \strlen($chunk);
    }
}
