<?php

declare(strict_types=1);

namespace Slimfony\Core\Idempotency;

/**
 * Remembers which requests were served, by key, so that a request that is sent again (a client that did not see the answer)
 * is answered with the first answer instead of doing its work twice. The store must be shared by all the workers and nodes of
 * the service; DbalIdempotencyStore keeps it in the database.
 *
 * @api
 */
interface IdempotencyStore
{
    /**
     * Claims the key for a request, atomically: of two requests that start together with the same key, one gets Fresh.
     *
     * @param string $fingerprint identifies the request (method, path, body): the same key with another fingerprint is a mistake
     */
    public function begin(string $key, string $fingerprint): Outcome;

    /** The answer kept for a key whose begin() said Done. */
    public function stored(string $key): ?StoredResponse;

    /** Keeps the answer: from now on begin() says Done for the same request. */
    public function complete(string $key, StoredResponse $response): void;

    /** Forgets the claim (the request failed): the client may send it again and it will run. */
    public function release(string $key): void;
}
